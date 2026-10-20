<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

/**
 * The rule by which a live column shape meets the fragment a {@see SchemaCatalog} declares for it.
 *
 * `SchemaDiff` composes the live shape as `format_type`, then ` not null` or ` null`, then
 * ` collate <name>` when the column leaves the database default.
 *
 * A fragment must start that shape and end on a word, so `bigint` pins the type alone and
 * `text not null` leaves the collation to the deployment, while `bigint[]`, or a type whose name only
 * ends like the declared one, fails. A fragment that names a collation is the whole shape and must
 * equal it, or `collate C.utf8`, or any name that starts like the declared one, would pass for
 * `collate C`.
 */
final class ColumnShape
{
    public static function holds(string $live, string $fragment): bool
    {
        return str_contains($fragment, 'collate ')
            ? $live === $fragment
            : str_starts_with($live.' ', $fragment.' ');
    }
}
