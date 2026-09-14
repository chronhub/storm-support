<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Error;

use Doctrine\DBAL\Exception\NoActiveTransaction;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Support\Error\TransientFailure;

final class TransientFailureTest extends TestCase
{
    #[Test]
    public function a_database_failure_is_infrastructure(): void
    {
        self::assertTrue(TransientFailure::behind(NoActiveTransaction::new()));
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
        $wrapped = new RuntimeException('handler failed', 0, new LogicException('bad state', 0, NoActiveTransaction::new()));

        self::assertTrue(TransientFailure::behind($wrapped));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_cause_chain_longer_than_the_walk_stops_rather_than_runs_away(): void
    {
        // A chain can be circular or simply enormous; the walk is bounded, and the bound is a real
        // answer, not poison by accident: a cause buried deeper than the cap reads as unrecognized.
        $deep = NoActiveTransaction::new();
        for ($level = 0; $level < TransientFailure::MAX_CAUSE_DEPTH; $level++) {
            $deep = new RuntimeException('level '.$level, 0, $deep);
        }

        self::assertFalse(TransientFailure::behind($deep));

        // one level shallower, the same chain is recognized, which pins the cap rather than the walk
        self::assertTrue(TransientFailure::behind($deep->getPrevious() ?? $deep));
    }
}
