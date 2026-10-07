<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

/**
 * What a package declares its installed schema must look like: the verification data
 * {@see SchemaProbe} reads back from the PostgreSQL catalogs. It is NOT DDL; it mirrors what the
 * owning `*Schema` classes create, scoped to what the runtime actually relies on.
 *
 * The split is the placement rule of `Support` applied literally: the probe owns the HOW, the loop
 * and the queries, mechanical and tested once; a catalog owns the WHAT, which table and which
 * index-shape matters, and stays with the package that declares it. So `Ledger` and `Saga` verify
 * their schemas with the same probe while depending on nothing of each other.
 *
 * Why any of this exists: `CREATE … IF NOT EXISTS` treats "an object of this name exists" as "the
 * object is the one declared". A pre-existing table missing a column, or a homonymous index that
 * lost its partial predicate, no-ops the DDL and an install would report success on a schema the
 * runtime cannot use.
 */
final readonly class SchemaCatalog
{
    /**
     * @param  array<string, array<string, string|null>>  $columns  per table, column name => a fragment
     *                                                              the live column shape must start with,
     *                                                              ending on a word, or
     *                                                              null to check presence only. The probe
     *                                                              composes the live shape from
     *                                                              `format_type` plus ` not null` or
     *                                                              ` null`, so `bigint not null` pins type
     *                                                              and nullability while a bare `bigint`
     *                                                              pins the type alone. A fragment naming
     *                                                              a collation is the whole shape and must
     *                                                              equal it, by the rule of `ColumnShape`.
     *                                                              The table keys are also the set of
     *                                                              VERIFIABLE tables, so a table absent
     *                                                              here is reported rather than passed
     * @param  array<string, array<string, string|null>>  $constraints  per table, constraint name => a
     *                                                                  needle for the live
     *                                                                  `pg_get_constraintdef`, or null to check
     *                                                                  presence only. A complete definition,
     *                                                                  opening on its keyword, must equal it;
     *                                                                  any other needle is a fragment it must
     *                                                                  contain, by the rule of `ConstraintShape`.
     *                                                                  A name proves nothing about a
     *                                                                  pre-existing homonym, and a CHECK that
     *                                                                  silently lost a value it must admit
     *                                                                  bites at runtime, not at install
     * @param  array<string, array<string, string|null>>  $indexes  per table, index name => a fragment the
     *                                                              live `indexdef` must contain, or null to
     *                                                              check presence only. The fragment is how a
     *                                                              partial predicate or a leading column, the
     *                                                              things that make the index worth having,
     *                                                              get verified rather than assumed
     * @param  list<string>  $partitioned  tables that must be partitioned parents, `relkind = 'p'`
     * @param  array<string, string>  $defaultPartitions  parent table => the DEFAULT partition that must be
     *                                                    ATTACHED to it. A detached child keeps its name, so
     *                                                    `CREATE TABLE … PARTITION OF … DEFAULT` no-ops on it
     *                                                    exactly as every other `IF NOT EXISTS` does: the
     *                                                    parent keeps `relkind = 'p'` and its named
     *                                                    partitions, verifies green, and has nowhere left to
     *                                                    route a category it does not name
     * @param  array<string, array<string, string>>  $identities  per table, column name => the expected
     *                                                            `pg_attribute.attidentity`, `'a'` for
     *                                                            `GENERATED ALWAYS AS IDENTITY` or `'d'` for
     *                                                            `GENERATED BY DEFAULT`. A column absent
     *                                                            here is not checked for identity at all; a
     *                                                            pre-existing homonym missing it passes the
     *                                                            type/nullability shape check unchanged, so
     *                                                            an insert that relies on the generator
     *                                                            fails at the first row, never at install
     * @param  array<string, array<string, string|null>>  $defaults  per table, column name => the expression
     *                                                               the live `DEFAULT` must equal, as
     *                                                               `pg_get_expr` renders it, or null to
     *                                                               check presence only. Equality, since a
     *                                                               homonym function of another schema
     *                                                               renders with that schema around the
     *                                                               declared call: a
     *                                                               pre-existing column can carry the right
     *                                                               type while its default silently names
     *                                                               the wrong function, or none at all
     */
    public function __construct(
        public array $columns,
        public array $constraints = [],
        public array $indexes = [],
        public array $partitioned = [],
        public array $defaultPartitions = [],
        public array $identities = [],
        public array $defaults = [],
    ) {}
}
