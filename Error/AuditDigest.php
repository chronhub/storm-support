<?php

declare(strict_types=1);

namespace Storm\Support\Error;

use Closure;
use Doctrine\DBAL\Driver\Exception as DbalDriverException;
use Storm\Text\Str;
use Throwable;

/**
 * Turns a `Throwable` into a compact, persist-safe audit string.
 *
 * Its error columns include `es_outbox.last_error` and `workflow_outbox.last_error`. It walks the
 * `getPrevious()` chain, strips the NUL from anonymous-class names, since a PostgreSQL `text` column
 * rejects NUL, caps the total length, and joins the causes with a readable separator.
 *
 * It is a pure utility, with no I/O and no dependency, shared by the writers of a failure that outlives
 * the call that raised it:
 *
 * - Error columns of the outbox relays, saga timers, failed saga commands and failed projections
 * - Compensation records, and the log lines of a saga's undo and settle failures
 * - Operator output: the ApiOps audit line of a failed verb and a console command's warning
 *
 * `outline()` serves the writers whose reader must not see what a message may quote: the undo reason,
 * whose message may quote a `vars` value, and the audit line of the erasure verb, which must not quote
 * the subject it erases.
 *
 * That cross-cutting concern places it in `Support` rather than in a consumer, and its logic and test
 * keep it out of `Contracts`, which holds pure interfaces.
 */
final readonly class AuditDigest
{
    /** Maximum number of cause levels walked through `getPrevious()`, which stops a runaway chain. */
    public const int MAX_CAUSE_DEPTH = 10;

    /** Hard cap on the returned string length in characters, matched to the DB column shape. */
    public const int MAX_ERROR_CHARS = 8000;

    /** Separator between cause levels in the joined output. */
    public const string CAUSE_SEPARATOR = "\ncaused by: ";

    public static function digest(Throwable $error): string
    {
        return self::join($error, static fn (Throwable $level): string => self::className($level).': '.$level->getMessage());
    }

    /**
     * The digest's chain without its messages: each level reads as its class and code, the SQLSTATE
     * added when a DBAL driver reports one, so a reason served to operators reconciles without quoting
     * what a message may carry.
     *
     * @param  (Closure(Throwable): bool)|null  $keepsMessage  picks the levels whose message stays verbatim,
     *                                                         written as `digest()` writes it
     */
    public static function outline(Throwable $error, ?Closure $keepsMessage = null): string
    {
        return self::join($error, static function (Throwable $level) use ($keepsMessage): string {
            if ($keepsMessage !== null && $keepsMessage($level)) {
                return self::className($level).': '.$level->getMessage();
            }

            $code = 'code '.$level->getCode();
            if ($level instanceof DbalDriverException && ($state = $level->getSQLState()) !== null) {
                $code .= ', SQLSTATE '.$state;
            }

            return self::className($level).' ('.$code.')';
        });
    }

    /**
     * @param  Closure(Throwable): string  $render
     */
    private static function join(Throwable $error, Closure $render): string
    {
        $parts = [];

        for ($e = $error, $depth = 0; $e !== null && $depth < self::MAX_CAUSE_DEPTH; $e = $e->getPrevious(), $depth++) {
            $parts[] = $render($e);
        }

        // a message can carry a NUL too, where a PostgreSQL `text` column would truncate the digest and
        // drop the rest of the cause chain, so every remaining NUL is stripped from the whole summary
        $summary = str_replace("\0", '', implode(self::CAUSE_SEPARATOR, $parts));

        // persist-safe means valid UTF-8, which a message from a driver, a protocol or a native library may
        // not be: one stray 0xFF fails the dead-letter INSERT with 22021, so recording the primary failure
        // fails in turn and a recordable poison becomes a worker crash-loop; ill-formed bytes become U+FFFD,
        // and C0 controls and DEL, ANSI escapes included, become '?', keeping the separator's \n
        $summary = mb_scrub($summary, 'UTF-8');
        $summary = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '?', $summary) ?? $summary;

        return Str::excerpt(self::MAX_ERROR_CHARS)($summary);
    }

    private static function className(Throwable $level): string
    {
        $class = $level::class;

        // an anonymous class name carries a NUL byte and a path, as in `Foo@anonymous\0/path:line`; keep
        // the readable head, since a NUL in a PostgreSQL `text` column would truncate all that follows
        if (($nul = strpos($class, "\0")) !== false) {
            $class = substr($class, 0, $nul);
        }

        return $class;
    }
}
