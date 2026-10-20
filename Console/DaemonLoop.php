<?php

declare(strict_types=1);

namespace Storm\Support\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException as UndeclaredOptionException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function get_debug_type;
use function hrtime;
use function is_scalar;
use function min;
use function sprintf;
use function usleep;

/**
 * Turns a one-shot "drain once and exit" console command into an optional in-process daemon.
 *
 * With `--daemon` the command boots once and loops the drain, polling every `--sleep` ms when idle
 * and recycling after `--time-limit` s. A scheduler re-executing the command per tick would pay the
 * whole framework bootstrap each time, autoload, container build and database connection, a cost a
 * tight cadence multiplies; booting once amortizes it. Without `--daemon` the command drains once and
 * exits, so callers, smokes and external schedulers need no change.
 *
 * The graceful stop is `GracefulSignals`, which `ConsumeBatchedCommand` and `RunProjectionCommand`
 * also compose around their own loops. `SIGTERM` and `SIGINT` stop the loop between ticks, after
 * the in-flight drain commits its transaction, so nothing is half-done. A signal is deferred only
 * while `daemonLoop()` runs; in the one-shot default it exits at once with the conventional
 * `128 + signal` code.
 *
 * Memory hygiene is time-based, `--time-limit` plus a supervisor relaunch, as in
 * {@see \Storm\Projector\Console\RunProjectionCommand} and {@see \Storm\Story\Console\ConsumeBatchedCommand};
 * there is no `--memory-limit`:
 *
 * - The limit defaults to 3600 s. An unlimited daemon must be asked for with `--time-limit=0`, since
 *   a long-lived process that never recycles is the accumulation failure mode.
 * - Both options are parsed strictly to keep that promise: a malformed `--time-limit` or `--sleep`
 *   refuses the command with `InvalidOptionException`, never falling back to unlimited or a default.
 * - For the same reason a daemon must run under the prod environment: a dev kernel accumulates debug
 *   collectors, such as the Doctrine debug stack, without bound, where prod stays flat.
 *
 * @phpstan-require-extends Command
 */
trait DaemonLoop
{
    use GracefulSignals;

    /**
     * Poll-interval ceiling: keeps `usleep(ms * 1000)` far inside the int domain, and a poll beyond 10 min is
     * a config typo.
     */
    private const int MAX_SLEEP_MS = 600_000;

    /** Bound on signal/deadline latency while idle: the poll sleeps in slices of at most this many ms. */
    private const int SLEEP_SLICE_MS = 100;

    /** True only while {@see daemonLoop()} runs; what tells a deferrable signal from a one-shot exit. */
    private bool $daemonLoopRunning = false;

    /**
     * Register `--daemon`, `--sleep` and `--time-limit` on the host command; call it from the host's
     * `configure()`, beside the command's own `--batch`.
     */
    private function configureDaemon(int $defaultSleepMs = 500): void
    {
        $this
            ->addOption('daemon', null, InputOption::VALUE_NONE, 'Stay up and loop the drain in-process (poll when idle), instead of a single pass')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Daemon idle poll interval in ms (used only when a pass drained nothing)', (string) $defaultSleepMs)
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Daemon: stop after N seconds (default 3600 — a supervisor relaunch is the memory hygiene); 0 = unlimited, explicit', '3600');
    }

    private function signalDeferred(): bool
    {
        return $this->daemonLoopRunning;
    }

    /**
     * Loop `$tick` until a signal or the time limit.
     *
     * `$tick` runs one drain and returns how many items it processed. `0` means the source was empty,
     * so the loop polls for `$sleepMs` before the next pass; a busy tick loops again at once. An
     * exception from `$tick` propagates: the process exits non-zero and the supervisor respawns it
     * with back-off, the crash-and-recover model of the other daemons.
     *
     * @param  callable(): int  $tick
     */
    private function daemonLoop(callable $tick, int $sleepMs, int $timeLimit): void
    {
        // hrtime, since NTP stepping the wall clock back must not stretch the supervisor's recycle budget;
        // it is an int on 64-bit, and the parse cap keeps the nanosecond sum inside the int domain
        $deadline = $timeLimit > 0 ? (int) hrtime(true) + $timeLimit * 1_000_000_000 : null;

        $this->daemonLoopRunning = true;

        try {
            while (! $this->stopRequested) {
                if ($deadline !== null && hrtime(true) >= $deadline) {
                    break; // recycle: a clean exit; the supervisor relaunches for memory hygiene
                }

                $idle = $tick() === 0;

                // re-check between the tick and any sleep: a signal that landed during the tick must stop
                // the loop now, not after a full poll the supervisor's grace period may not cover
                // $tick may invoke handleSignal re-entrantly, which this re-check reads
                // @phpstan-ignore booleanOr.leftAlwaysFalse
                if ($this->stopRequested || ($deadline !== null && hrtime(true) >= $deadline)) {
                    // equivalent, break to continue: both exits are re-read at
                    // the loop head, the stop flag by the `while` and the deadline by the check above
                    // the tick, so continuing here leaves without another tick and without the sleep
                    // @infection-ignore-all
                    break;
                }

                if ($idle) {
                    $this->interruptibleSleep($sleepMs, $deadline);
                }
            }
        } finally {
            $this->daemonLoopRunning = false;
        }
    }

    /**
     * Idle poll in bounded slices, re-checking the stop flag and the deadline between them, so a
     * signal is honored within about {@see self::SLEEP_SLICE_MS} ms whatever `--sleep` says.
     */
    private function interruptibleSleep(int $sleepMs, ?int $deadline): void
    {
        for ($remaining = $sleepMs; $remaining > 0; $remaining -= self::SLEEP_SLICE_MS) {
            if ($this->stopRequested || ($deadline !== null && hrtime(true) >= $deadline)) {
                return;
            }

            usleep(min(self::SLEEP_SLICE_MS, $remaining) * 1000);
        }
    }

    /**
     * The poll interval, a positive integer of milliseconds up to `MAX_SLEEP_MS`.
     *
     * A malformed value is refused rather than silently replaced by the default, which would hide a
     * broken deployment config.
     *
     * @throws InvalidOptionException when `--sleep` is not a positive integer within the bound, or
     *                                the host command never declared the daemon options
     */
    private function daemonSleepMs(InputInterface $input): int
    {
        $raw = $this->daemonOption($input, 'sleep');

        $value = PositiveIntOption::parse($raw);

        if ($value === null || $value > self::MAX_SLEEP_MS) {
            throw new InvalidOptionException(sprintf(
                'Invalid --sleep "%s" — a positive integer of milliseconds up to %d.',
                is_scalar($raw) ? (string) $raw : get_debug_type($raw),
                self::MAX_SLEEP_MS,
            ));
        }

        return $value;
    }

    /**
     * The time limit in seconds, where only the exact string `0` means unlimited.
     *
     * A naive cast would turn `abc` and `-5` into 0 and silently disable recycling, the accumulation
     * failure mode the finite default exists to prevent.
     *
     * @throws InvalidOptionException when `--time-limit` is neither `0` nor a positive integer
     *                                within the bound, or the host command never declared the
     *                                daemon options
     */
    private function daemonTimeLimit(InputInterface $input): int
    {
        $raw = $this->daemonOption($input, 'time-limit');

        $value = TimeLimitOption::parse($raw);

        if ($value === null) {
            throw new InvalidOptionException(sprintf(
                'Invalid --time-limit "%s" — 0 (unlimited, explicit) or a positive integer of seconds up to %d.',
                is_scalar($raw) ? (string) $raw : get_debug_type($raw),
                TimeLimitOption::MAX_SECONDS,
            ));
        }

        return $value;
    }

    /**
     * @throws InvalidOptionException when the host command never declared the daemon options
     */
    private function daemonOption(InputInterface $input, string $name): mixed
    {
        try {
            return $input->getOption($name);
        } catch (UndeclaredOptionException $e) {
            // the trait's own boundary discipline: the wiring mistake names its remedy instead of
            // leaking Symfony's undeclared-option type
            throw new InvalidOptionException("The daemon options are not declared: call configureDaemon() from the host command's configure().", previous: $e);
        }
    }
}
