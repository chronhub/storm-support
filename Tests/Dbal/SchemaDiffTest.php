<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Dbal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Dbal\SchemaCatalog;
use Storm\Support\Dbal\SchemaDiff;

/**
 * The comparison half of the schema probe, tested on rows held in memory.
 *
 * Each divergence renders the message an installer reports, letter for letter what the probe renders
 * against a real Postgres, and the problems come in the probe's order.
 */
final class SchemaDiffTest extends TestCase
{
    #[Test]
    public function a_missing_column_is_named(): void
    {
        $catalog = new SchemaCatalog(columns: ['es_outbox' => ['id' => 'bigint not null', 'type' => 'text not null']]);

        self::assertSame([
            'table es_outbox: missing column(s) type — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
        ], SchemaDiff::problems($catalog, ['es_outbox'], [self::column('es_outbox', 'id', 'bigint')]));
    }

    #[Test]
    public function a_column_without_its_identity_is_named(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['sequence_no' => 'bigint not null']],
            identities: ['event_store' => ['sequence_no' => 'a']],
        );

        self::assertSame([
            "column sequence_no on event_store: not GENERATED AS IDENTITY (attidentity '', wanted 'a') — a pre-existing column with the right type but no generator accepts an explicit value silently and never produces one on its own",
        ], SchemaDiff::problems($catalog, ['event_store'], [self::column('event_store', 'sequence_no', 'bigint')]));
    }

    #[Test]
    public function a_divergent_default_is_named(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['xact_id' => 'xid8 not null']],
            defaults: ['event_store' => ['xact_id' => 'pg_current_xact_id()']],
        );

        self::assertSame([
            "column xact_id on event_store: DEFAULT is 'pg_current_xact_id_if_assigned()', not 'pg_current_xact_id()' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
        ], SchemaDiff::problems($catalog, ['event_store'], [
            self::column('event_store', 'xact_id', 'xid8', default: 'pg_current_xact_id_if_assigned()'),
        ]));
    }

    #[Test]
    public function a_detached_default_partition_is_named(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['category' => 'text not null']],
            partitioned: ['event_store'],
            defaultPartitions: ['event_store' => 'event_store_default'],
        );

        self::assertSame([
            'partition event_store_default: not attached to event_store — a detached child of the right name and shape is what the DDL skips; an event of an unnamed category has nowhere to land',
        ], SchemaDiff::problems(
            $catalog,
            ['event_store'],
            [self::column('event_store', 'category', 'text')],
            relkinds: ['event_store' => 'p'],
            defaultPartitionBounds: ['event_store' => false],
        ));
    }

    #[Test]
    public function an_undeclared_live_constraint_is_named(): void
    {
        $catalog = new SchemaCatalog(columns: ['es_outbox' => ['attempts' => 'integer not null']]);

        self::assertSame([
            'table es_outbox: undeclared constraint(s) storm_planted — a live constraint no catalog names refuses writes the runtime believes legitimate; drop it, or declare it in the catalog of the module that owns the table',
        ], SchemaDiff::problems(
            $catalog,
            ['es_outbox'],
            [self::column('es_outbox', 'attempts', 'integer')],
            localConstraints: [['relname' => 'es_outbox', 'conname' => 'storm_planted']],
        ));
    }

    #[Test]
    public function an_absent_declared_index_is_named(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['es_outbox' => ['status' => 'text not null']],
            indexes: ['es_outbox' => ['es_outbox_pending_idx' => "WHERE (status = 'pending'"]],
        );

        self::assertSame(
            ['index es_outbox_pending_idx on es_outbox: absent'],
            SchemaDiff::problems($catalog, ['es_outbox'], [self::column('es_outbox', 'status', 'text')]),
        );
    }

    #[Test]
    public function a_schema_that_meets_every_declaration_has_no_problem(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => [
                'sequence_no' => 'bigint not null',
                'stream' => 'text not null collate C',
                'header' => 'jsonb null',
                'xact_id' => null,
            ]],
            constraints: ['event_store' => [
                'event_store_pk' => 'PRIMARY KEY (sequence_no)',
                'event_store_stream_chk' => "'x'",
                'event_store_named_chk' => null,
            ]],
            indexes: ['event_store' => ['event_store_stream_idx' => '(stream)', 'event_store_any_idx' => null]],
            partitioned: ['event_store'],
            defaultPartitions: ['event_store' => 'event_store_default'],
            identities: ['event_store' => ['sequence_no' => 'a']],
            defaults: ['event_store' => ['xact_id' => 'pg_current_xact_id()', 'header' => null]],
        );

        self::assertSame([], SchemaDiff::problems(
            $catalog,
            ['event_store'],
            [
                self::column('event_store', 'sequence_no', 'bigint', identity: 'a'),
                self::column('event_store', 'stream', 'text', collation: 'C'),
                self::column('event_store', 'header', 'jsonb', notNull: false, default: "'{}'::jsonb"),
                self::column('event_store', 'xact_id', 'xid8', default: 'pg_current_xact_id()'),
            ],
            relkinds: ['event_store' => 'p'],
            defaultPartitionBounds: ['event_store' => 'DEFAULT'],
            constraints: [
                ['relname' => 'event_store', 'conname' => 'event_store_pk', 'convalidated' => true, 'definition' => 'PRIMARY KEY (sequence_no)'],
                ['relname' => 'event_store', 'conname' => 'event_store_stream_chk', 'convalidated' => true, 'definition' => "CHECK ((stream <> 'x'::text))"],
                ['relname' => 'event_store', 'conname' => 'event_store_named_chk', 'convalidated' => true, 'definition' => 'CHECK (true)'],
            ],
            localConstraints: [
                ['relname' => 'event_store', 'conname' => 'event_store_named_chk'],
                ['relname' => 'event_store', 'conname' => 'event_store_pk'],
                ['relname' => 'event_store', 'conname' => 'event_store_stream_chk'],
            ],
            indexes: [
                ['indexname' => 'event_store_stream_idx', 'tablename' => 'event_store', 'indexdef' => 'CREATE INDEX event_store_stream_idx ON ONLY event_store USING btree (stream)', 'usable' => true],
                ['indexname' => 'event_store_any_idx', 'tablename' => 'event_store', 'indexdef' => 'CREATE INDEX event_store_any_idx ON ONLY event_store USING btree (header)', 'usable' => true],
            ],
            uniqueIndexes: [['tablename' => 'event_store', 'indexname' => 'event_store_any_idx']],
        ));
    }

    #[Test]
    public function an_unmapped_table_reads_unverifiable_and_an_absent_one_names_its_constraints(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['stream_heads' => ['stream' => 'text not null']],
            constraints: ['stream_heads' => ['stream_heads_pk' => 'PRIMARY KEY (stream)']],
        );

        self::assertSame([
            'table custom_thing: not in the conformance map — nothing to verify against',
            'table stream_heads: absent',
            'constraint stream_heads_pk on stream_heads: absent',
        ], SchemaDiff::problems($catalog, ['custom_thing', 'stream_heads'], [self::column('custom_thing', 'id', 'integer', notNull: false)]));
    }

    #[Test]
    public function undeclared_columns_are_named_in_name_order(): void
    {
        $catalog = new SchemaCatalog(columns: ['es_outbox' => ['id' => 'bigint not null']]);

        self::assertSame([
            'table es_outbox: undeclared column(s) storm_alpha, storm_zeta — a live column no catalog names is verified by nothing; declare it in the catalog of the module that owns the table',
        ], SchemaDiff::problems($catalog, ['es_outbox'], [
            self::column('es_outbox', 'storm_zeta', 'text', notNull: false),
            self::column('es_outbox', 'id', 'bigint'),
            self::column('es_outbox', 'storm_alpha', 'text', notNull: false),
        ]));
    }

    #[Test]
    public function a_column_shape_that_lost_its_fragment_is_named_and_a_null_fragment_checks_presence_alone(): void
    {
        $catalog = new SchemaCatalog(columns: ['stream_heads' => ['last_version' => 'bigint not null', 'stream' => null]]);

        self::assertSame([
            "column last_version on stream_heads: shape 'integer not null' lost 'bigint not null' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
        ], SchemaDiff::problems($catalog, ['stream_heads'], [
            self::column('stream_heads', 'last_version', 'integer'),
            self::column('stream_heads', 'stream', 'bytea', notNull: false),
        ]));
    }

    #[Test]
    public function the_live_shape_carries_nullability_then_a_collation_other_than_the_default(): void
    {
        $catalog = new SchemaCatalog(columns: ['snapshots' => ['state' => 'jsonb not null', 'stream' => 'text not null collate C']]);

        self::assertSame([
            "column state on snapshots: shape 'jsonb null' lost 'jsonb not null' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
            "column stream on snapshots: shape 'text not null collate \"public\".\"C\"' lost 'text not null collate C' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
        ], SchemaDiff::problems($catalog, ['snapshots'], [
            self::column('snapshots', 'state', 'jsonb', notNull: false),
            self::column('snapshots', 'stream', 'text', collation: '"public"."C"'),
        ]));
    }

    #[Test]
    public function an_identity_of_the_wrong_kind_is_named_and_an_absent_column_is_reported_once(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['sequence_no' => 'bigint not null', 'position' => 'bigint not null']],
            identities: ['event_store' => ['sequence_no' => 'a', 'position' => 'a']],
        );

        self::assertSame([
            'table event_store: missing column(s) position — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
            "column sequence_no on event_store: not GENERATED AS IDENTITY (attidentity 'd', wanted 'a') — a pre-existing column with the right type but no generator accepts an explicit value silently and never produces one on its own",
        ], SchemaDiff::problems($catalog, ['event_store'], [self::column('event_store', 'sequence_no', 'bigint', identity: 'd')]));
    }

    #[Test]
    public function a_missing_default_is_named_even_when_presence_alone_is_declared(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['xact_id' => 'xid8 not null', 'header' => 'jsonb not null', 'position' => 'bigint not null']],
            defaults: ['event_store' => ['xact_id' => 'pg_current_xact_id()', 'header' => null, 'position' => null]],
        );

        self::assertSame([
            'table event_store: missing column(s) position — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
            'column xact_id on event_store: no DEFAULT — expected one',
            'column header on event_store: no DEFAULT — expected one',
        ], SchemaDiff::problems($catalog, ['event_store'], [
            self::column('event_store', 'xact_id', 'xid8'),
            self::column('event_store', 'header', 'jsonb'),
        ]));
    }

    #[Test]
    public function a_parent_that_is_not_partitioned_is_named_and_one_that_does_not_exist_is_left_to_the_column_check(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['category' => 'text not null'], 'event_links' => ['category' => 'text not null']],
            partitioned: ['event_store', 'event_links'],
        );

        self::assertSame([
            'table event_links: absent',
            "table event_store: not partitioned (relkind 'r') — the runtime expects a LIST-partitioned parent",
        ], SchemaDiff::problems(
            $catalog,
            ['event_links', 'event_store'],
            [self::column('event_store', 'category', 'text')],
            relkinds: ['event_store' => 'r', 'event_links' => false],
        ));
    }

    #[Test]
    public function a_default_partition_bounded_by_values_is_named_and_a_bound_read_as_null_still_renders(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['category' => 'text not null'], 'event_links' => ['category' => 'text not null']],
            defaultPartitions: ['event_store' => 'event_store_default', 'event_links' => 'event_links_default'],
        );

        self::assertSame([
            "partition event_store_default on event_store: bound is 'FOR VALUES IN ('DEFAULT')', not DEFAULT — the catch-all of the category routing is a bounded partition instead",
            "partition event_links_default on event_links: bound is '', not DEFAULT — the catch-all of the category routing is a bounded partition instead",
        ], SchemaDiff::problems(
            $catalog,
            ['event_store', 'event_links'],
            [self::column('event_store', 'category', 'text'), self::column('event_links', 'category', 'text')],
            defaultPartitionBounds: ['event_store' => "FOR VALUES IN ('DEFAULT')", 'event_links' => null],
        ));
    }

    #[Test]
    public function a_declared_constraint_is_absent_unvalidated_or_divergent(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['es_outbox' => ['attempts' => 'integer not null']],
            constraints: ['es_outbox' => [
                'es_outbox_status_chk' => "'failed'",
                'es_outbox_attempts_chk' => 'CHECK ((attempts >= 0))',
                'es_outbox_failed_at_chk' => null,
                'es_outbox_position_chk' => 'position > 0',
            ]],
        );

        self::assertSame([
            'constraint es_outbox_status_chk on es_outbox: absent',
            "constraint es_outbox_attempts_chk on es_outbox: definition is 'CHECK ((attempts >= 1))', not 'CHECK ((attempts >= 0))' — a pre-existing constraint with this name is incompatible; the installer creates, it does not migrate",
            'constraint es_outbox_failed_at_chk on es_outbox: NOT VALID — it guards new rows only, and the existing ones were never checked against it',
        ], SchemaDiff::problems(
            $catalog,
            ['es_outbox'],
            [self::column('es_outbox', 'attempts', 'integer')],
            constraints: [
                // a homonym on an unrelated table never verifies the declared one
                ['relname' => 't_probe_homonym', 'conname' => 'es_outbox_status_chk', 'convalidated' => true, 'definition' => "CHECK ((status <> 'failed'::text))"],
                ['relname' => 'es_outbox', 'conname' => 'es_outbox_attempts_chk', 'convalidated' => true, 'definition' => 'CHECK ((attempts >= 1))'],
                ['relname' => 'es_outbox', 'conname' => 'es_outbox_failed_at_chk', 'convalidated' => false, 'definition' => 'CHECK (true)'],
                ['relname' => 'es_outbox', 'conname' => 'es_outbox_position_chk', 'convalidated' => true, 'definition' => 'CHECK ((position > 0))'],
            ],
        ));
    }

    #[Test]
    public function a_constraint_declared_by_name_alone_is_not_undeclared(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['es_outbox' => ['position' => 'bigint not null']],
            constraints: ['es_outbox' => ['es_outbox_position_chk' => null]],
        );

        self::assertSame([], SchemaDiff::problems(
            $catalog,
            ['es_outbox'],
            [self::column('es_outbox', 'position', 'bigint')],
            constraints: [['relname' => 'es_outbox', 'conname' => 'es_outbox_position_chk', 'convalidated' => true, 'definition' => 'CHECK ((position > 0))']],
            localConstraints: [['relname' => 'es_outbox', 'conname' => 'es_outbox_position_chk']],
        ));
    }

    #[Test]
    public function a_declared_index_on_another_table_unusable_or_reshaped_is_named(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['es_outbox' => ['status' => 'text not null']],
            indexes: ['es_outbox' => [
                'es_outbox_pending_idx' => "WHERE (status = 'pending'",
                'es_outbox_failed_idx' => "WHERE (status = 'failed'",
                'es_outbox_partition_idx' => '(partition_key, id)',
            ]],
        );

        self::assertSame([
            'index es_outbox_pending_idx on es_outbox: belongs to es_outbox_archive',
            'index es_outbox_failed_idx on es_outbox: INVALID — an interrupted concurrent build leaves its definition whole; the planner ignores it and a unique one enforces nothing',
            "index es_outbox_partition_idx on es_outbox: definition lost '(partition_key, id)' — a pre-existing index with this name is incompatible; the installer creates, it does not migrate",
        ], SchemaDiff::problems(
            $catalog,
            ['es_outbox'],
            [self::column('es_outbox', 'status', 'text')],
            indexes: [
                ['indexname' => 'es_outbox_pending_idx', 'tablename' => 'es_outbox_archive', 'indexdef' => "CREATE INDEX es_outbox_pending_idx ON es_outbox_archive USING btree (id) WHERE (status = 'pending'::text)", 'usable' => true],
                ['indexname' => 'es_outbox_failed_idx', 'tablename' => 'es_outbox', 'indexdef' => "CREATE INDEX es_outbox_failed_idx ON es_outbox USING btree (id) WHERE (status = 'failed'::text)", 'usable' => false],
                ['indexname' => 'es_outbox_partition_idx', 'tablename' => 'es_outbox', 'indexdef' => 'CREATE INDEX es_outbox_partition_idx ON es_outbox USING btree (id)', 'usable' => true],
            ],
        ));
    }

    #[Test]
    public function an_undeclared_unique_index_is_named_and_one_declared_by_name_alone_is_not(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['es_outbox' => ['id' => 'bigint not null']],
            indexes: ['es_outbox' => ['es_outbox_id_uq' => null]],
        );

        self::assertSame([
            'table es_outbox: undeclared unique index(es) storm_planted_uq — a live unique index no catalog names refuses writes the runtime believes legitimate; drop it, or declare it in the catalog of the module that owns the table',
        ], SchemaDiff::problems(
            $catalog,
            ['es_outbox'],
            [self::column('es_outbox', 'id', 'bigint')],
            indexes: [['indexname' => 'es_outbox_id_uq', 'tablename' => 'es_outbox', 'indexdef' => 'CREATE UNIQUE INDEX es_outbox_id_uq ON es_outbox USING btree (id)', 'usable' => true]],
            uniqueIndexes: [
                ['tablename' => 'es_outbox', 'indexname' => 'es_outbox_id_uq'],
                ['tablename' => 'es_outbox', 'indexname' => 'storm_planted_uq'],
            ],
        ));
    }

    #[Test]
    public function a_declaration_for_a_table_outside_the_requested_set_is_not_verified(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['es_outbox' => ['id' => 'bigint not null'], 'event_store' => ['sequence_no' => 'bigint not null']],
            constraints: ['event_store' => ['event_store_pk' => null]],
            indexes: ['event_store' => ['event_store_sequence_no_idx' => null]],
            partitioned: ['event_store'],
            defaultPartitions: ['event_store' => 'event_store_default'],
            identities: ['event_store' => ['sequence_no' => 'a']],
            defaults: ['event_store' => ['sequence_no' => null]],
        );

        self::assertSame([], SchemaDiff::problems(
            $catalog,
            ['es_outbox'],
            [self::column('es_outbox', 'id', 'bigint'), self::column('event_store', 'sequence_no', 'bigint')],
            relkinds: ['event_store' => 'r'],
        ));
    }

    #[Test]
    public function the_problems_come_in_the_probe_order(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => [
                'sequence_no' => 'bigint not null',
                'xact_id' => 'xid8 not null',
                'missing' => 'text not null',
            ]],
            constraints: ['event_store' => ['event_store_pk' => null]],
            indexes: ['event_store' => ['event_store_sequence_no_idx' => null]],
            partitioned: ['event_store'],
            defaultPartitions: ['event_store' => 'event_store_default'],
            identities: ['event_store' => ['sequence_no' => 'a']],
            defaults: ['event_store' => ['xact_id' => null]],
        );

        self::assertSame([
            'table event_store: missing column(s) missing — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
            'table event_store: undeclared column(s) extra — a live column no catalog names is verified by nothing; declare it in the catalog of the module that owns the table',
            "column xact_id on event_store: shape 'bigint not null' lost 'xid8 not null' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
            "column sequence_no on event_store: not GENERATED AS IDENTITY (attidentity '', wanted 'a') — a pre-existing column with the right type but no generator accepts an explicit value silently and never produces one on its own",
            'column xact_id on event_store: no DEFAULT — expected one',
            "table event_store: not partitioned (relkind 'r') — the runtime expects a LIST-partitioned parent",
            'partition event_store_default: not attached to event_store — a detached child of the right name and shape is what the DDL skips; an event of an unnamed category has nowhere to land',
            'constraint event_store_pk on event_store: absent',
            'table event_store: undeclared constraint(s) storm_planted — a live constraint no catalog names refuses writes the runtime believes legitimate; drop it, or declare it in the catalog of the module that owns the table',
            'index event_store_sequence_no_idx on event_store: absent',
            'table event_store: undeclared unique index(es) storm_planted_uq — a live unique index no catalog names refuses writes the runtime believes legitimate; drop it, or declare it in the catalog of the module that owns the table',
        ], SchemaDiff::problems(
            $catalog,
            ['event_store'],
            [
                self::column('event_store', 'sequence_no', 'bigint'),
                self::column('event_store', 'xact_id', 'bigint'),
                self::column('event_store', 'extra', 'text'),
            ],
            relkinds: ['event_store' => 'r'],
            defaultPartitionBounds: ['event_store' => false],
            localConstraints: [['relname' => 'event_store', 'conname' => 'storm_planted']],
            uniqueIndexes: [['tablename' => 'event_store', 'indexname' => 'storm_planted_uq']],
        ));
    }

    #[Test]
    public function an_absent_table_does_not_stop_the_check_of_the_next_one(): void
    {
        $catalog = new SchemaCatalog(columns: [
            'stream_heads' => ['stream' => 'text not null'],
            'es_outbox' => ['id' => 'bigint not null', 'type' => 'text not null'],
        ]);

        self::assertSame([
            'table stream_heads: absent',
            'table es_outbox: missing column(s) type — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
        ], SchemaDiff::problems($catalog, ['stream_heads', 'es_outbox'], [self::column('es_outbox', 'id', 'bigint')]));
    }

    #[Test]
    public function a_column_skipped_by_the_shape_check_does_not_stop_the_next_one(): void
    {
        // the first column is absent and the second declares presence alone; the third lost its shape
        $catalog = new SchemaCatalog(columns: ['stream_heads' => [
            'position' => 'bigint not null',
            'stream' => null,
            'last_version' => 'bigint not null',
        ]]);

        self::assertSame([
            'table stream_heads: missing column(s) position — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
            "column last_version on stream_heads: shape 'integer not null' lost 'bigint not null' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
        ], SchemaDiff::problems($catalog, ['stream_heads'], [
            self::column('stream_heads', 'stream', 'text'),
            self::column('stream_heads', 'last_version', 'integer'),
        ]));
    }

    #[Test]
    public function an_absent_column_does_not_stop_the_identity_check_of_the_next_one(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['position' => 'bigint not null', 'sequence_no' => 'bigint not null']],
            identities: ['event_store' => ['position' => 'a', 'sequence_no' => 'a']],
        );

        self::assertSame([
            'table event_store: missing column(s) position — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
            "column sequence_no on event_store: not GENERATED AS IDENTITY (attidentity '', wanted 'a') — a pre-existing column with the right type but no generator accepts an explicit value silently and never produces one on its own",
        ], SchemaDiff::problems($catalog, ['event_store'], [self::column('event_store', 'sequence_no', 'bigint')]));
    }

    #[Test]
    public function every_divergent_identity_is_named(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['sequence_no' => 'bigint not null', 'position' => 'bigint not null']],
            identities: ['event_store' => ['sequence_no' => 'a', 'position' => 'd']],
        );

        self::assertSame([
            "column sequence_no on event_store: not GENERATED AS IDENTITY (attidentity '', wanted 'a') — a pre-existing column with the right type but no generator accepts an explicit value silently and never produces one on its own",
            "column position on event_store: not GENERATED AS IDENTITY (attidentity 'a', wanted 'd') — a pre-existing column with the right type but no generator accepts an explicit value silently and never produces one on its own",
        ], SchemaDiff::problems($catalog, ['event_store'], [
            self::column('event_store', 'sequence_no', 'bigint'),
            self::column('event_store', 'position', 'bigint', identity: 'a'),
        ]));
    }

    #[Test]
    public function an_absent_column_does_not_stop_the_default_check_of_the_next_one(): void
    {
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['position' => 'bigint not null', 'xact_id' => 'xid8 not null']],
            defaults: ['event_store' => ['position' => null, 'xact_id' => 'pg_current_xact_id()']],
        );

        self::assertSame([
            'table event_store: missing column(s) position — a pre-existing object with this name is incompatible; the installer creates, it does not migrate',
            "column xact_id on event_store: DEFAULT is 'pg_current_xact_id_if_assigned()', not 'pg_current_xact_id()' — a pre-existing column with this name is incompatible; the installer creates, it does not migrate",
        ], SchemaDiff::problems($catalog, ['event_store'], [
            self::column('event_store', 'xact_id', 'xid8', default: 'pg_current_xact_id_if_assigned()'),
        ]));
    }

    #[Test]
    public function undeclared_objects_are_read_only_on_requested_tables_the_catalog_maps(): void
    {
        // `custom_thing` is requested but unmapped, `event_store` mapped but not requested: neither
        // has a declaration to fall short of, whatever rows name them
        $catalog = new SchemaCatalog(columns: ['es_outbox' => ['id' => 'bigint not null'], 'event_store' => ['sequence_no' => 'bigint not null']]);

        self::assertSame(['table custom_thing: not in the conformance map — nothing to verify against'], SchemaDiff::problems(
            $catalog,
            ['custom_thing', 'es_outbox'],
            [self::column('es_outbox', 'id', 'bigint'), self::column('custom_thing', 'id', 'integer')],
            localConstraints: [
                ['relname' => 'custom_thing', 'conname' => 'custom_thing_pk'],
                ['relname' => 'event_store', 'conname' => 'storm_planted'],
            ],
            uniqueIndexes: [
                ['tablename' => 'custom_thing', 'indexname' => 'custom_thing_uq'],
                ['tablename' => 'event_store', 'indexname' => 'storm_planted_uq'],
            ],
        ));
    }

    #[Test]
    public function a_relkind_is_judged_only_for_a_table_declared_partitioned(): void
    {
        $catalog = new SchemaCatalog(columns: ['es_outbox' => ['id' => 'bigint not null']], partitioned: ['event_store']);

        self::assertSame([], SchemaDiff::problems(
            $catalog,
            ['es_outbox'],
            [self::column('es_outbox', 'id', 'bigint')],
            relkinds: ['es_outbox' => 'r'],
        ));
    }

    #[Test]
    public function a_parent_or_a_partition_the_probe_did_not_read_reads_as_not_found(): void
    {
        // no `relkind` read is a relation that does not exist, left to the column check; no bound read
        // is a child that is not attached
        $catalog = new SchemaCatalog(
            columns: ['event_store' => ['category' => 'text not null']],
            partitioned: ['event_store'],
            defaultPartitions: ['event_store' => 'event_store_default'],
        );

        self::assertSame([
            'partition event_store_default: not attached to event_store — a detached child of the right name and shape is what the DDL skips; an event of an unnamed category has nowhere to land',
        ], SchemaDiff::problems($catalog, ['event_store'], [self::column('event_store', 'category', 'text')]));
    }

    /**
     * One row of the probe's column read, under its aliases.
     *
     * @return array<string, mixed>
     */
    private static function column(
        string $table,
        string $column,
        string $type,
        bool $notNull = true,
        ?string $collation = null,
        string $identity = '',
        ?string $default = null,
    ): array {
        return [
            'table_name' => $table,
            'column_name' => $column,
            'column_type' => $type,
            'not_null' => $notNull ? 1 : 0,
            'collation_name' => $collation,
            'identity' => $identity,
            'default_expr' => $default,
        ];
    }
}
