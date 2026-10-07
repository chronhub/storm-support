<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Dbal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Dbal\ConstraintShape;

/**
 * The needle grammar of a declared constraint, equality for a complete definition and containment
 * for a fragment.
 *
 * The named test pins the case containment admits, a homonym that only wraps the declared check.
 */
final class ConstraintShapeTest extends TestCase
{
    #[Test]
    public function a_complete_needle_refuses_a_definition_that_only_wraps_it(): void
    {
        self::assertSame(
            "constraint version_chk on event_store: definition is 'CHECK (((version > 0) OR true))', not 'CHECK ((version > 0))' — a pre-existing constraint with this name is incompatible; the installer creates, it does not migrate",
            ConstraintShape::divergence('event_store', 'version_chk', 'CHECK (((version > 0) OR true))', 'CHECK ((version > 0))'),
        );
    }

    #[Test]
    public function a_fragment_needle_names_what_the_definition_lost(): void
    {
        self::assertSame(
            "constraint status_chk on es_outbox: definition lost ''failed'' — a pre-existing constraint with this name is incompatible; the installer creates, it does not migrate",
            ConstraintShape::divergence('es_outbox', 'status_chk', "CHECK ((status = ANY (ARRAY['pending'::text, 'published'::text])))", "'failed'"),
        );
    }

    #[Test]
    #[DataProvider('meeting')]
    public function the_live_definition_meets_the_needle(string $definition, string $needle): void
    {
        self::assertNull(ConstraintShape::divergence('a_table', 'a_constraint', $definition, $needle));
    }

    #[Test]
    #[DataProvider('diverging')]
    public function the_live_definition_diverges_from_the_needle(string $definition, string $needle): void
    {
        self::assertNotNull(ConstraintShape::divergence('a_table', 'a_constraint', $definition, $needle));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function meeting(): iterable
    {
        yield 'the declared check itself' => ['CHECK ((version > 0))', 'CHECK ((version > 0))'];
        yield 'the declared primary key' => ['PRIMARY KEY (category, sequence_no)', 'PRIMARY KEY (category, sequence_no)'];
        yield 'the declared unique constraint' => ['UNIQUE (name)', 'UNIQUE (name)'];
        yield 'a fragment found inside' => ["CHECK ((status = ANY (ARRAY['pending'::text, 'failed'::text])))", "'failed'"];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function diverging(): iterable
    {
        yield 'a check on another column ending like the declared one' => ['CHECK ((event_version > 0))', 'CHECK ((version > 0))'];
        yield 'a floor that only starts like the declared one' => ['CHECK ((generation >= 10))', 'CHECK ((generation >= 1))'];
        yield 'a negated check' => ['CHECK ((NOT (version > 0)))', 'CHECK ((version > 0))'];
        yield 'a primary key on more columns' => ['PRIMARY KEY (category, sequence_no, id)', 'PRIMARY KEY (category, sequence_no)'];
        yield 'a unique constraint on other columns' => ['UNIQUE (name, id)', 'UNIQUE (name)'];
        yield 'a foreign key elsewhere' => ['FOREIGN KEY (a) REFERENCES public.b(id)', 'FOREIGN KEY (a) REFERENCES b(id)'];
        yield 'an exclusion that only wraps the declared one' => ['EXCLUDE USING gist (r WITH &&) WHERE (x)', 'EXCLUDE USING gist (r WITH &&)'];
        yield 'a fragment that is gone' => ["CHECK ((status = ANY (ARRAY['pending'::text])))", "'failed'"];
    }
}
