<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Error;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\CommitFailedRollbackOnly;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\SavepointsNotSupported;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Support\Error\TransientFailure;
use Throwable;

final class TransientFailureTest extends TestCase
{
    #[Test]
    public function a_database_failure_is_infrastructure(): void
    {
        self::assertTrue(TransientFailure::behind(self::deadlock()));
    }

    #[Test]
    public function an_ordinary_failure_is_not(): void
    {
        // the default answer, and the one that keeps a retry budget meaningful: an unrecognized
        // failure stays the message's own, so the caller's dead-letter gate still bounds it
        self::assertFalse(TransientFailure::behind(new RuntimeException('the handler refuses this')));
    }

    #[Test]
    public function the_cause_chain_is_walked(): void
    {
        // what a synchronously routed handler produces: it wraps the failure it met, and only the
        // cause names the infrastructure
        $wrapped = new RuntimeException('handler failed', 0, new LogicException('bad state', 0, self::deadlock()));

        self::assertTrue(TransientFailure::behind($wrapped));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_cause_chain_longer_than_the_walk_stops_rather_than_runs_away(): void
    {
        // a chain can be circular or simply enormous, so the walk is bounded and the bound is part of the
        // answer: a cause buried deeper than the cap reads as unrecognized
        $deep = self::deadlock();
        for ($level = 0; $level < TransientFailure::MAX_CAUSE_DEPTH; $level++) {
            $deep = new RuntimeException('level '.$level, 0, $deep);
        }

        self::assertFalse(TransientFailure::behind($deep));

        // one level shallower, the same chain is recognized, which pins the cap rather than the walk
        self::assertTrue(TransientFailure::behind($deep->getPrevious() ?? $deep));
    }

    #[Test]
    #[DataProvider('poisonFailures')]
    public function a_unique_constraint_violation_is_not_transient(Throwable $failure): void
    {
        // the same statement fails the same way on every retry, so holding the row pending would
        // block its lane for good; the budget has to be allowed to dead-letter it
        self::assertFalse(TransientFailure::behind($failure));
    }

    #[Test]
    #[DataProvider('transientFailures')]
    public function an_infrastructure_failure_is_transient(Throwable $failure): void
    {
        self::assertTrue(TransientFailure::behind($failure));
    }

    /**
     * Each family twice, at the head of the chain and as the cause a synchronous handler wrapped.
     *
     * Every SQLSTATE is the one PostgreSQL reports for that failure; `55000` and `25001` share their
     * class with a recognized single code, so they pin the code rather than its class.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function poisonFailures(): iterable
    {
        yield from self::atTheHeadAndAsACause([
            'a unique constraint violation' => new UniqueConstraintViolationException(self::driverFailure('23505'), null),
            'a not-null constraint violation' => new NotNullConstraintViolationException(self::driverFailure('23502'), null),
            'a foreign key constraint violation' => new ForeignKeyConstraintViolationException(self::driverFailure('23503'), null),
            'a syntax error' => new SyntaxErrorException(self::driverFailure('42601'), null),
            'a commit with no transaction open' => NoActiveTransaction::new(),
            'a commit on a rollback-only transaction' => CommitFailedRollbackOnly::new(),
            'a savepoint on a driver without them' => SavepointsNotSupported::new(),
            'a data error the converter leaves generic' => new DriverException(self::driverFailure('22012'), null),
            'a lock-class state other than lock_not_available' => new DriverException(self::driverFailure('55000'), null),
            'a transaction-state code other than read_only_sql_transaction' => new DriverException(self::driverFailure('25001'), null),
            'a driver failure that carries no SQLSTATE' => new DriverException(self::driverFailure(null), null),
            'a plain runtime exception' => new RuntimeException('the handler refuses this'),
        ]);
    }

    /**
     * Each family twice, as above.
     *
     * Apart from the deadlock, a row recognized by its type carries a SQLSTATE outside every recognized
     * code, so it proves the type rather than the code.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function transientFailures(): iterable
    {
        yield from self::atTheHeadAndAsACause([
            'a deadlock' => new DeadlockException(self::driverFailure('40P01'), null),
            'a lock wait timeout' => new LockWaitTimeoutException(self::driverFailure('HY000'), null),
            'a connection refused' => new ConnectionException(self::driverFailure('HY000'), null),
            'a connection lost' => new ConnectionLost(self::driverFailure('HY000'), null),
            'a missing table' => new TableNotFoundException(self::driverFailure('42P01'), null),
            'a missing column' => new InvalidFieldNameException(self::driverFailure('42703'), null),
            'lock_not_available, left generic' => new DriverException(self::driverFailure('55P03'), null),
            'query_canceled, left generic' => new DriverException(self::driverFailure('57014'), null),
            'read_only_sql_transaction, left generic' => new DriverException(self::driverFailure('25006'), null),
            'too_many_connections, left generic' => new DriverException(self::driverFailure('53300'), null),
            'connection_failure, left generic' => new DriverException(self::driverFailure('08006'), null),
            'statement_completion_unknown, left generic' => new DriverException(self::driverFailure('40003'), null),
        ]);
    }

    /**
     * @param  array<string, Throwable>  $families
     * @return iterable<string, array{Throwable}>
     */
    private static function atTheHeadAndAsACause(array $families): iterable
    {
        foreach ($families as $family => $failure) {
            yield $family.' at the head' => [$failure];
            yield $family.' as a cause' => [new RuntimeException('handler failed', 0, $failure)];
        }
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(self::driverFailure('40P01'), null);
    }

    private static function driverFailure(?string $sqlState): AbstractException
    {
        return new class('the driver refused', $sqlState) extends AbstractException {};
    }
}
