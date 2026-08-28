<?php

declare(strict_types=1);

namespace Storm\Support\Console;

/**
 * Strict parsing for a long-running command's `--time-limit`, the console surface's one clock cap.
 *
 * `0` is the ONLY spelling of unlimited and it has to be asked for, exactly, never stumbled into: a
 * process that never recycles is a leak on a schedule, and the finite default is what prevents it. A
 * naive cast would turn `abc` and `-5` into that same `0` and disable the barrier in silence, so
 * anything else is refused rather than floored. The ceiling is a year, past which a cap is
 * indistinguishable from the unlimited nobody asked for.
 *
 * Every command that stays up reads its cap through here, so the two spellings cannot drift: the
 * `DaemonLoop` hosts turn a refusal into an `InvalidOptionException`, since they parse before the
 * command owns its exit code, and a command parsing it inline turns it into `Command::INVALID` with
 * its own message.
 */
final class TimeLimitOption
{
    /**
     * A year of seconds: the point past which a finite cap stops differing from unlimited.
     */
    public const int MAX_SECONDS = 31_536_000;

    /**
     * Parse the raw option into a cap in seconds.
     *
     * @return int<0, max>|null the cap, `0` meaning unlimited; null when the raw value is neither the
     *                          exact `0` nor a positive integer within `MAX_SECONDS`
     */
    public static function parse(mixed $raw): ?int
    {
        if ($raw === '0' || $raw === 0) {
            return 0;
        }

        $value = PositiveIntOption::parse($raw);

        return $value !== null && $value <= self::MAX_SECONDS ? $value : null;
    }
}
