<?php

declare(strict_types=1);

namespace Storm\Support\Console;

use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException as UndeclaredOptionException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function array_filter;
use function array_values;
use function get_debug_type;
use function hrtime;
use function is_scalar;
use function min;
use function sprintf;
use function usleep;

/**
 * Makes a one-shot "drain once and exit" console command an optional in-process daemon. With
 * `--daemon` it boots once and loops the drain, polling `--sleep` ms when idle and recycling after
 * `--time-limit` s, instead of a scheduler re-`exec`-ing a fresh process per tick. That re-exec pays
 * the full framework bootstrap of autoload, container build and DB connect on every iteration,
 * a cost a tight re-run cadence multiplies. Booting once amortizes it to nothing. The default, without `--daemon`, is a single drain then exit,
 * so callers, smokes and external schedulers need no adaptation; daemon mode is additive.
 *
 * Every Symfony `Command` already is a `SignalableCommandInterface` with no-op defaults, so the host
 * just composes this trait, no `implements` needed, and the trait overrides the two methods so
 * SIGTERM/SIGINT stop the loop gracefully: the in-flight drain finishes its own transaction commit
 * before the loop exits between ticks, so nothing is half-done. In the one-shot default, with no
 * loop to read the flag, a signal exits immediately with the conventional `128 + signal` code, the
 * uninstrumented behavior: the in-flight transaction rolls back atomically, and Ctrl-C never goes
 * dead with `SIGKILL` as the only exit.
 *
 * Memory hygiene is time-based via `--time-limit` plus a supervisor relaunch, the same idiom as
 * {@see \Storm\Projector\Console\RunProjectionCommand} and {@see \Storm\Story\Console\ConsumeBatchedCommand};
 * there is no `--memory-limit`. The limit defaults finite at 3600 s; an unlimited daemon must be asked
 * for with `--time-limit=0`, never stumbled into, because a long-lived process that never recycles is
 * the accumulation failure mode. The options are parsed STRICTLY to keep that promise: a malformed
 * `--time-limit` or `--sleep` refuses the command loud with `InvalidOptionException`, never falling
 * back to unlimited or to a default. For the same reason, run a daemon under the prod environment: a
 * dev kernel accumulates debug collectors such as the Doctrine debug stack without bound where prod stays flat.
 *
 * @phpstan-require-extends Command
 */
trait DaemonLoop
{
    /** Poll-interval ceiling: keeps `usleep(ms * 1000)` far inside the int domain, and a poll beyond 10 min is a config typo. */
    private const int MAX_SLEEP_MS = 600_000;

    /** Bound on signal/deadline latency while idle: the poll sleeps in slices of at most this many ms. */
    private const int SLEEP_SLICE_MS = 100;

    /** Set by {@see handleSignal()} on SIGTERM/SIGINT; the loop reads it and stops between ticks. */
    private bool $stopRequested = false;

    /** True only while {@see daemonLoop()} runs; what tells a deferrable signal from a one-shot exit. */
    private bool $daemonLoopRunning = false;

    /**
     * Register `--daemon` / `--sleep` / `--time-limit` on the host command; call from its `configure()`,
     * alongside the command's own `--batch`.
     */
    private function configureDaemon(int $defaultSleepMs = 500): void
    {
        $this
            ->addOption('daemon', null, InputOption::VALUE_NONE, 'Stay up and loop the drain in-process (poll when idle), instead of a single pass')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Daemon idle poll interval in ms (used only when a pass drained nothing)', (string) $defaultSleepMs)
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Daemon: stop after N seconds (default 3600 — a supervisor relaunch is the memory hygiene); 0 = unlimited, explicit', '3600');
    }

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
        // one-shot mode: no loop will ever read the flag, so deferring would absorb the signal
        // entirely, Ctrl-C dead and SIGKILL the only exit; the uninstrumented default returns
        // instead, an immediate exit whose in-flight transaction rolls back atomically
        if (! $this->daemonLoopRunning) {
            return 128 + $signal;
        }

        $this->stopRequested = true;

        return false; // don't exit now; let the in-flight drain finish its commit, then stop between ticks
    }

    /**
     * Loop `$tick` until a signal or the time-limit. `$tick` runs one drain and returns how many items it
     * processed; `0` means the source was empty, so the loop polls for `$sleepMs` before the next pass. A busy
     * tick loops immediately while the drain is hot. `$tick` may throw; the exception propagates, so the process
     * exits non-zero and the supervisor respawns with back-off: the same crash-and-recover model as the other daemons.
     *
     * @param  callable(): int  $tick
     */
    private function daemonLoop(callable $tick, int $sleepMs, int $timeLimit): void
    {
        // hrtime, not wall-clock: NTP stepping the wall clock backwards must not stretch the
        // recycle budget the supervisor counts on. hrtime is int on 64-bit; the parse cap keeps
        // the nanosecond arithmetic inside the int domain
        $deadline = $timeLimit > 0 ? (int) hrtime(true) + $timeLimit * 1_000_000_000 : null;

        $this->daemonLoopRunning = true;

        try {
            while (! $this->stopRequested) {
                if ($deadline !== null && hrtime(true) >= $deadline) {
                    break; // recycle: a clean exit; the supervisor relaunches for memory hygiene
                }

                $idle = $tick() === 0;

                // re-check BETWEEN the tick and any sleep: a signal that landed during the tick must
                // stop the loop now, not after a full poll interval the supervisor's grace period may
                // not cover
                // @phpstan-ignore booleanOr.leftAlwaysFalse ($tick may invoke handleSignal re-entrant; this re-check is the point)
                if ($this->stopRequested || ($deadline !== null && hrtime(true) >= $deadline)) {
                    // @infection-ignore-all; equivalent, break to continue: both exits are re-read at
                    // the loop head, the stop flag by the `while` and the deadline by the check above
                    // the tick, so continuing here leaves without another tick and without the sleep
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
     * Idle poll in bounded slices, re-checking the stop flag and the deadline between slices, so a
     * signal is honored within ~{@see self::SLEEP_SLICE_MS} ms whatever `--sleep` says.
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
     * STRICT: the poll interval is a positive integer of milliseconds, bounded; a malformed value is
     * refused rather than silently replaced by the default, which would hide a broken deployment config.
     *
     * @throws InvalidOptionException when `--sleep` is not a positive integer within the bound, or
     *                                the host command never declared the daemon options
     */
    private function daemonSleepMs(InputInterface $input): int
    {
        try {
            $raw = $input->getOption('sleep');
        } catch (UndeclaredOptionException $e) {
            // the trait's own boundary discipline: the wiring mistake names its remedy instead of
            // leaking Symfony's undeclared-option type
            throw new InvalidOptionException("The daemon options are not declared: call configureDaemon() from the host command's configure().", previous: $e);
        }

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
     * STRICT: `0`, the exact string, is the ONLY spelling of "unlimited"; it must be asked for,
     * never stumbled into. A naive cast would turn `abc` and `-5` into 0 and silently disable the
     * recycle barrier, the exact accumulation failure mode the finite default exists to prevent.
     *
     * @throws InvalidOptionException when `--time-limit` is neither `0` nor a positive integer
     *                                within the bound, or the host command never declared the
     *                                daemon options
     */
    private function daemonTimeLimit(InputInterface $input): int
    {
        try {
            $raw = $input->getOption('time-limit');
        } catch (UndeclaredOptionException $e) {
            throw new InvalidOptionException("The daemon options are not declared: call configureDaemon() from the host command's configure().", previous: $e);
        }

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
}
