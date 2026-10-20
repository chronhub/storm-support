<?php

declare(strict_types=1);

namespace Storm\Support\Error;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\RetryableException;
use Throwable;

use function array_any;
use function in_array;
use function substr;

/**
 * Tells a failure of the infrastructure a message rides from a failure of the message itself.
 *
 * It walks the cause chain for a failure that clears without the message changing.
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
 * - A DBAL `RetryableException`, a deadlock or a lock wait timeout, and an
 *   `Exception\ConnectionException`, `ConnectionLost` among them: both clear on their own.
 *
 * - A DBAL `DatabaseObjectNotFoundException` or `InvalidFieldNameException`, a missing table or
 *   column: a deployment state a migration clears.
 *
 * - A DBAL `DriverException` whose SQLSTATE names an outage that DBAL's PostgreSQL converter leaves
 *   generic; the codes sit on the two constants below.
 *
 * Dead-lettering any of them would demand a hand replay for a fault that clears without one. Every
 * other DBAL failure is the message's own, since each retry meets it again:
 *
 * - A constraint violation.
 *
 * - A syntax or data error.
 *
 * - The root `Doctrine\DBAL\ConnectionException`, which reports no outage despite its name: its
 *   `NoActiveTransaction` and `CommitFailedRollbackOnly` name a transaction misuse.
 *
 * The chain is walked because a synchronously routed handler wraps what it met: a handler failure
 * whose cause names the broker is the broker's failure, and reading the top exception alone would
 * read it as poison.
 *
 * An unknown failure stays poison: the budget is the only bound on a row that will never succeed,
 * and a predicate that recognized everything would remove it. A publisher speaking a transport this
 * one does not know declares its outages by wrapping them in a recognized type.
 */
final readonly class TransientFailure
{
    /** Maximum number of cause levels walked through `getPrevious()`, which stops a runaway chain. */
    public const int MAX_CAUSE_DEPTH = 10;

    /**
     * Messenger's transport and retry markers, matched by name. The component is an optional
     * companion of this substrate, which several packages depend on and which stays free of any
     * transport of its own; an absent Messenger simply matches nothing, since `instanceof` against a
     * class that was never loaded is false and triggers no autoload.
     */
    private const array MESSENGER_MARKERS = [
        'Symfony\Component\Messenger\Exception\TransportException',
        'Symfony\Component\Messenger\Exception\RecoverableExceptionInterface',
    ];

    /**
     * SQLSTATE classes, the first two characters of a code, that name an outage as a whole:
     *
     * - `08`, connection exception
     * - `40`, transaction rollback
     * - `53`, insufficient resources
     * - `57`, operator intervention, `57014` query_canceled among them, which a `statement_timeout`
     *   raises
     */
    private const array TRANSIENT_SQLSTATE_CLASSES = ['08', '40', '53', '57'];

    /**
     * Single codes that name an outage inside a class otherwise left to the message:
     *
     * - `55P03`, lock_not_available, which a `lock_timeout` or a `NOWAIT` raises
     * - `25006`, read_only_sql_transaction, a write reaching a standby after a failover
     */
    private const array TRANSIENT_SQLSTATES = ['55P03', '25006'];

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
        if ($e instanceof RetryableException
            || $e instanceof ConnectionException
            || $e instanceof DatabaseObjectNotFoundException
            || $e instanceof InvalidFieldNameException) {
            return true;
        }

        if ($e instanceof DriverException && self::outage($e->getSQLState())) {
            return true;
        }

        return array_any(
            self::MESSENGER_MARKERS,
            static fn (string $marker): bool => $e instanceof $marker,
        );
    }

    private static function outage(?string $sqlState): bool
    {
        return $sqlState !== null
            && (in_array($sqlState, self::TRANSIENT_SQLSTATES, true)
                || in_array(substr($sqlState, 0, 2), self::TRANSIENT_SQLSTATE_CLASSES, true));
    }
}
