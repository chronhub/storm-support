<?php

declare(strict_types=1);

namespace Storm\Support\Console;

use Storm\Clock\Duration;
use Storm\Text\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Reads a console option that names a duration, with one message and one strictness for every command.
 *
 * `Duration::fromString()` stays the one parser; this class decides what an absent value means and
 * what the operator reads.
 *
 * A refusal goes to the error output, so a command that also prints a document keeps its stdout
 * parsable, and the caller returns the exit code it receives as is, the shape of
 * `DestructiveConfirmation`. An option required unless another is given composes `optional()` with
 * its own test of that other option.
 */
final class DurationOption
{
    private const string FORMS = 'an age such as 30d, 48h or 90m (d=days, h=hours, m=minutes)';

    /**
     * The duration a required option names, or `Command::INVALID` once its refusal is written.
     *
     * Absent, empty or malformed, the option is refused.
     */
    public static function required(InputInterface $input, SymfonyStyle $io, string $name): Duration|int
    {
        $raw = $input->getOption($name);

        if (! is_string($raw) || $raw === '') {
            $io->getErrorStyle()->error(sprintf('Specify --%s with %s.', $name, self::FORMS));

            return Command::INVALID;
        }

        return self::parse($io, $name, $raw);
    }

    /**
     * The duration an optional option names, null when unset, or `Command::INVALID` once refused.
     *
     * Absent or empty reads as unset. A malformed value is refused rather than read as unset: a
     * filter that cannot be parsed must not quietly become no filter at all.
     */
    public static function optional(InputInterface $input, SymfonyStyle $io, string $name): Duration|int|null
    {
        $raw = $input->getOption($name);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return self::parse($io, $name, $raw);
    }

    private static function parse(SymfonyStyle $io, string $name, string $raw): Duration|int
    {
        $duration = Duration::fromString($raw);

        if ($duration === null) {
            $io->getErrorStyle()->error(sprintf('Malformed --%s "%s": specify %s.', $name, Str::printable($raw), self::FORMS));

            return Command::INVALID;
        }

        return $duration;
    }
}
