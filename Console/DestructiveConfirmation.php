<?php

declare(strict_types=1);

namespace Storm\Support\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The deliberation gate a destructive verb owes its operator, in one place so every verb asks the same
 * way.
 *
 * Three outcomes, and each answers a different question. `--force` means the caller already decided,
 * typically a runbook, and nothing is asked. A non-interactive session without it is refused as a
 * malformed invocation, since there is nobody to answer and an unattended erase is exactly what the
 * gate exists to stop. An interactive session is asked, defaulting to no, and a decline is a SUCCESS:
 * the run did what it was told, which was nothing, so a chained command behind it may keep going.
 *
 * The prompt names the target and the consequence rather than asking for a blanket yes. What earns the
 * gate is almost never the deliberate erase; it is the typo that reaches a valid name.
 *
 * Host wiring: call `configureForce()` from `configure()`, then `confirmDestructive()` from `execute()`
 * before the first irreversible call, and return its value when it is not null.
 *
 * @see DaemonLoop for the same host-trait shape on the daemon options
 */
trait DestructiveConfirmation
{
    /**
     * Register `--force` on the host command; call from its `configure()`.
     *
     * @param  string  $because  why the confirmation exists, completing "required in a non-interactive
     *                           session, since ..."
     */
    protected function configureForce(string $because): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            sprintf('Skip the confirmation; required in a non-interactive session, since %s.', $because),
        );
    }

    /**
     * Clear the gate, or produce the exit code the host must return.
     *
     * @param  string  $question  the prompt, naming the target; answered no by default
     * @param  string  $consequence  what the run destroys, rendered in the refusal and the prompt
     * @return int|null null when the caller may proceed, otherwise the exit code to return as is
     */
    protected function confirmDestructive(SymfonyStyle $io, InputInterface $input, string $question, string $consequence): ?int
    {
        if ((bool) $input->getOption('force')) {
            return null;
        }

        if (! $input->isInteractive()) {
            $io->error(sprintf('%s The session is non-interactive: pass --force to confirm.', $consequence));

            return Command::INVALID;
        }

        if (! $io->confirm(sprintf('%s %s', $question, $consequence), false)) {
            $io->warning('Aborted — nothing was destroyed.');

            return Command::SUCCESS;
        }

        return null;
    }
}
