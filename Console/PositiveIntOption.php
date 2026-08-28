<?php

declare(strict_types=1);

namespace Storm\Support\Console;

use function ctype_digit;
use function is_int;
use function is_string;

/**
 * Strict positive-integer parsing for an operator-supplied count, on the console and on the ops HTTP
 * surface that twins it; `--batch=abc` must be rejected, never become PHP's silent `(int)` 0. A zero
 * reaches the store as `LIMIT 0`, so a drain claims no row while the command still reports a clean
 * pass, without a word to the operator. The caller turns null into `Command::INVALID`, or into the
 * surface's 422, with its own parameter-specific message.
 */
final class PositiveIntOption
{
    /**
     * @return positive-int|null the parsed value, or null when the raw option is not a positive integer
     */
    public static function parse(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 1 ? $raw : null;
        }

        if (is_string($raw) && ctype_digit($raw)) {
            // refuse what an int cannot REPRESENT before casting: PHP saturates an over-long digit
            // string at PHP_INT_MAX, which silently removes the operator's cap. A 40-nine --batch
            // would mean "process practically the whole table in one pass"
            $max = (string) PHP_INT_MAX;
            $canonical = ltrim($raw, '0');

            if ($canonical === '') {
                return null; // all zeros
            }

            if (strlen($canonical) > strlen($max) || (strlen($canonical) === strlen($max) && $canonical > $max)) {
                return null; // not representable as int
            }

            $value = (int) $canonical;

            return $value >= 1 ? $value : null;
        }

        return null;
    }
}
