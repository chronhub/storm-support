<?php

declare(strict_types=1);

namespace Storm\Support\Error;

use Throwable;

/**
 * Turns a Throwable into a compact, persist-safe audit string for an error column such as
 * `es_outbox.last_error` or `workflow_outbox.last_error`. Walks the getPrevious chain, NUL-strips
 * anonymous-class names since a Postgres text column rejects NUL, caps the total length, and joins
 * causes with a readable separator.
 *
 * Pure utility: no I/O, no dependency. Shared by both outbox relays, Chronicler's OutboxRelay and
 * Saga's SagaOutboxRelay, the saga TimerRunner and the bundle's SagaCommandFailureListener; a
 * cross-cutting error-formatting concern, which is why it lives in Support rather than in any of
 * its consumers. It does not belong in Contracts, which holds pure interfaces, whereas this
 * carries logic plus a test.
 */
final readonly class AuditDigest
{
    /** Maximum number of cause levels walked through getPrevious(). Stops the chain runaway. */
    public const int MAX_CAUSE_DEPTH = 10;

    /** Hard cap on the returned string length in characters, matched to the DB column shape. */
    public const int MAX_ERROR_CHARS = 8000;

    /** Separator between cause levels in the joined output. */
    public const string CAUSE_SEPARATOR = "\ncaused by: ";

    public static function digest(Throwable $error): string
    {
        $parts = [];

        for ($e = $error, $depth = 0; $e !== null && $depth < self::MAX_CAUSE_DEPTH; $e = $e->getPrevious(), $depth++) {
            $class = $e::class;

            // Anonymous classes carry a NUL byte and path in their name, e.g. `Foo@anonymous\0/path:line`.
            // Keep the readable head; a NUL can't live in a Postgres `text` column anyway, where it would
            // truncate the value and swallow everything after it.
            if (($nul = strpos($class, "\0")) !== false) {
                $class = substr($class, 0, $nul);
            }

            $parts[] = $class.': '.$e->getMessage();
        }

        // The per-class strip above keeps an anonymous class's readable head; an exception message can
        // also carry a NUL; a Postgres `text` column truncates the digest there, swallowing the cause chain
        // after it, so strip any remaining NUL from the whole summary.
        $summary = str_replace("\0", '', implode(self::CAUSE_SEPARATOR, $parts));

        // Persist-safe means VALID UTF-8: a Throwable message is not contracted UTF-8, its bytes
        // coming from drivers, protocol payloads, or native libraries. One stray 0xFF would fail
        // the dead-letter INSERT with 22021, the secondary failure killing the recording of the
        // primary one, turning a recordable poison into a worker crash-loop. Ill-formed sequences
        // become U+FFFD; remaining C0 controls and DEL, ANSI escapes included, become '?', keeping
        // the cause separator's \n.
        $summary = mb_scrub($summary, 'UTF-8');
        $summary = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '?', $summary) ?? $summary;

        return mb_strlen($summary) > self::MAX_ERROR_CHARS
            ? mb_substr($summary, 0, self::MAX_ERROR_CHARS - 1).'…'
            : $summary;
    }
}
