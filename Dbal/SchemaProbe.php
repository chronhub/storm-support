<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Interrogates the PostgreSQL catalogs AFTER the DDL ran and reports every way the installed schema
 * diverges from what a {@see SchemaCatalog} declares. An installer runs it inside the same
 * transaction as its DDL, so an install either proves its schema or rolls back untouched.
 *
 * Purely mechanical: it holds no table name, no predicate, no retention rule. What to verify comes
 * from the catalog the caller hands it, which is what lets one probe serve packages that must not
 * depend on each other.
 *
 * @infection-ignore-all every method here is a SQL body whose enforcement lives in the integration
 *                       suite against a real Postgres; pure logic must not move into this class, or
 *                       it hides from the mutation field
 *
 * @see \Storm\Tests\Integration\Ledger\SchemaConformanceProbeTest the enforcement
 */
final readonly class SchemaProbe
{
    /**
     * Report every divergence between the live schema in the connection's `current_schema()` and the
     * declared one, for the given tables.
     *
     * @param  list<string>  $tables  the subset owned by this connection; a split's read-model side owns
     *                                only some of them. Every name must be a key of the catalog's
     *                                `columns`; an unknown one is itself reported, since unverifiable
     *                                must never read as verified
     * @return list<string> human-readable problems; empty means the schema verified
     *
     * @throws Exception on a DBAL failure interrogating the catalogs
     */
    public function problems(Connection $connection, SchemaCatalog $catalog, array $tables): array
    {
        // every renderer read below, `format_type`, `pg_get_expr`, `pg_get_constraintdef` and
        // `pg_get_indexdef`, names an object bare when the session's search path finds it first and
        // qualifies it otherwise, so one column reads two ways under two paths. The probe binds the
        // schema it inspects, then renders under `pg_catalog` alone: a built-in reads bare, anything
        // else carries its schema, whatever path the caller set
        $schema = $connection->fetchOne('SELECT current_schema()');
        $path = (string) $connection->fetchOne("SELECT current_setting('search_path')");
        $connection->fetchOne("SELECT set_config('search_path', 'pg_catalog', false)");
        $inspected = false;

        try {
            $problems = $this->inspect($connection, $catalog, $tables, is_string($schema) ? $schema : null);
            $inspected = true;

            return $problems;
        } finally {
            try {
                $connection->fetchOne("SELECT set_config('search_path', :path, false)", ['path' => $path]);
            } catch (Exception $e) {
                // a failed read inside a transaction aborts it, and its rollback reverts the path;
                // only a restore that fails after a clean inspection is the caller's to see
                if ($inspected) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     *
     * @throws Exception on a DBAL failure interrogating the catalogs
     */
    private function inspect(Connection $connection, SchemaCatalog $catalog, array $tables, ?string $schema): array
    {
        $problems = [];

        // pg_catalog, not information_schema: format_type keeps the precision of timestamptz(6) and
        // names non-standard types such as xid8, where information_schema drops the precision and
        // reports USER-DEFINED. pg_attrdef, LEFT joined, since most columns carry no default at all;
        // pg_get_expr needs the owning relation's oid to resolve the column references inside it.
        $rows = $connection->fetchAllAssociative(
            /* language=PostgreSQL */
            "SELECT c.relname AS table_name, a.attname AS column_name,
                    format_type(a.atttypid, a.atttypmod) AS column_type, a.attnotnull::int AS not_null,
                    CASE WHEN coll.oid IS NULL OR (cn.nspname = 'pg_catalog' AND coll.collname = 'default') THEN NULL
                         WHEN cn.nspname = 'pg_catalog' THEN coll.collname
                         ELSE quote_ident(cn.nspname) || '.' || quote_ident(coll.collname) END AS collation_name,
                    a.attidentity AS identity,
                    pg_get_expr(ad.adbin, ad.adrelid) AS default_expr
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_collation coll ON coll.oid = a.attcollation
             LEFT JOIN pg_namespace cn ON cn.oid = coll.collnamespace
             LEFT JOIN pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
             WHERE n.nspname = :schema AND c.relname IN (:tables)
               AND c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped",
            ['schema' => $schema, 'tables' => $tables],
            ['tables' => ArrayParameterType::STRING],
        );
        $live = [];
        $liveIdentity = [];
        $liveDefault = [];
        foreach ($rows as $row) {
            // the collation joins the shape only when it is NOT the database default, so every column
            // that leaves the question to the deployment reads exactly as before. A column that PINS
            // one does so because an ordering it relies on would otherwise follow the host's libc,
            // which is a behavior difference no type comparison can see. A collation outside
            // `pg_catalog` carries its schema, both names quoted as PostgreSQL quotes them, so neither
            // a homonym of `C` nor one of `default` reads as the real one.
            $collation = $row['collation_name'] === null ? '' : ' collate '.(string) $row['collation_name'];

            $table = (string) $row['table_name'];
            $column = (string) $row['column_name'];

            $live[$table][$column] =
                (string) $row['column_type'].((bool) $row['not_null'] ? ' not null' : ' null').$collation;
            $liveIdentity[$table][$column] = (string) $row['identity'];
            $liveDefault[$table][$column] = $row['default_expr'] === null ? null : (string) $row['default_expr'];
        }

        foreach ($tables as $table) {
            if (! isset($catalog->columns[$table])) {
                $problems[] = sprintf('table %s: not in the conformance map — nothing to verify against', $table);

                continue;
            }
            if (! isset($live[$table])) {
                $problems[] = sprintf('table %s: absent', $table);

                continue;
            }
            $missing = array_diff(array_keys($catalog->columns[$table]), array_keys($live[$table]));
            if ($missing !== []) {
                $problems[] = sprintf(
                    'table %s: missing column(s) %s — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
                    $table,
                    implode(', ', $missing),
                );
            }
            foreach ($catalog->columns[$table] as $column => $needle) {
                if ($needle === null || ! isset($live[$table][$column])) {
                    continue;
                }
                if (! ColumnShape::holds($live[$table][$column], $needle)) {
                    $problems[] = sprintf(
                        "column %s on %s: shape '%s' lost '%s' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
                        $column,
                        $table,
                        $live[$table][$column],
                        $needle,
                    );
                }
            }
        }

        // Identity, checked apart from the type/nullability shape above: `format_type` and
        // `attnotnull` say nothing about `GENERATED … AS IDENTITY`, so a pre-existing `bigint not
        // null` column with the right shape but no generator verifies clean here and fails the
        // first insert that omits it, an event_store with no sequence_no supplied being the case
        // that motivated this.
        $scopedIdentities = array_intersect_key($catalog->identities, array_flip($tables));
        foreach ($scopedIdentities as $table => $expected) {
            foreach ($expected as $column => $needle) {
                if (! isset($liveIdentity[$table][$column])) {
                    continue; // absence is reported once, by the column-shape loop above
                }
                if ($liveIdentity[$table][$column] !== $needle) {
                    $problems[] = sprintf(
                        "column %s on %s: not GENERATED AS IDENTITY (attidentity '%s', wanted '%s') — a pre-existing column with the right type but no generator accepts an explicit value silently and never produces one on its own",
                        $column,
                        $table,
                        $liveIdentity[$table][$column],
                        $needle,
                    );
                }
            }
        }

        // A column's DEFAULT is likewise invisible to the type/nullability shape: a wrong or
        // missing default changes what every insert that omits the column actually stores, `xact_id`
        // silently losing `pg_current_xact_id()` being the case that motivated this. The column
        // stays the right type, NOT NULL still holds if a literal default is left, and the safe-head
        // classification that reads the column is fooled without a single failed statement anywhere.
        $scopedDefaults = array_intersect_key($catalog->defaults, array_flip($tables));
        foreach ($scopedDefaults as $table => $expected) {
            foreach ($expected as $column => $needle) {
                if (! isset($live[$table][$column])) {
                    continue; // absence is reported once, by the column-shape loop above
                }
                $actual = $liveDefault[$table][$column] ?? null;
                if ($actual === null) {
                    $problems[] = sprintf('column %s on %s: no DEFAULT — expected one', $column, $table);
                } elseif ($needle !== null && $actual !== $needle) {
                    $problems[] = sprintf(
                        "column %s on %s: DEFAULT is '%s', not '%s' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
                        $column,
                        $table,
                        $actual,
                        $needle,
                    );
                }
            }
        }

        foreach (array_intersect($catalog->partitioned, $tables) as $table) {
            $relkind = $connection->fetchOne(
                /* language=PostgreSQL */
                'SELECT c.relkind FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = :schema AND c.relname = :table',
                ['schema' => $schema, 'table' => $table],
            );
            if ($relkind !== false && $relkind !== 'p') {
                $problems[] = sprintf("table %s: not partitioned (relkind '%s') — the runtime expects a LIST-partitioned parent", $table, (string) $relkind);
            }
        }

        // ATTACHMENT, not existence: a detached child keeps its name and its columns, so the re-run
        // of `CREATE TABLE … PARTITION OF … DEFAULT` skips it and the parent still reports 'p' with
        // its named partitions in place. Nothing above notices, and the first event of an unnamed
        // category has nowhere to land
        foreach (array_intersect_key($catalog->defaultPartitions, array_flip($tables)) as $parent => $child) {
            $bound = $connection->fetchOne(
                /* language=PostgreSQL */
                'SELECT pg_get_expr(c.relpartbound, c.oid)
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 JOIN pg_inherits i ON i.inhrelid = c.oid
                 JOIN pg_class p ON p.oid = i.inhparent
                 JOIN pg_namespace pn ON pn.oid = p.relnamespace
                 WHERE n.nspname = :schema AND c.relname = :child AND pn.nspname = :schema AND p.relname = :parent',
                ['schema' => $schema, 'child' => $child, 'parent' => $parent],
            );

            if ($bound === false) {
                $problems[] = sprintf(
                    'partition %s: not attached to %s — a detached child of the right name and shape is what the DDL skips; an event of an unnamed category has nowhere to land',
                    $child,
                    $parent,
                );
            } elseif ((string) $bound !== 'DEFAULT') {
                $problems[] = sprintf(
                    "partition %s on %s: bound is '%s', not DEFAULT — the catch-all of the category routing is a bounded partition instead",
                    $child,
                    $parent,
                    (string) $bound,
                );
            }
        }

        $scoped = array_intersect_key($catalog->constraints, array_flip($tables));
        if ($scoped !== []) {
            $names = array_merge(...array_map(array_keys(...), array_values($scoped)));
            $defs = [];
            // pg_get_constraintdef, not bare existence: a pre-existing CHECK under the declared name
            // survives the DDL, since ADD CONSTRAINT converges on duplicate_object exactly as
            // CREATE … IF NOT EXISTS does, so the name alone would bless a homonym that lost a
            // value the runtime writes. Keyed by TABLE AND name: constraint names are unique per
            // table, never per schema, so a name-only key would let a same-named constraint on an
            // UNRELATED table verify the declared one, a verifier saying "verified" unchecked; the
            // per-table key also drops the partition children's copies naturally, the declared
            // parent carrying its own row.
            foreach ($connection->fetchAllAssociative(
                /* language=PostgreSQL */
                'SELECT c.relname, con.conname, con.convalidated, pg_get_constraintdef(con.oid) AS definition
                 FROM pg_constraint con
                 JOIN pg_class c ON c.oid = con.conrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = :schema AND con.conname IN (:names)',
                ['schema' => $schema, 'names' => $names],
                ['names' => ArrayParameterType::STRING],
            ) as $row) {
                $defs[(string) $row['relname']][(string) $row['conname']] = [
                    'definition' => (string) $row['definition'],
                    'validated' => (bool) $row['convalidated'],
                ];
            }
            foreach ($scoped as $table => $expected) {
                foreach ($expected as $name => $needle) {
                    if (! isset($defs[$table][$name])) {
                        $problems[] = sprintf('constraint %s on %s: absent', $name, $table);
                    } elseif (! $defs[$table][$name]['validated']) {
                        // NOT VALID carries the full definition, so the needle below matches and the
                        // predicate still guards every future write; what it does NOT do is answer for
                        // the rows already there, which is exactly what a conformance verdict claims
                        $problems[] = sprintf(
                            'constraint %s on %s: NOT VALID — it guards new rows only, and the existing ones were never checked against it',
                            $name,
                            $table,
                        );
                    } elseif ($needle !== null) {
                        $divergence = ConstraintShape::divergence($table, $name, $defs[$table][$name]['definition'], $needle);
                        if ($divergence !== null) {
                            $problems[] = $divergence;
                        }
                    }
                }
            }
        }

        $indexes = array_intersect_key($catalog->indexes, array_flip($tables));
        if ($indexes !== []) {
            $names = array_merge(...array_map(array_keys(...), array_values($indexes)));
            $defs = [];
            // Schema-unique names identify indexes, but their owning table must also match.
            // pg_index rather than the pg_indexes view: the view renders a definition and nothing
            // else, so an index left INVALID by an interrupted concurrent build reads as complete
            foreach ($connection->fetchAllAssociative(
                /* language=PostgreSQL */
                'SELECT c.relname AS indexname, t.relname AS tablename, pg_get_indexdef(i.indexrelid) AS indexdef,
                        (i.indisvalid AND i.indisready AND i.indislive) AS usable
                 FROM pg_index i
                 JOIN pg_class c ON c.oid = i.indexrelid
                 JOIN pg_class t ON t.oid = i.indrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = :schema AND c.relname IN (:names)',
                ['schema' => $schema, 'names' => $names],
                ['names' => ArrayParameterType::STRING],
            ) as $row) {
                $defs[(string) $row['indexname']] = [
                    'table' => (string) $row['tablename'],
                    'definition' => (string) $row['indexdef'],
                    'usable' => (bool) $row['usable'],
                ];
            }
            foreach ($indexes as $table => $expected) {
                foreach ($expected as $name => $needle) {
                    if (! isset($defs[$name])) {
                        $problems[] = sprintf('index %s on %s: absent', $name, $table);
                    } elseif ($defs[$name]['table'] !== $table) {
                        $problems[] = sprintf('index %s on %s: belongs to %s', $name, $table, $defs[$name]['table']);
                    } elseif (! $defs[$name]['usable']) {
                        // an interrupted CREATE INDEX CONCURRENTLY leaves the definition intact and the
                        // index unusable: the planner ignores it and a unique one enforces nothing,
                        // while the write path still pays to maintain it
                        $problems[] = sprintf(
                            'index %s on %s: INVALID — an interrupted concurrent build leaves its definition whole; the planner ignores it and a unique one enforces nothing',
                            $name,
                            $table,
                        );
                    } elseif ($needle !== null && ! str_contains($defs[$name]['definition'], $needle)) {
                        $problems[] = sprintf(
                            "index %s on %s: definition lost '%s' — a pre-existing index with this name is incompatible; the installer creates, it does not migrate",
                            $name,
                            $table,
                            $needle,
                        );
                    }
                }
            }
        }

        return $problems;
    }
}
