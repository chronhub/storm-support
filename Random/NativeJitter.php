<?php

declare(strict_types=1);

namespace Storm\Support\Random;

use Storm\Contracts\Random\Jitter;

use function random_int;

/**
 * The production jitter: a uniform draw from the operating system's source of randomness.
 */
final readonly class NativeJitter implements Jitter
{
    public function between(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
