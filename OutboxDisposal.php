<?php

declare(strict_types=1);

namespace Storm\Support;

/**
 * What becomes of an outbox row once its message is published successfully.
 *
 * It is the policy vocabulary that the event outbox `es_outbox` of Chronicler and the saga command
 * outbox `workflow_outbox` share. `Support` names the options; the decision stays with the caller and
 * its config. Failed rows are never disposed of: whatever the mode, they stay in the hot table for
 * forensics and replay by the dead-letter tooling, whose prune by age cleans them up separately.
 *
 * - `Delete`, the default, removes the row at terminal success, since a queue table is no ledger and
 *   the durable record lives elsewhere: the event in `event_store`, the saga's steps in its history
 *   sink, the consumption proof in `es_inbox`. The hot table stays tiny, which keeps autovacuum ahead
 *   of the dead entries a flood leaves in the pending index, and the relay's own poll cheap.
 *
 * - `Archive` moves the row out of the hot path to an append-only `*_archive` sibling, through one
 *   atomic `DELETE … RETURNING` feeding an `INSERT` at the end of the drain batch. It is the delivery
 *   audit for developers who want one, at the cost of the appending write. The hot table stays as
 *   tiny as with `Delete`, with the same gain during a flood; only the storage differs. The archive
 *   has its own retention, which the prune commands sweep by age.
 *
 * It is string-backed so the value binds straight from the bundle config keys
 * `storm.outbox.disposal` and `storm.saga.command_outbox.disposal`.
 */
enum OutboxDisposal: string
{
    case Delete = 'delete';
    case Archive = 'archive';
}
