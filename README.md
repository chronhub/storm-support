# Storm Support

The shared **concrete** substrate. Sibling of `Contracts`: where `Contracts` is the pure
shared layer (interfaces expressible in Contracts + SPL + PSR vocabulary only), `Support` is the
concrete shared layer — small, stateless utilities that several packages need and that *cannot*
live in `Contracts`, either because they touch an infra vendor (e.g. Doctrine DBAL) or because
they carry executable logic where `Contracts` holds interfaces only.

It sits at the bottom of the dependency DAG: it depends on **no feature module**, so every module
above may depend on it without a cycle. In deptrac it is `Support: ~` (no Storm-layer dependency);
consumers add `Support` to their own allowlist, case by case — the rules then show exactly who uses
it, rather than implying a blanket dependency.

## Placement rule (to keep this from becoming a junk drawer)

A class belongs in `Support` only when **all** hold:

1. **Shared** — needed by two or more packages (a one-package helper stays in that package).
2. **Concrete, not contractable** — it can't go in `Contracts`, either because it references an
   approved infra vendor (DBAL, Symfony Console) or because it is executable logic with a test,
   where `Contracts` holds pure interfaces only. If it's expressible as an interface in
   Contracts + SPL + PSR vocabulary, it belongs in `Contracts` instead.
3. **No business DECISIONS — mechanisms, and shared policy VOCABULARY.** Purely mechanical helpers
   own the *how* (the loop, the parse, the digest); the *what* (which table, which predicate, which
   retention rule) stays with the caller. One deliberate extension: a **value object naming a
   policy's options** (`OutboxDisposal`) may live here when two modules must share the vocabulary
   without depending on each other — the DECISION (which option, when) still belongs to the caller
   and its config; `Support` never decides, it only names.
4. **References no feature module** — never `use Storm\<feature>\…`. That keeps it at the bottom of
   the DAG.

Group by concern in sub-namespaces (`Support\Dbal\…`).

## Current residents

- `Dbal\BatchedDelete` — the cooperative batched retention delete
  (`ctid IN (… LIMIT n FOR UPDATE SKIP LOCKED)` looped until empty; overlapping prunes claim
  disjoint batches), shared by every prune across Chronicler, AggregateRepository, Saga and
  Telemetry. PostgreSQL-only, like the store it serves.
- `Dbal\SchemaCatalog` + `Dbal\SchemaProbe` — the installed-schema verification pair. The probe is
  the MECHANISM: it interrogates the PostgreSQL catalogs after a package's DDL ran and reports every
  divergence, so an installer either proves its schema or rolls back. The catalog is the DATA the
  caller hands it — which tables, which named constraints, which index shapes — and stays with the
  package that declares it. That is rule 3 applied exactly: the *how* lives here, the *what* does
  not, which is what lets `Ledger` and the opt-in `Saga`, which may not depend on each other, verify
  their schemas with one mechanism.
- `Console\DaemonLoop` — makes a one-shot "drain once and exit" console command an optional
  in-process daemon (`--daemon`/`--sleep`/`--time-limit`, parsed strictly — a malformed value
  refuses loud, never falls back), amortising the framework bootstrap.
- `Console\PositiveIntOption` — strict positive-integer option parsing; rejects what PHP's silent
  `(int)` cast would turn into a hidden 0, and what it would saturate past `PHP_INT_MAX`.
- `Error\AuditDigest` — Throwable → compact persist-safe audit string for `last_error` columns
  (cause chain walked, scrubbed to valid UTF-8, controls neutralised, NUL-stripped, char-capped),
  shared by both outbox relays, the saga TimerRunner and the bundle's SagaCommandFailureListener.
- `Error\TransientFailure` — the MECHANISM of rule 3 again, on the sibling question: does this
  Throwable's cause chain name the infrastructure (a Messenger transport failure or its recoverable
  marker, any Doctrine DBAL failure) rather than the message itself? Shared by both outbox relays,
  which ask it before their own dead-letter gate so a broker outage is never converted into an
  operator's replay. It answers; the *decision* of what to do with the answer, and at which gate,
  stays with each relay. Messenger is an optional companion here, matched by name so the substrate
  keeps no transport of its own.
- `OutboxDisposal` — the shared policy VOCABULARY (rule 3's deliberate extension): what becomes of
  an outbox row at terminal success, `delete` or `archive`, shared by the event and saga outboxes.
  Disposal concerns SUCCESSFUL rows only; failed rows stay hot for forensics, and their later
  cleanup is the separate prune-by-age of the dead-letter tooling.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Experimental 0.x: this package changes without deprecation cycles — no backward-compatibility
promise and no legacy layer. Pin an exact 0.x tag or commit for reproducibility; pinning fixes
history, not a stable API. Schema changes are resets, not migrations, and a reset destroys data,
so it stays on disposable environments.*
