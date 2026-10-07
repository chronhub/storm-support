<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Random;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Random\NativeJitter;

use function array_unique;
use function sort;

/**
 * The production draw honors the `Jitter` contract: both bounds included, nothing outside them.
 */
final class NativeJitterTest extends TestCase
{
    #[Test]
    public function a_range_of_one_value_draws_that_value(): void
    {
        self::assertSame(7, new NativeJitter()->between(7, 7));
    }

    #[Test]
    public function the_draw_reaches_both_bounds_and_nothing_outside_them(): void
    {
        // both bounds are included: two hundred draws from a two-value range land on each of them,
        // a miss having a probability of two in 2^200, and never anywhere else
        $jitter = new NativeJitter;
        $drawn = [];
        for ($i = 0; $i < 200; $i++) {
            $drawn[] = $jitter->between(3, 4);
        }

        $seen = array_unique($drawn);
        sort($seen);
        self::assertSame([3, 4], $seen);
    }
}
