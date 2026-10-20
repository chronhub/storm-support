<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Console\DurationOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function is_int;
use function preg_replace;

final class DurationOptionTest extends TestCase
{
    private const string FORMS = 'an age such as 30d, 48h or 90m (d=days, h=hours, m=minutes).';

    #[Test]
    #[DataProvider('forms')]
    public function a_well_formed_duration_returns_its_seconds(bool $optional): void
    {
        $tester = $this->runFixture($optional, ['--before' => '48h']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("seconds 172800\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    /**
     * @param  array<string, string>  $input
     */
    #[Test]
    #[DataProvider('unset')]
    public function an_unset_required_duration_is_refused_on_the_error_output(array $input): void
    {
        $tester = $this->runFixture(false, $input);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Specify --before with '.self::FORMS, self::flat($tester->getErrorOutput()));
    }

    /**
     * @param  array<string, string>  $input
     */
    #[Test]
    #[DataProvider('unset')]
    public function an_unset_optional_duration_reads_as_no_filter(array $input): void
    {
        $tester = $this->runFixture(true, $input);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("unset\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    #[Test]
    #[DataProvider('malformed')]
    public function a_malformed_duration_is_refused_with_the_shared_message(bool $optional, string $value): void
    {
        // the optional form refuses too: a filter that cannot be parsed must not quietly become no
        // filter at all, and both forms say it in the same words
        $tester = $this->runFixture($optional, ['--before' => $value]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Malformed --before "'.$value.'": specify '.self::FORMS, self::flat($tester->getErrorOutput()));
    }

    #[Test]
    public function a_refused_value_is_echoed_printable(): void
    {
        $tester = $this->runFixture(true, ['--before' => "7\e[2Jd"]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Malformed --before "7\x1B[2Jd"', self::flat($tester->getErrorOutput()));
        self::assertStringNotContainsString("\e", $tester->getErrorOutput());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function forms(): iterable
    {
        yield 'required' => [false];
        yield 'optional' => [true];
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unset(): iterable
    {
        yield 'absent' => [[]];
        yield 'empty' => [['--before' => '']];
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function malformed(): iterable
    {
        foreach (['required' => false, 'optional' => true] as $form => $optional) {
            yield $form.', no unit' => [$optional, '30'];
            yield $form.', spelled out' => [$optional, '1 hour'];
            yield $form.', zero' => [$optional, '0d'];
        }
    }

    /**
     * @param  array<string, string>  $input
     */
    private function runFixture(bool $optional, array $input): CommandTester
    {
        $tester = new CommandTester(new class($optional) extends Command
        {
            public function __construct(private readonly bool $optional)
            {
                parent::__construct('fixture:duration');
            }

            protected function configure(): void
            {
                $this->addOption('before', null, InputOption::VALUE_REQUIRED, 'An age');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);
                $duration = $this->optional
                    ? DurationOption::optional($input, $io, 'before')
                    : DurationOption::required($input, $io, 'before');

                if (is_int($duration)) {
                    return $duration;
                }

                $output->writeln($duration === null ? 'unset' : 'seconds '.$duration->seconds);

                return Command::SUCCESS;
            }
        });

        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private static function flat(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }
}
