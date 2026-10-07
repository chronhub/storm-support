<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

use function array_any;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * The rule by which a live constraint definition meets the needle a {@see SchemaCatalog} declares.
 *
 * `pg_get_constraintdef` renders the whole definition. A needle that opens on the keyword of a
 * complete definition IS that definition and must equal it, since containment would let
 * `CHECK (((version > 0) OR true))` pass for `CHECK ((version > 0))`. The keywords are:
 *
 * - `CHECK `
 * - `PRIMARY KEY `
 * - `UNIQUE `
 * - `FOREIGN KEY `
 * - `EXCLUDE `
 *
 * Any other needle is a fragment the definition must contain, one value of a status list for
 * instance, whose full rendering follows an enumeration the catalog does not repeat.
 */
final class ConstraintShape
{
    private const array COMPLETE = ['CHECK ', 'PRIMARY KEY ', 'UNIQUE ', 'FOREIGN KEY ', 'EXCLUDE '];

    /**
     * The divergence to report, or null when the live definition meets the needle.
     */
    public static function divergence(string $table, string $name, string $definition, string $needle): ?string
    {
        if (array_any(self::COMPLETE, static fn (string $keyword): bool => str_starts_with($needle, $keyword))) {
            return $definition === $needle ? null : sprintf(
                "constraint %s on %s: definition is '%s', not '%s' — a pre-existing constraint with this name is incompatible; the installer creates, it does not migrate",
                $name,
                $table,
                $definition,
                $needle,
            );
        }

        return str_contains($definition, $needle) ? null : sprintf(
            "constraint %s on %s: definition lost '%s' — a pre-existing constraint with this name is incompatible; the installer creates, it does not migrate",
            $name,
            $table,
            $needle,
        );
    }
}
