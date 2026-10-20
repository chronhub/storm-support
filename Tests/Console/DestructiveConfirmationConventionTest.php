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
use function in_array;
use function sprintf;
use function str_contains;
use function strlen;
use function substr;

/**
 * Every `--force` that confirms a destructive verb goes through `DestructiveConfirmation`.
 *
 * Each such verb then refuses, asks and declines the same way, and a `--json` caller is never
 * prompted.
 *
 * A gate is recognized by what it does rather than by how its option is declared: a file that asks
 * `isInteractive()` and reads `getOption('force')` hand-rolls one, which only the trait may do. The
 * Maker commands are exempt by directory: their `--force` overwrites a generated file, another
 * meaning, and one of them asks `isInteractive()` for a prompt of its own.
 */
final class DestructiveConfirmationConventionTest extends TestCase
{
    private const string TRAIT = '/Support/Console/DestructiveConfirmation.php';

    private const string MAKER = '/Symfony/Maker/';

    #[Test]
    public function every_force_confirmation_composes_the_shared_gate(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php'
                || str_contains($path, '/Tests/')
                || str_contains($path, self::MAKER)
                || $path === $root.self::TRAIT) {
                continue;
            }

            if (self::handRollsAGate((string) file_get_contents($path))) {
                $offenders[] = sprintf('src/%s', substr($path, strlen($root) + 1));
            }
        }

        self::assertSame([], $offenders, "A --force confirmation is recoded outside DestructiveConfirmation; compose configureForce() and confirmDestructive() instead:\n".implode("\n", $offenders));
    }

    #[Test]
    public function a_gate_needs_both_calls_and_a_mention_is_not_one(): void
    {
        self::assertTrue(self::handRollsAGate(<<<'PHP'
            <?php
            if (! $input->getOption('force') && ! $input->isInteractive()) {}
            PHP));
        self::assertTrue(self::handRollsAGate(<<<'PHP'
            <?php
            $force = $input?->getOption("force");
            $asking = $input?->isInteractive();
            PHP));

        // one call alone is no gate: the other meanings of --force, and a prompt of its own
        self::assertFalse(self::handRollsAGate("<?php\n\$overwrite = \$input->getOption('force');"));
        self::assertFalse(self::handRollsAGate("<?php\nif (\$input->isInteractive()) {}\n\$json = \$input->getOption('json');"));
        // a mention in a comment or a string is not a call
        self::assertFalse(self::handRollsAGate("<?php\n// \$input->isInteractive() and \$input->getOption('force')\n\$a = 'isInteractive';"));
    }

    private static function handRollsAGate(string $source): bool
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $asksInteractive = false;
        $readsForce = false;

        foreach ($tokens as $i => $token) {
            if (! $token->is(T_STRING) || ! in_array(($tokens[$i - 1] ?? null)?->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                || ($tokens[$i + 1] ?? null)?->text !== '(') {
                continue;
            }

            if ($token->text === 'isInteractive') {
                $asksInteractive = true;
            } elseif ($token->text === 'getOption' && in_array(($tokens[$i + 2] ?? null)?->text, ["'force'", '"force"'], true)) {
                $readsForce = true;
            }
        }

        return $asksInteractive && $readsForce;
    }
}
