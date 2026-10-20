<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Console;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function array_values;
use function dirname;
use function file_get_contents;
use function implode;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Every console option that names a duration is read through `DurationOption`.
 *
 * An operator then meets one message and one strictness whichever command refuses the value.
 * `DurationOption` is the one console place allowed to call `Duration::fromString()` itself.
 *
 * The walk reads tokens rather than text: a comment or a string naming the call is not a parse, while
 * a qualified or first-class call still is.
 */
final class DurationOptionConventionTest extends TestCase
{
    private const string ALLOWED = '/Support/Console/DurationOption.php';

    #[Test]
    public function no_duration_parsing_outside_the_shared_option(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php'
                || ! str_contains($path, '/Console/')
                || str_contains($path, '/Tests/')
                || $path === $root.self::ALLOWED) {
                continue;
            }

            foreach (self::parseLines((string) file_get_contents($path)) as $line) {
                $offenders[] = sprintf('src/%s:%d', substr($path, strlen($root) + 1), $line);
            }
        }

        self::assertSame([], $offenders, "Duration::fromString is called in a console outside DurationOption; read the option through it instead:\n".implode("\n", $offenders));
    }

    #[Test]
    public function a_call_is_found_through_any_spelling_and_a_mention_is_not(): void
    {
        $source = <<<'PHP'
            <?php
            $a = Duration::fromString('7d');
            $b = \Storm\Clock\Duration::fromString('7d');
            $c = array_map(Duration::fromString(...), []);
            // Duration::fromString('7d')
            $d = 'Duration::fromString';
            $e = Other::fromString('7d');
            $f = $duration->fromString('7d');
            PHP;

        self::assertSame([2, 3, 4], self::parseLines($source));
    }

    /**
     * @return list<int>
     */
    private static function parseLines(string $source): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $lines = [];

        foreach ($tokens as $i => $token) {
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                || ($token->text !== 'Duration' && ! str_ends_with($token->text, '\\Duration'))) {
                continue;
            }

            if (($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON) === true && ($tokens[$i + 2] ?? null)?->text === 'fromString') {
                $lines[] = $token->line;
            }
        }

        return $lines;
    }
}
