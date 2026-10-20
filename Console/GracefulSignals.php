<?php

declare(strict_types=1);

namespace Storm\Support\Console;

use Override;
use Symfony\Component\Console\Command\Command;

use function array_filter;
use function array_values;

/**
 * Graceful `SIGTERM` and `SIGINT` for a console command that loops.
 *
 * A signal raises a stop flag that the host's loop reads between units of work, so the unit in flight
 * finishes its own commit before the process exits. Every Symfony `Command` already is a
 * `SignalableCommandInterface` with no-op defaults, so the host only composes this trait.
 *
 * Both signals are subscribed: a supervisor sends `SIGTERM` and an operator at a terminal `SIGINT`, and
 * a worker subscribed to only one of them would die mid-unit under the other.
 *
 * A signal is deferred only while a loop reads the flag, which the host reports through
 * `signalDeferred()`. With no loop to read it, deferring would absorb the signal, leaving Ctrl-C
 * without effect and `SIGKILL` as the only exit; the handler returns the conventional `128 + signal`
 * code instead, an immediate exit whose in-flight transaction rolls back atomically.
 *
 * @phpstan-require-extends Command
 */
trait GracefulSignals
{
    /** Set by `handleSignal()` while a loop reads it; the loop stops at its next boundary. */
    private bool $stopRequested = false;

    /**
     * {@inheritDoc}
     *
     * @return list<int>
     */
    #[Override]
    public function getSubscribedSignals(): array
    {
        return array_values(array_filter(
            [defined('SIGTERM') ? SIGTERM : null, defined('SIGINT') ? SIGINT : null],
            static fn (?int $signal): bool => $signal !== null,
        ));
    }

    #[Override]
    public function handleSignal(int $signal, int|false $previousExitCode = false): int|false
    {
        if (! $this->signalDeferred()) {
            return 128 + $signal;
        }

        $this->stopRequested = true;

        return false; // don't exit now; the loop finishes its unit of work, then stops at the boundary
    }

    /**
     * Whether a loop that reads the stop flag is running, so a signal can wait for it.
     */
    abstract private function signalDeferred(): bool;
}
