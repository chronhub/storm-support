<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Reports every way an installed schema diverges from what a {@see SchemaCatalog} declares.
 *
 * It interrogates the PostgreSQL catalogs after the DDL ran, inside the installer's own transaction,
 * so an install either proves its schema or rolls back untouched.
 *
 * It is purely mechanical, with no table name, predicate or retention rule: what to verify comes from
 * the catalog the caller hands it, so one probe serves packages that must not depend on each other.
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
     * Report every divergence between the declared schema and the live one in `current_schema()`.
     *
     * Every catalog renderer the probe reads names an object bare when the search path finds it first
     * and qualifies it otherwise, so one column would read two ways under two paths. The probe renders
     * under `pg_catalog` alone, a built-in reading bare and anything else with its schema, then restores
     * the caller's search path.
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
        // the inspected schema is bound before the path moves to `pg_catalog`, under which `format_type`,
        // `pg_get_expr`, `pg_get_constraintdef` and `pg_get_indexdef` then render, whatever the caller set
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
        // pg_catalog rather than information_schema, which drops the precision of timestamptz(6) and calls
        // xid8 `USER-DEFINED`; pg_attrdef is `LEFT JOIN`ed, as most columns have no default, and
        // pg_get_expr takes the owning relation's oid to resolve the column references inside a default
        $columns = $connection->fetchAllAssociative(
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

        $relkinds = [];
        foreach (array_intersect($catalog->partitioned, $tables) as $table) {
            $relkinds[$table] = $connection->fetchOne(
                /* language=PostgreSQL */
                'SELECT c.relkind FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = :schema AND c.relname = :table',
                ['schema' => $schema, 'table' => $table],
            );
        }

        // attachment rather than existence: a detached child keeps its name and columns, so the DDL re-run
        // skips it and the parent still reads 'p' with its named partitions, while an event of an unnamed
        // category has nowhere to land
        $defaultPartitionBounds = [];
        foreach (array_intersect_key($catalog->defaultPartitions, array_flip($tables)) as $parent => $child) {
            $defaultPartitionBounds[$parent] = $connection->fetchOne(
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
        }

        $constraints = [];
        $scoped = array_intersect_key($catalog->constraints, array_flip($tables));
        if ($scoped !== []) {
            // definitions rather than names: `ADD CONSTRAINT` converges on duplicate_object as `IF NOT
            // EXISTS` does, so a homonym under the declared name survives the DDL though it lost a value the
            // runtime writes
            $constraints = $connection->fetchAllAssociative(
                /* language=PostgreSQL */
                'SELECT c.relname, con.conname, con.convalidated, pg_get_constraintdef(con.oid) AS definition
                 FROM pg_constraint con
                 JOIN pg_class c ON c.oid = con.conrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = :schema AND con.conname IN (:names)',
                ['schema' => $schema, 'names' => array_merge(...array_map(array_keys(...), array_values($scoped)))],
                ['names' => ArrayParameterType::STRING],
            );
        }

        // local constraints only: an inherited partition copy compares on its parent, as the catalog does;
        // a not-null row, from PostgreSQL 18, belongs to the column shape, whose needle pins nullability,
        // and a constraint trigger is a trigger, which the probe does not read
        $localConstraints = [];
        $known = array_values(array_intersect($tables, array_keys($catalog->columns)));
        if ($known !== []) {
            $localConstraints = $connection->fetchAllAssociative(
                /* language=PostgreSQL */
                "SELECT c.relname, con.conname
                 FROM pg_constraint con
                 JOIN pg_class c ON c.oid = con.conrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = :schema AND c.relname IN (:tables)
                   AND con.conislocal AND con.coninhcount = 0 AND con.contype NOT IN ('n', 't')
                 ORDER BY con.conname",
                ['schema' => $schema, 'tables' => $known],
                ['tables' => ArrayParameterType::STRING],
            );
        }

        $indexes = [];
        $declared = array_intersect_key($catalog->indexes, array_flip($tables));
        if ($declared !== []) {
            // pg_index rather than the pg_indexes view, which renders a definition and nothing else, so an
            // index left `INVALID` by an interrupted concurrent build would read as complete
            $indexes = $connection->fetchAllAssociative(
                /* language=PostgreSQL */
                'SELECT c.relname AS indexname, t.relname AS tablename, pg_get_indexdef(i.indexrelid) AS indexdef,
                        (i.indisvalid AND i.indisready AND i.indislive) AS usable
                 FROM pg_index i
                 JOIN pg_class c ON c.oid = i.indexrelid
                 JOIN pg_class t ON t.oid = i.indrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = :schema AND c.relname IN (:names)',
                ['schema' => $schema, 'names' => array_merge(...array_map(array_keys(...), array_values($declared)))],
                ['names' => ArrayParameterType::STRING],
            );
        }

        // a unique index refuses writes like a constraint yet has no pg_constraint row; one backing a
        // constraint of its table is reported as that constraint, a partition copy compares on its parent,
        // and a plain index, which only speeds reads and is an operator's call, is never reported
        $uniqueIndexes = [];
        if ($known !== []) {
            $uniqueIndexes = $connection->fetchAllAssociative(
                /* language=PostgreSQL */
                'SELECT t.relname AS tablename, ic.relname AS indexname
                 FROM pg_index i
                 JOIN pg_class ic ON ic.oid = i.indexrelid
                 JOIN pg_class t ON t.oid = i.indrelid
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 WHERE n.nspname = :schema AND t.relname IN (:tables)
                   AND i.indisunique AND NOT ic.relispartition
                   AND NOT EXISTS (
                       SELECT 1 FROM pg_constraint con
                       WHERE con.conrelid = i.indrelid AND con.conindid = i.indexrelid
                   )
                 ORDER BY ic.relname',
                ['schema' => $schema, 'tables' => $known],
                ['tables' => ArrayParameterType::STRING],
            );
        }

        return SchemaDiff::problems(
            $catalog,
            $tables,
            $columns,
            relkinds: $relkinds,
            defaultPartitionBounds: $defaultPartitionBounds,
            constraints: $constraints,
            localConstraints: $localConstraints,
            indexes: $indexes,
            uniqueIndexes: $uniqueIndexes,
        );
    }
}
