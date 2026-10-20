<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Random;

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
use function ltrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Every random draw in the framework goes through the `Jitter` port.
 *
 * The range a backoff asks for then stays observable to a test. `NativeJitter` is the one place
 * allowed to call the source itself.
 *
 * The walk reads tokens rather than text: a comment or a string naming the function is not a draw,
 * while a fully qualified, spaced or first-class call still is.
 */
final class RandomSourceConventionTest extends TestCase
{
    private const string ALLOWED = '/Support/Random/';

    #[Test]
    public function no_random_int_outside_support_random(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_starts_with($path, $root.self::ALLOWED)) {
                continue;
            }

            foreach (self::drawLines((string) file_get_contents($path)) as $line) {
                $offenders[] = sprintf('src/%s:%d', substr($path, strlen($root) + 1), $line);
            }
        }

        self::assertSame([], $offenders, "random_int is called outside src/Support/Random; draw through the Jitter port instead:\n".implode("\n", $offenders));
    }

    #[Test]
    public function a_call_is_found_through_any_spelling_and_a_mention_is_not(): void
    {
        $source = <<<'PHP'
            <?php
            $a = random_int(1, 2);
            $b = \Random_Int (1, 2);
            $c = array_map(random_int(...), []);
            // random_int(1, 2)
            $d = 'random_int(1, 2)';
            $e = $draw->random_int(1, 2);
            use function random_int;
            PHP;

        self::assertSame([2, 3, 4], self::drawLines($source));
    }

    /**
     * @return list<int>
     */
    private static function drawLines(string $source): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $lines = [];

        foreach ($tokens as $i => $token) {
            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || strtolower(ltrim($token->text, '\\')) !== 'random_int') {
                continue;
            }

            // a method or a declaration of that name is not the global function
            $previous = $tokens[$i - 1] ?? null;
            if (($tokens[$i + 1] ?? null)?->text === '(' && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
                $lines[] = $token->line;
            }
        }

        return $lines;
    }
}
