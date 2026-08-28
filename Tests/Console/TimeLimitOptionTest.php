<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Console;

use const PHP_INT_MAX;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Support\Console\TimeLimitOption;

/**
 * The one clock cap of the console surface, and the asymmetry that makes it safe: `0` passes because
 * it is the deliberate word for unlimited, while everything a naive cast would ALSO turn into 0 is
 * refused. The named tests below pin the two bugs that shape the class, a typo buying an unlimited
 * daemon and a forty-nine cap saturating into one; the providers fence the boundaries.
 */
final class TimeLimitOptionTest extends TestCase
{
    #[Test]
    public function the_exact_zero_is_unlimited_because_an_operator_typed_it(): void
    {
        // both spellings the console can hand over: Symfony gives the string, a programmatic caller
        // the int, and unlimited has to survive either one
        self::assertSame(0, TimeLimitOption::parse('0'));
        self::assertSame(0, TimeLimitOption::parse(0));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_mistyped_cap_is_refused_rather_than_becoming_the_unlimited_nobody_asked_for(): void
    {
        // The whole reason the class exists rather than a cast: (int) 'abc' and (int) '-5' are both 0,
        // which here does not mean "fall back to a default", it means "run until the box gives out".
        self::assertNull(TimeLimitOption::parse('abc'));
        self::assertNull(TimeLimitOption::parse('-5'));
        self::assertNull(TimeLimitOption::parse('00'), 'one spelling of unlimited, exactly');
    }

    #[Test]
    #[DataProvider('accepted')]
    public function returns_the_parsed_cap(mixed $raw, int $expected): void
    {
        self::assertSame($expected, TimeLimitOption::parse($raw));
    }

    #[Test]
    #[DataProvider('rejected')]
    public function returns_null_for_anything_that_is_neither_zero_nor_a_cap_in_range(mixed $raw): void
    {
        self::assertNull(TimeLimitOption::parse($raw));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function accepted(): array
    {
        return [
            'unlimited as a string' => ['0', 0],
            'unlimited as an int' => [0, 0],
            'the one-second floor' => ['1', 1],
            'the house default' => ['3600', 3600],
            'a native int' => [900, 900],
            'the ceiling itself' => [TimeLimitOption::MAX_SECONDS, TimeLimitOption::MAX_SECONDS],
            'the ceiling as a string' => [(string) TimeLimitOption::MAX_SECONDS, TimeLimitOption::MAX_SECONDS],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejected(): array
    {
        return [
            // past the ceiling a cap stops differing from the unlimited it was not
            'one second past the ceiling' => [TimeLimitOption::MAX_SECONDS + 1],
            'a decade' => ['315360000'],
            'the largest int' => [PHP_INT_MAX],

            // what a cast would flatten into the unlimited zero
            'non numeric' => ['abc'],
            'negative string' => ['-1'],
            'negative int' => [-1],
            'sign-prefixed positive' => ['+60'],
            'decimal' => ['1.5'],
            'trailing garbage' => ['60s'],
            'padded zero' => ['00'],
            'empty string' => [''],
            'blank string' => [' '],
            'surrounding whitespace' => [' 60 '],

            // non-int, non-string types never parse
            'null' => [null],
            'float' => [60.0],
            'true' => [true],
            'false' => [false],
            'array' => [[60]],
            'object' => [new stdClass],
        ];
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unrepresentable_cap_is_refused_not_saturated(): void
    {
        // PHP saturates an over-long digit string at PHP_INT_MAX, so a fat-fingered cap would arrive as
        // a number the ceiling then has to catch; both gates are pinned, the parse and the bound
        self::assertNull(TimeLimitOption::parse('9223372036854775808'));
        self::assertNull(TimeLimitOption::parse(str_repeat('9', 40)));
    }
}
