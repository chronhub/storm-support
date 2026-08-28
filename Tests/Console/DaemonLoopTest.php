<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Console\DaemonLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException as UndeclaredOptionException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;

use function hrtime;
use function sprintf;

/**
 * The daemon loop the claim-loop commands `saga:relay`, `outbox:relay` and `saga:timers` share via the
 * trait: it loops a tick until a signal or the time-limit, polling when a pass drained nothing and
 * looping hot when it did. The loop's time-based EXIT is not asserted here because it is
 * non-deterministic and slow, the same reason `ConsumeBatchedCommandTest` bounds its loop by signal,
 * not by the clock; the signal is the deterministic handle.
 *
 * The idle poll's slicing is another matter and is asserted, since a deadline crossing one slice in
 * proves the re-check without paying a poll interval.
 */
final class DaemonLoopTest extends TestCase
{
    #[Test]
    public function loops_the_tick_until_a_signal_lands_then_stops_after_the_current_pass(): void
    {
        $host = $this->host();
        $calls = 0;

        $host->runDaemonLoop(function () use (&$calls, $host): int {
            $calls++;
            if ($calls === 3) {
                // a signal lands during the 3rd pass; INSIDE the loop it defers, false by contract
                self::assertFalse($host->handleSignal($this->term()));
            }

            return 1; // busy, so loop hot, no idle poll
        });

        self::assertSame(3, $calls); // passes 1, 2, 3 ran; the loop breaks before a 4th
    }

    #[Test]
    public function an_idle_pass_still_honours_a_signal_instead_of_spinning(): void
    {
        $host = $this->host();
        $calls = 0;

        $host->runDaemonLoop(function () use (&$calls, $host): int {
            $calls++;
            $host->handleSignal($this->term());

            return 0; // idle, so the loop would poll, but the signal must still stop it
        }, sleepMs: 1);

        self::assertSame(1, $calls);
    }

    #[Test]
    public function a_signal_outside_the_daemon_loop_exits_immediately_and_term_int_are_subscribed(): void
    {
        $host = $this->host();

        // one-shot mode: no loop will ever read the flag, so deferring would absorb the signal
        // entirely, Ctrl-C dead; the uninstrumented default returns, the conventional 128 + signal
        self::assertSame(128 + $this->term(), $host->handleSignal($this->term()));

        if (defined('SIGTERM')) {
            self::assertContains(SIGTERM, $host->getSubscribedSignals());
        }

        if (defined('SIGINT')) {
            self::assertContains(SIGINT, $host->getSubscribedSignals());
        }
    }

    #[Test]
    public function a_malformed_sleep_refuses_loud_instead_of_a_silent_fallback(): void
    {
        // A silent fallback would replace `--sleep=abc` with 500 without a word, so a broken deployment
        // config looks valid. A malformed option is a refusal, never a guess.
        $host = $this->host();

        self::assertSame(250, $host->readSleepMs(['--sleep' => '250'])); // a real value passes through

        foreach (['0', '-5', 'abc', (string) (DaemonLoopTestHost::maxSleepMs() + 1)] as $bad) {
            try {
                $host->readSleepMs(['--sleep' => $bad]);
                self::fail(sprintf('--sleep "%s" must be refused', $bad));
            } catch (InvalidOptionException $e) {
                self::assertStringContainsString('--sleep', $e->getMessage());
            }
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function only_an_explicit_zero_means_unlimited_everything_else_malformed_refuses(): void
    {
        // Casting `abc` or `-5` to 0 means UNLIMITED, so a typo silently disables the recycle
        // barrier, the exact accumulation failure mode the finite default exists to prevent
        $host = $this->host();

        self::assertSame(0, $host->readTimeLimit(['--time-limit' => '0']));     // unlimited: explicit, the only spelling
        self::assertSame(120, $host->readTimeLimit(['--time-limit' => '120'])); // a real limit passes through

        foreach (['-5', 'abc', '00', str_repeat('9', 40)] as $bad) {
            try {
                $host->readTimeLimit(['--time-limit' => $bad]);
                self::fail(sprintf('--time-limit "%s" must be refused, never become unlimited', $bad));
            } catch (InvalidOptionException $e) {
                self::assertStringContainsString('--time-limit', $e->getMessage());
            }
        }
    }

    #[Test]
    #[Group('slow')]
    public function the_idle_poll_re_checks_between_slices_instead_of_sleeping_its_whole_interval(): void
    {
        // the recycle deadline can fall in the middle of a long idle poll, and a daemon that slept
        // the interval out would overshoot its budget by up to `--sleep`; the supervisor's grace
        // period is what pays for that overshoot. The nominal poll below is 5 s, and neither call
        // may take anything like it
        $host = $this->host();
        $nominalMs = 5_000;

        $crossed = $this->elapsedMs(fn () => $host->runInterruptibleSleep($nominalMs, (int) hrtime(true) - 1));
        self::assertLessThan(500, $crossed, 'a deadline already behind us must return before any sleep at all');

        $oneSliceIn = $this->elapsedMs(fn () => $host->runInterruptibleSleep($nominalMs, (int) hrtime(true) + 50_000_000));
        self::assertGreaterThanOrEqual(90, $oneSliceIn, 'the poll does sleep; it is the slice that is bounded, not the sleep that is skipped');
        self::assertLessThan(1_000, $oneSliceIn, 'the deadline is honored a slice in, never a full interval later');
    }

    #[Test]
    public function a_host_that_never_declared_the_daemon_options_is_handed_the_remedy(): void
    {
        // the trait is public API for app commands, and this wiring mistake is otherwise reported by
        // Symfony as "the --sleep option does not exist", which names the symptom; the remedy is
        // that the host never called configureDaemon() from its own configure()
        $host = new UnconfiguredDaemonHost;

        foreach (['sleep' => $host->readSleepMs(...), 'time-limit' => $host->readTimeLimit(...)] as $option => $read) {
            try {
                $read();
                self::fail(sprintf('an undeclared --%s must be refused by the trait, not by Symfony', $option));
            } catch (InvalidOptionException $e) {
                self::assertStringContainsString('configureDaemon()', $e->getMessage(), $option);
                self::assertInstanceOf(UndeclaredOptionException::class, $e->getPrevious(), 'the raw cause stays reachable for whoever wants it');
            }
        }
    }

    /**
     * @param  callable(): void  $run
     */
    private function elapsedMs(callable $run): float
    {
        $started = hrtime(true);
        $run();

        return (hrtime(true) - $started) / 1_000_000;
    }

    private function term(): int
    {
        return defined('SIGTERM') ? SIGTERM : 15;
    }

    /**
     * A minimal command host that composes the trait; the loop is driven directly. A real relay command
     * wires its own `$this->relay->drain()` tick; here the tick is supplied per test.
     */
    private function host(): DaemonLoopTestHost
    {
        return new DaemonLoopTestHost;
    }
}

/**
 * @internal test fixture for {@see DaemonLoopTest}: a Command that composes {@see DaemonLoop} and exposes
 * its private loop so a test can drive it with a supplied tick
 */
final class DaemonLoopTestHost extends Command
{
    use DaemonLoop;

    protected function configure(): void
    {
        $this->configureDaemon(); // registers --daemon / --sleep / --time-limit on the definition
    }

    /** @param  callable(): int  $tick */
    public function runDaemonLoop(callable $tick, int $sleepMs = 1, int $timeLimit = 0): void
    {
        $this->daemonLoop($tick, $sleepMs, $timeLimit);
    }

    /** @param  array<string, string>  $argv */
    public function readSleepMs(array $argv): int
    {
        return $this->daemonSleepMs(new ArrayInput($argv, $this->getDefinition()));
    }

    /** @param  array<string, string>  $argv */
    public function readTimeLimit(array $argv): int
    {
        return $this->daemonTimeLimit(new ArrayInput($argv, $this->getDefinition()));
    }

    public function runInterruptibleSleep(int $sleepMs, ?int $deadline): void
    {
        $this->interruptibleSleep($sleepMs, $deadline);
    }

    public static function maxSleepMs(): int
    {
        return self::MAX_SLEEP_MS;
    }
}

/**
 * @internal test fixture for {@see DaemonLoopTest}: a Command that composes {@see DaemonLoop} and
 * never calls `configureDaemon()`, the wiring mistake the trait refuses by name
 */
final class UnconfiguredDaemonHost extends Command
{
    use DaemonLoop;

    public function readSleepMs(): int
    {
        return $this->daemonSleepMs(new ArrayInput([], $this->getDefinition()));
    }

    public function readTimeLimit(): int
    {
        return $this->daemonTimeLimit(new ArrayInput([], $this->getDefinition()));
    }
}
