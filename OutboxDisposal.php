<?php

declare(strict_types=1);

namespace Storm\Support;

/**
 * What becomes of an outbox row once its message is successfully published; the shared policy
 * VOCABULARY between the event outbox `es_outbox` in chronicler and the saga command outbox
 * `workflow_outbox` in saga. Support names the options; the DECISION stays with the caller and its
 * config. Failed rows are never subject to disposal: they stay in the hot table for forensics and
 * replay by the dead-letter tooling, whatever the mode; their eventual cleanup is the prune-by-age
 * of that tooling, a separate concern.
 *
 * - `Delete`, the default: a queue table is not a ledger. The durable record lives elsewhere, the
 *   event in `event_store`, the saga's steps in its history sink, consumption proof in `es_inbox`,
 *   so the row dies at terminal success. The hot table stays tiny, which is what keeps autovacuum
 *   ahead of the pending-index graveyard during a flood and the relay's own poll cheap.
 *
 * - `Archive`: the row moves out of the hot path to an append-only `*_archive` sibling, via one
 *   atomic `DELETE … RETURNING` feeding an `INSERT` at the end of the drain batch. This is the
 *   delivery audit for devs who want one, at the cost of the appending write. The hot table stays
 *   exactly as tiny as with `Delete`; the intra-flood win is identical, only the storage story
 *   differs. The archive has its own retention, the prune commands sweeping it by age.
 *
 * String-backed so the value binds straight from bundle config `storm.outbox.disposal` and
 * `storm.saga.command_outbox.disposal`.
 */
enum OutboxDisposal: string
{
    case Delete = 'delete';
    case Archive = 'archive';
}
