<?php

declare(strict_types=1);

namespace Storm\Support\Error;

use Doctrine\DBAL\Exception as DbalFailure;
use Throwable;

use function array_any;

/**
 * Tells a failure of the INFRASTRUCTURE a message rides from a failure of the message itself, by
 * walking the cause chain for a type that names the transport or the database.
 *
 * The mechanism only; the verdict stays with the caller. Both outbox relays spend a retry budget on
 * a failed publish and dead-letter the row once it runs out, and that budget measures the broker's
 * health: a few ticks under an unreachable broker would otherwise convert a wait into an operator's
 * replay, since a dead-lettered row leaves every lag signal and comes back only by hand. So each
 * relay asks this before its own dead-letter gate and keeps the row pending when the answer is yes;
 * what a caller does with the answer, and at which gate, is the caller's.
 *
 * Recognized:
 *
 * - A Messenger `TransportException`, the broker refusing the handoff, and its
 *   `RecoverableExceptionInterface`, a handler asking for another delivery.
 *
 * - Any Doctrine DBAL failure: the driver refused, so nothing about the message was decided. A
 *   connection lost and a deadlock clear on their own; a missing table or column is a deployment
 *   state a migration clears. Dead-lettering either would demand a hand replay for a fault the next
 *   deploy already fixes.
 *
 * The chain is walked because a synchronously routed handler wraps what it met: a handler failure
 * whose cause names the broker is the broker's failure, and reading the top exception alone would
 * read it as poison.
 *
 * Unknown stays poison, deliberately: the budget is the only bound on a row that will never succeed,
 * and a predicate that recognized everything would remove it. A publisher speaking a transport this
 * one does not know declares its outages by wrapping them in a recognized type.
 */
final readonly class TransientFailure
{
    /** Maximum number of cause levels walked through getPrevious(). Stops the chain runaway. */
    public const int MAX_CAUSE_DEPTH = 10;

    /**
     * Messenger's transport and retry markers, matched by NAME. The component is an optional
     * companion of this substrate, which several packages depend on and which stays free of any
     * transport of its own; an absent Messenger simply matches nothing, since `instanceof` against a
     * class that was never loaded is false and triggers no autoload.
     */
    private const array MESSENGER_MARKERS = [
        'Symfony\Component\Messenger\Exception\TransportException',
        'Symfony\Component\Messenger\Exception\RecoverableExceptionInterface',
    ];

    public static function behind(Throwable $error): bool
    {
        for ($e = $error, $depth = 0; $e !== null && $depth < self::MAX_CAUSE_DEPTH; $e = $e->getPrevious(), $depth++) {
            if (self::names($e)) {
                return true;
            }
        }

        return false;
    }

    private static function names(Throwable $e): bool
    {
        if ($e instanceof DbalFailure) {
            return true;
        }

        return array_any(
            self::MESSENGER_MARKERS,
            static fn (string $marker): bool => $e instanceof $marker,
        );
    }
}
