<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use LogicException;
use RuntimeException;

use function sprintf;
use function usleep;

/**
 * The two waits an installer can hit, both BOUNDED, taken at the top of its transaction.
 *
 * `pg_advisory_xact_lock` waits, and waits forever: behind a stuck or orphaned sibling a deploy hook
 * shows no output, no error and no end, which reads as a hung deployment with nothing to diagnose.
 * A brief try-lock retry forgives the sub-second overlap of two deploys racing each other, then
 * names the real situation and lets the caller fail with an exit code a hook can act on.
 *
 * The second wait is the DDL's own, and no advisory key bounds it: an additive index declared since
 * the last deploy takes a lock on a table a live system is reading, and a standard build queues
 * behind every transaction already open on it. `lock_timeout` turns that hang into a failure an
 * operator can read; the way out on a large live table is a concurrent build outside any
 * transaction, which PostgreSQL forbids inside one, then a re-run to verify.
 *
 * Both are transaction-scoped, so the caller opens its transaction first and both end with the
 * commit or the rollback; there is nothing to unlock and no leak on a crash. Called outside one,
 * `SET LOCAL` would be a silent no-op and the DDL bound a promise on paper, so the absence of a
 * transaction is refused rather than tolerated.
 *
 * Mechanical on purpose, and shared: the three installers had one bounded implementation and two
 * that waited, which is a contract that differs by which command an operator happened to run.
 */
final class InstallLock
{
    /** Roughly eight tenths of a second in total, enough to absorb two deploys overlapping, short enough to answer. */
    private const int ATTEMPTS = 5;

    private const int PAUSE_MICROSECONDS = 200_000;

    /** The DDL wait budget: past normal statement turnover on a live table, well short of a hook that hangs. */
    public const int LOCK_TIMEOUT_MS = 5_000;

    /**
     * @param  int  $key  the installer's own advisory key; a distinct one per installer lets the saga
     *                    and telemetry schemas install side by side while each still serializes with itself
     * @param  string  $command  the command name the refusal quotes, so a deploy log says which hook stopped
     *
     * @throws LogicException when the caller has not opened its transaction, an authoring error
     * @throws RuntimeException when the lock stayed busy for the whole budget
     * @throws Exception on a DBAL failure taking the lock
     */
    public static function acquire(Connection $connection, int $key, string $command): void
    {
        if (! $connection->isTransactionActive()) {
            throw new LogicException(sprintf(
                'InstallLock::acquire() must run inside the caller\'s transaction, and %s opened none: '
                .'the advisory lock would release at once and the DDL bound would be a no-op.',
                $command,
            ));
        }

        $connection->executeStatement(sprintf('SET LOCAL lock_timeout = %d', self::LOCK_TIMEOUT_MS));

        foreach (range(1, self::ATTEMPTS) as $attempt) {
            if ((bool) $connection->fetchOne(sprintf('SELECT pg_try_advisory_xact_lock(%d)', $key))) {
                return;
            }

            if ($attempt < self::ATTEMPTS) {
                usleep(self::PAUSE_MICROSECONDS);
            }
        }

        // the target is read HERE rather than passed in: a hook that fails while pointed at a
        // database nobody expected is the confusing case, and the message is the only place an
        // operator learns which one it was
        $home = (string) $connection->fetchOne(
            /* language=PostgreSQL */
            "SELECT current_database() || '/' || current_schema()",
        );

        throw new RuntimeException(sprintf(
            'Another %s is already running on %s: the install advisory lock stayed busy. Retry once it finishes.',
            $command,
            $home,
        ));
    }
}
