<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

use function array_diff;
use function array_flip;
use function array_intersect;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_values;
use function implode;
use function sort;
use function sprintf;
use function str_contains;

/**
 * The verdict half of the schema verification, kept apart from the probe's SQL.
 *
 * It sets the rows `SchemaProbe` read from the PostgreSQL catalogs against what a `SchemaCatalog`
 * declares, and renders the problems an installer reports. It takes the rows under the aliases of the
 * probe's queries and casts them itself, which keeps the probe a list of statements.
 *
 * Being pure, every comparison stands in the mutation field, beyond the annotation that exempts the
 * probe's SQL bodies. Quoting that annotation anywhere in this file would exempt the class as well,
 * since the mutation tool matches it in any comment.
 *
 * The problems come in a fixed order:
 *
 * - Per requested table: unverifiable, absent, missing columns, undeclared columns, then each shape
 * - Identities, then defaults
 * - Partitioning, then the attachment of each declared DEFAULT partition
 * - Declared constraints, then undeclared ones
 * - Declared indexes, then undeclared unique ones
 */
final class SchemaDiff
{
    /**
     * Every divergence between the live rows and the catalog, for the requested tables.
     *
     * Each live argument holds what the probe read for the catalog's scope; one left empty reads as
     * nothing found.
     *
     * @param  list<string>  $tables  a name the catalog's `columns` does not map is itself reported
     * @param  list<array<string, mixed>>  $columns  one row per live column of `$tables`: `table_name`,
     *                                               `column_name`, `column_type`, `not_null`,
     *                                               `collation_name`, null under the database default,
     *                                               `identity`, and `default_expr`, null without one
     * @param  array<string, mixed>  $relkinds  per partitioned table in scope, its live `relkind`, or
     *                                          `false` when no relation bears the name
     * @param  array<string, mixed>  $defaultPartitionBounds  per parent in scope, the bound of its declared
     *                                                        DEFAULT partition, or `false` when that child
     *                                                        is not attached to it; a missing key reads as
     *                                                        `false`
     * @param  list<array<string, mixed>>  $constraints  `relname`, `conname`, `convalidated` and
     *                                                   `definition` of each live constraint bearing a
     *                                                   declared name, on any table
     * @param  list<array<string, mixed>>  $localConstraints  `relname` and `conname` of each local
     *                                                        constraint of a mapped table, by name
     * @param  list<array<string, mixed>>  $indexes  `indexname`, `tablename`, `indexdef` and `usable` of
     *                                               each live index bearing a declared name
     * @param  list<array<string, mixed>>  $uniqueIndexes  `tablename` and `indexname` of each unique index
     *                                                     of a mapped table that no constraint carries, by
     *                                                     name
     * @return list<string> human-readable problems; empty means the schema verified
     */
    public static function problems(
        SchemaCatalog $catalog,
        array $tables,
        array $columns,
        array $relkinds = [],
        array $defaultPartitionBounds = [],
        array $constraints = [],
        array $localConstraints = [],
        array $indexes = [],
        array $uniqueIndexes = [],
    ): array {
        $live = [];
        $liveIdentity = [];
        $liveDefault = [];
        foreach ($columns as $row) {
            // a collation joins the shape only when it is not the database default: a column pins one so an
            // ordering does not follow the host's libc, which no type comparison sees; one outside
            // `pg_catalog` carries its quoted schema, so no homonym of `C` or `default` passes for the real
            // one
            $collation = $row['collation_name'] === null ? '' : ' collate '.(string) $row['collation_name'];

            $table = (string) $row['table_name'];
            $column = (string) $row['column_name'];

            $live[$table][$column] =
                (string) $row['column_type'].((bool) $row['not_null'] ? ' not null' : ' null').$collation;
            $liveIdentity[$table][$column] = (string) $row['identity'];
            $liveDefault[$table][$column] = $row['default_expr'] === null ? null : (string) $row['default_expr'];
        }

        $known = array_values(array_intersect($tables, array_keys($catalog->columns)));

        return [
            ...self::columnProblems($catalog, $tables, $live),
            ...self::identityProblems($catalog, $tables, $liveIdentity),
            ...self::defaultProblems($catalog, $tables, $live, $liveDefault),
            ...self::partitionProblems($catalog, $tables, $relkinds, $defaultPartitionBounds),
            ...self::constraintProblems($catalog, $tables, $known, $constraints, $localConstraints),
            ...self::indexProblems($catalog, $tables, $known, $indexes, $uniqueIndexes),
        ];
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, string>>  $live
     * @return list<string>
     */
    private static function columnProblems(SchemaCatalog $catalog, array $tables, array $live): array
    {
        $problems = [];

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
            // the other direction: a live column no catalog names is verified by nobody, from a DDL ahead of
            // its catalog or an alteration outside the installer; sorted, since the probe's read fixes no
            // order
            $undeclared = array_values(array_diff(array_keys($live[$table]), array_keys($catalog->columns[$table])));
            sort($undeclared);
            if ($undeclared !== []) {
                $problems[] = sprintf(
                    'table %s: undeclared column(s) %s — a live column no catalog names is verified by nothing; declare it in the catalog of the module that owns the table',
                    $table,
                    implode(', ', $undeclared),
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

        return $problems;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, string>>  $liveIdentity
     * @return list<string>
     */
    private static function identityProblems(SchemaCatalog $catalog, array $tables, array $liveIdentity): array
    {
        $problems = [];

        // identity apart from the shape: `format_type` and `attnotnull` ignore `GENERATED … AS IDENTITY`,
        // so a `bigint not null` column with no generator passes there, then fails the first insert omitting
        // it, as an event_store insert without sequence_no would
        foreach (array_intersect_key($catalog->identities, array_flip($tables)) as $table => $expected) {
            foreach ($expected as $column => $needle) {
                if (! isset($liveIdentity[$table][$column])) {
                    continue; // absence is reported once, by the column check
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

        return $problems;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, string>>  $live
     * @param  array<string, array<string, string|null>>  $liveDefault
     * @return list<string>
     */
    private static function defaultProblems(SchemaCatalog $catalog, array $tables, array $live, array $liveDefault): array
    {
        $problems = [];

        // a default is as invisible to the shape: a wrong or missing one changes what every insert omitting
        // the column stores, as `xact_id` losing `pg_current_xact_id()` would, while the type stays right,
        // `NOT NULL` holds and the safe-head classification reading it errs without a single failed statement
        foreach (array_intersect_key($catalog->defaults, array_flip($tables)) as $table => $expected) {
            foreach ($expected as $column => $needle) {
                if (! isset($live[$table][$column])) {
                    continue; // absence is reported once, by the column check
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

        return $problems;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, mixed>  $relkinds
     * @param  array<string, mixed>  $defaultPartitionBounds
     * @return list<string>
     */
    private static function partitionProblems(SchemaCatalog $catalog, array $tables, array $relkinds, array $defaultPartitionBounds): array
    {
        $problems = [];

        foreach (array_intersect($catalog->partitioned, $tables) as $table) {
            $relkind = $relkinds[$table] ?? false;
            if ($relkind !== false && $relkind !== 'p') {
                $problems[] = sprintf("table %s: not partitioned (relkind '%s') — the runtime expects a LIST-partitioned parent", $table, (string) $relkind);
            }
        }

        foreach (array_intersect_key($catalog->defaultPartitions, array_flip($tables)) as $parent => $child) {
            // only a missing row means detached: a bound read as null still renders as one
            $bound = array_key_exists($parent, $defaultPartitionBounds) ? $defaultPartitionBounds[$parent] : false;

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

        return $problems;
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $known
     * @param  list<array<string, mixed>>  $constraints
     * @param  list<array<string, mixed>>  $localConstraints
     * @return list<string>
     */
    private static function constraintProblems(
        SchemaCatalog $catalog,
        array $tables,
        array $known,
        array $constraints,
        array $localConstraints,
    ): array {
        $problems = [];

        // keyed by table and name, since constraint names are unique per table, never per schema: a
        // same-named constraint on an unrelated table cannot verify the declared one, and the partition
        // children's copies drop out, the declared parent carrying its own row
        $defs = [];
        foreach ($constraints as $row) {
            $defs[(string) $row['relname']][(string) $row['conname']] = [
                'definition' => (string) $row['definition'],
                'validated' => (bool) $row['convalidated'],
            ];
        }
        foreach (array_intersect_key($catalog->constraints, array_flip($tables)) as $table => $expected) {
            foreach ($expected as $name => $needle) {
                if (! isset($defs[$table][$name])) {
                    $problems[] = sprintf('constraint %s on %s: absent', $name, $table);
                } elseif (! $defs[$table][$name]['validated']) {
                    // `NOT VALID` keeps the full definition, so the needle matches and new writes stay
                    // guarded; it does not answer for the rows already there, which is what a conformance
                    // verdict claims
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

        // the other direction, as for columns: an undeclared live constraint refuses writes the runtime deems
        // legitimate, failing in class 23 far from its cause; keyed by existence, since a bare name maps to
        // null
        $undeclared = [];
        foreach ($localConstraints as $row) {
            $table = (string) $row['relname'];
            if (! array_key_exists((string) $row['conname'], $catalog->constraints[$table] ?? [])) {
                $undeclared[$table][] = (string) $row['conname'];
            }
        }
        foreach ($known as $table) {
            if (isset($undeclared[$table])) {
                $problems[] = sprintf(
                    'table %s: undeclared constraint(s) %s — a live constraint no catalog names refuses writes the runtime believes legitimate; drop it, or declare it in the catalog of the module that owns the table',
                    $table,
                    implode(', ', $undeclared[$table]),
                );
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $known
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<array<string, mixed>>  $uniqueIndexes
     * @return list<string>
     */
    private static function indexProblems(
        SchemaCatalog $catalog,
        array $tables,
        array $known,
        array $indexes,
        array $uniqueIndexes,
    ): array {
        $problems = [];

        // Schema-unique names identify indexes, but their owning table must also match
        $defs = [];
        foreach ($indexes as $row) {
            $defs[(string) $row['indexname']] = [
                'table' => (string) $row['tablename'],
                'definition' => (string) $row['indexdef'],
                'usable' => (bool) $row['usable'],
            ];
        }
        foreach (array_intersect_key($catalog->indexes, array_flip($tables)) as $table => $expected) {
            foreach ($expected as $name => $needle) {
                if (! isset($defs[$name])) {
                    $problems[] = sprintf('index %s on %s: absent', $name, $table);
                } elseif ($defs[$name]['table'] !== $table) {
                    $problems[] = sprintf('index %s on %s: belongs to %s', $name, $table, $defs[$name]['table']);
                } elseif (! $defs[$name]['usable']) {
                    // an interrupted `CREATE INDEX CONCURRENTLY` leaves the definition whole and the index
                    // unusable: the planner ignores it, a unique one enforces nothing, writes still maintain
                    // it
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

        // the other direction, keyed by existence as for constraints: an index declared by name
        // alone maps to null
        $undeclared = [];
        foreach ($uniqueIndexes as $row) {
            $table = (string) $row['tablename'];
            if (! array_key_exists((string) $row['indexname'], $catalog->indexes[$table] ?? [])) {
                $undeclared[$table][] = (string) $row['indexname'];
            }
        }
        foreach ($known as $table) {
            if (isset($undeclared[$table])) {
                $problems[] = sprintf(
                    'table %s: undeclared unique index(es) %s — a live unique index no catalog names refuses writes the runtime believes legitimate; drop it, or declare it in the catalog of the module that owns the table',
                    $table,
                    implode(', ', $undeclared[$table]),
                );
            }
        }

        return $problems;
    }
}
