<?php

declare(strict_types=1);

namespace Storm\Support\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;

/**
 * The batched-delete primitive shared by the framework's retention prunes: Chronicler inbox/outbox,
 * aggregate snapshots, saga bookkeeping, telemetry history. Deletes the rows matching `$predicate` in
 * capped batches via `ctid IN (SELECT ctid … LIMIT n FOR UPDATE SKIP LOCKED)`, looping until none
 * remain, so a large prune is many short statements rather than one long lock. PostgreSQL-only by
 * construction through `ctid` and `SKIP LOCKED`; the store it serves is too.
 *
 * CONCURRENT prunes are cooperative, not blocking: `SKIP LOCKED` makes overlapping runs claim
 * disjoint batches instead of queueing on the same ctids. A run that waited out its neighbor
 * instead would read the post-commit `0 affected` as "nothing left" and abandon the remaining
 * eligible rows. Consequence to know: the RETURN VALUE is the rows THIS run deleted; under overlap
 * the policy's total is the sum across runs, and the last runner leaves the table clean.
 *
 * The caller owns the table and the predicate, its retention rule being the domain part; this owns only
 * the lock-avoiding loop, the one mechanical part worth writing and testing once. `$table` and
 * `$predicate` are BOTH trusted, static SQL authored by the calling store: every runtime value must
 * be a bound `$bindings` parameter, never interpolated; the table is additionally gated to a plain
 * identifier at runtime, so the trust is checkable rather than assumed.
 *
 * NEVER against a partitioned parent, and the guard makes the rule unreachable to break: `ctid` is
 * a physical address WITHIN one relation, partitions collide freely on it, and the outer `DELETE`,
 * whose only predicate is the ctid set, would remove homonymous rows from SIBLING partitions,
 * silent data loss rather than an error. A partitioned table prunes per partition, or with a
 * predicate-scoped `DELETE`.
 *
 * Stateless on purpose: the caller already holds the connection with its own transactional context, so
 * this stays a pure function rather than a wired service.
 */
final class BatchedDelete
{
    /**
     * @param  string  $predicate  the `WHERE` body without the `WHERE` keyword, e.g. `archived_at < :age`.
     *                             It runs against the table unaliased, so qualify by the real table name when needed,
     *                             for example `NOT EXISTS (… WHERE h.stream = snapshots.stream)`, never a custom alias.
     * @param  array<string, mixed>  $bindings  values bound into `$predicate`
     * @param  int  $batch  rows deleted per statement, the lock cap; guarded at runtime, since a PHPDoc
     *                      annotation is no boundary for a public helper that builds SQL, and an
     *                      unguarded 0 would issue `LIMIT 0`, end the loop on its first pass and
     *                      report a clean prune with every eligible row still there
     *
     * @throws InvalidArgumentException when `$batch` is not strictly positive, `$table` is not a
     *                                  plain identifier, or `$table` names a partitioned parent;
     *                                  caller bugs, each refused before any row is touched
     * @throws Exception on a DBAL delete failure
     */
    public static function run(Connection $connection, string $table, string $predicate, array $bindings, int $batch): int
    {
        if ($batch < 1) {
            throw new InvalidArgumentException(sprintf('The delete batch must be strictly positive, got %d.', $batch));
        }

        if (preg_match('/^[a-z_][a-z0-9_]*\z/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf("The delete table must be a plain identifier, got '%s'.", addcslashes($table, "\0..\37\177")));
        }

        // one catalog read per prune, and the ctid trap becomes unreachable instead of documented
        $relkind = $connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relname = :table',
            ['table' => $table],
        );

        if ($relkind === 'p') {
            throw new InvalidArgumentException(sprintf(
                'BatchedDelete refuses the partitioned parent %s: ctid is per-partition, so the outer DELETE would remove unrelated rows from sibling partitions. Prune each partition, or use a predicate-scoped DELETE.',
                $table,
            ));
        }

        $total = 0;

        do {
            $deleted = (int) $connection->executeStatement(
                sprintf('DELETE FROM %1$s WHERE ctid IN (SELECT ctid FROM %1$s WHERE %2$s LIMIT %3$d FOR UPDATE SKIP LOCKED)', $table, $predicate, $batch),
                $bindings,
            );
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
