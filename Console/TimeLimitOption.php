<?php

declare(strict_types=1);

namespace Storm\Support\Console;

/**
 * Strict parsing for a long-running command's `--time-limit`, the console surface's one clock cap.
 *
 * Only the exact `0` means unlimited, and it must be asked for: a process that never recycles
 * accumulates memory without bound, which the finite default prevents. A naive cast would turn `abc`
 * and `-5` into that same `0` and silently disable recycling, so any other value is refused rather
 * than floored.
 *
 * Every command that stays up reads its cap through here, so the two spellings cannot drift:
 *
 * - The `DaemonLoop` hosts turn a refusal into an `InvalidOptionException`, since they parse before
 *   the command owns its exit code.
 * - A command parsing it inline turns it into `Command::INVALID` with its own message.
 */
final class TimeLimitOption
{
    /**
     * A year of seconds, past which a finite cap no longer differs from the unlimited nobody asked for.
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
