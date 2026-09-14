<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Tests\Fixture\DestructiveFixtureCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class DestructiveConfirmationTest extends TestCase
{
    #[Test]
    #[DataProvider('answers')]
    public function interactive_confirmation_controls_whether_the_operation_runs(string $answer, bool $proceeds): void
    {
        $tester = new CommandTester($this->command());
        $tester->setInputs([$answer]);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('Remove the fixture?', $tester->getDisplay());
        self::assertSame($proceeds, str_contains($tester->getDisplay(), 'operation reached'));
        if (! $proceeds) {
            self::assertStringContainsString('Aborted', $tester->getDisplay());
            self::assertStringContainsString('nothing was destroyed.', $tester->getDisplay());
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function answers(): array
    {
        return ['yes' => ['yes', true], 'no' => ['no', false], 'default' => ['', false]];
    }

    #[Test]
    public function non_interactive_execution_requires_force(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));
        self::assertStringNotContainsString('operation reached', $tester->getDisplay());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('The fixture will be removed.', $display);
        self::assertStringContainsString('pass --force to confirm.', $display);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true], ['interactive' => false]));
        self::assertStringContainsString('operation reached', $tester->getDisplay());
        self::assertStringNotContainsString('Remove the fixture?', $tester->getDisplay());
    }

    private function command(): Command
    {
        return new class() extends DestructiveFixtureCommand
        {
            protected function configure(): void
            {
                $this->configureForce('the fixture is removed');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);
                $status = $this->confirmDestructive($io, $input, 'Remove the fixture?', 'The fixture will be removed.');

                if ($status !== null) {
                    return $status;
                }

                $io->writeln('operation reached');

                return Command::SUCCESS;
            }
        };
    }
}
