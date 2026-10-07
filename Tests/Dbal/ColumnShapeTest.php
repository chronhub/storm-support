<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Dbal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Dbal\ColumnShape;

/**
 * The fragment grammar of a declared column, a prefix for type and nullability, equality for a
 * named collation.
 *
 * The named test pins the case a containment check admits, a pin moved to a collation whose name
 * only starts like `C`.
 */
final class ColumnShapeTest extends TestCase
{
    #[Test]
    public function a_pinned_collation_refuses_one_whose_name_only_starts_like_it(): void
    {
        self::assertFalse(ColumnShape::holds('text not null collate C.utf8', 'text not null collate C'));
    }

    #[Test]
    #[DataProvider('holding')]
    public function the_live_shape_meets_the_fragment(string $live, string $fragment): void
    {
        self::assertTrue(ColumnShape::holds($live, $fragment));
    }

    #[Test]
    #[DataProvider('breaking')]
    public function the_live_shape_lost_the_fragment(string $live, string $fragment): void
    {
        self::assertFalse(ColumnShape::holds($live, $fragment));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function holding(): iterable
    {
        yield 'a bare type pins the type alone' => ['bigint not null', 'bigint'];
        yield 'type and nullability' => ['timestamp(6) with time zone not null', 'timestamp(6) with time zone not null'];
        yield 'a fragment without collation leaves it to the deployment' => ['text not null collate en_US.utf8', 'text not null'];
        yield 'the pinned collation itself' => ['text not null collate C', 'text not null collate C'];
        yield 'a nullable pinned column' => ['text null collate C', 'text null collate C'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function breaking(): iterable
    {
        yield 'a narrower type' => ['integer not null', 'bigint not null'];
        yield 'a type whose name ends like the declared one' => ['storm_test_text not null', 'text not null'];
        yield 'an array of the declared type' => ['bigint[] not null', 'bigint'];
        yield 'flipped nullability' => ['jsonb null', 'jsonb not null'];
        yield 'a pin fallen to the database default' => ['text not null', 'text not null collate C'];
        yield 'a pin moved to another collation' => ['text not null collate en_US.utf8', 'text not null collate C'];
        yield 'a pin moved to a name that extends it' => ['text not null collate C_shifted', 'text not null collate C'];
        yield 'the right collation on the wrong nullability' => ['text null collate C', 'text not null collate C'];
        yield 'a wider type that ends like the pinned one' => ['citext not null collate C', 'text not null collate C'];
        yield 'a pinned fragment short of its type and nullability' => ['text not null collate C', 'collate C'];
    }
}
