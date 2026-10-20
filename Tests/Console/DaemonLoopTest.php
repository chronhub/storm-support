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
 * The daemon loop the claim-loop commands `saga:relay`, `outbox:relay` and `saga:timers` share.
 *
 * It loops a tick until a signal or the time limit, polling when a pass drained nothing and looping
 * again at once when it did. The time-based exit is not asserted, being non-deterministic and slow;
 * as in `ConsumeBatchedCommandTest`, a signal bounds the loop, since it is the deterministic handle.
 *
 * The slicing of the idle poll is asserted, since a deadline crossing one slice in proves the
 * re-check without paying a poll interval.
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
                // a signal lands during the 3rd pass; inside the loop it defers, false by contract
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

        // one-shot mode: no loop reads the flag, so deferring would absorb the signal and leave Ctrl-C
        // without effect; the handler returns the conventional 128 + signal, like an uninstrumented one
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
        // a silent fallback would replace `--sleep=abc` with 500 and make a broken deployment config
        // look valid; a malformed option is refused, never guessed
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
        // casting `abc` or `-5` to 0 would mean unlimited, so a typo would silently disable recycling,
        // the accumulation failure mode the finite default exists to prevent
        $host = $this->host();

        // unlimited: explicit, the only spelling
        self::assertSame(0, $host->readTimeLimit(['--time-limit' => '0']));
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
        // a deadline can fall mid-poll, and sleeping the interval out would overshoot the budget by up to
        // `--sleep`, paid from the supervisor's grace period; neither call may approach the nominal 5 s
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
        // the trait is public API for app commands; Symfony would report this wiring mistake as "the
        // --sleep option does not exist", the symptom, where the remedy is configureDaemon() in configure()
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

    #[Test]
    public function both_undeclared_daemon_options_are_refused_with_the_same_message(): void
    {
        // one wiring mistake, one remedy: whichever option the host reads first, the refusal reads alike
        $host = new UnconfiguredDaemonHost;
        $messages = [];

        foreach (['sleep' => $host->readSleepMs(...), 'time-limit' => $host->readTimeLimit(...)] as $option => $read) {
            try {
                $read();
                self::fail(sprintf('an undeclared --%s must be refused', $option));
            } catch (InvalidOptionException $e) {
                $messages[$option] = $e->getMessage();
            }
        }

        $remedy = "The daemon options are not declared: call configureDaemon() from the host command's configure().";
        self::assertSame(['sleep' => $remedy, 'time-limit' => $remedy], $messages);
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
