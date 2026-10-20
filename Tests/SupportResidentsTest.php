<?php

declare(strict_types=1);

namespace Storm\Support\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The package README is the placement record of `Support`, and every resident must be listed there.
 *
 * Each entry gives the resident's reason to live in the shared substrate, so a class that moves in
 * without one is a placement nobody argued. The residents are discovered from the filesystem, so a new
 * one is seen without anyone remembering to look, and each must be named between backticks by its
 * name under `Storm\Support`.
 */
final class SupportResidentsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function residents(): iterable
    {
        $root = dirname(__DIR__);
        $residents = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_starts_with($path, $root.'/Tests/')) {
                continue;
            }

            $residents[] = str_replace('/', '\\', substr($path, strlen($root) + 1, -4));
        }
        sort($residents);

        foreach ($residents as $resident) {
            yield $resident => [$resident];
        }
    }

    #[Test]
    #[DataProvider('residents')]
    public function readme_names_every_resident(string $resident): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__).'/README.md');

        self::assertStringContainsString('`'.$resident.'`', $readme, sprintf(
            'src/Support/README.md does not name `%s`; every resident is listed by its name under Storm\Support, with the reason it lives in the shared substrate',
            $resident,
        ));
    }
}
