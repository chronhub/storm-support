<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Console;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Support\Console\PositiveIntOption;

/**
 * The strict guard the batch/prune/relay options lean on: a positive integer passes through, everything
 * else is null, never PHP's silent `(int)` 0. The named test below pins the exact motivating bug that
 * `--batch=abc` must be rejected, not cast to a hidden default; the providers fence the boundaries.
 */
final class PositiveIntOptionTest extends TestCase
{
    #[Test]
    public function rejects_a_non_numeric_option_instead_of_silently_casting_to_zero(): void
    {
        // The whole reason the class exists: (int) 'abc' === 0 would have re-armed a hidden default.
        self::assertNull(PositiveIntOption::parse('abc'));
    }

    #[Test]
    public function returns_the_native_int_unchanged(): void
    {
        self::assertSame(42, PositiveIntOption::parse(42));
    }

    #[Test]
    #[DataProvider('accepted')]
    public function returns_the_parsed_positive_int(mixed $raw, int $expected): void
    {
        self::assertSame($expected, PositiveIntOption::parse($raw));
    }

    #[Test]
    #[DataProvider('rejected')]
    public function returns_null_for_anything_that_is_not_a_positive_integer(mixed $raw): void
    {
        self::assertNull(PositiveIntOption::parse($raw));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function accepted(): array
    {
        return [
            'int at the one boundary' => [1, 1],
            'int above one' => [42, 42],
            'largest int' => [PHP_INT_MAX, PHP_INT_MAX],
            'digit string one' => ['1', 1],
            'multi-digit string' => ['1000', 1000],
            // A console option like --batch=007 means 7, not malformed input: leading zeros collapse
            // to the value here, unlike the domain SequencePosition which treats them as invalid.
            'leading zeros collapse to the value' => ['007', 7],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejected(): array
    {
        return [
            // ints below the 1 boundary
            'int zero' => [0],
            'negative int' => [-1],
            'smallest int' => [PHP_INT_MIN],

            // strings that are not a strict run of ASCII digits
            'string zero' => ['0'],
            'sign-prefixed negative' => ['-1'],
            'sign-prefixed positive' => ['+1'],
            'empty string' => [''],
            'blank string' => [' '],
            'leading whitespace' => [' 1'],
            'trailing whitespace' => ['1 '],
            'decimal string' => ['1.5'],
            'thousands separator' => ['1_000'],
            'non numeric' => ['xyz'],
            'trailing garbage' => ['12abc'],
            'hex literal' => ['0x1A'],
            'scientific notation' => ['1e3'],

            // non-int, non-string types never parse
            'null' => [null],
            'float' => [1.5],
            'whole float' => [5.0],
            'true' => [true],
            'false' => [false],
            'array' => [[1]],
            'object' => [new stdClass],
        ];
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unrepresentable_integer_is_refused_not_saturated(): void
    {
        // PHP saturates an over-long digit string at PHP_INT_MAX, which silently removed the
        // operator's cap: a --batch of forty nines would process practically the whole table
        self::assertSame(PHP_INT_MAX, PositiveIntOption::parse((string) PHP_INT_MAX), 'the exact max is representable');
        self::assertNull(PositiveIntOption::parse('9223372036854775808'), 'max + 1 is not');
        self::assertNull(PositiveIntOption::parse(str_repeat('9', 40)));
    }

    #[Test]
    public function leading_zeros_still_parse_to_their_value(): void
    {
        self::assertSame(7, PositiveIntOption::parse('007'));
        self::assertNull(PositiveIntOption::parse('000'));
    }
}
