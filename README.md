# Storm Support

The shared **concrete** substrate. Sibling of `Contracts`: where `Contracts` is the pure
shared layer (interfaces expressible in Contracts + SPL + PSR vocabulary only), `Support` is the
concrete shared layer — small, stateless utilities that several packages need and that *cannot*
live in `Contracts`, either because they touch an infra vendor (e.g. Doctrine DBAL) or because
they carry executable logic where `Contracts` holds interfaces only.

It sits at the bottom of the dependency DAG: it depends on **no feature module**, only on the
`Contracts` ports it implements, the `Text` string rules it applies and the `Clock` duration it
reads, none of which depends on a feature module either, so every module above may depend on it
without a cycle. In deptrac it is `Support: [Contracts, Clock, Text]`; consumers add `Support` to
their own allowlist, case by case — the
rules then show exactly who uses it, rather than implying a blanket dependency.

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

Group by concern in sub-namespaces (`Support\Dbal\…`). A value object of shared policy VOCABULARY,
the extension of rule 3, sits at the module root instead, since it belongs to no technical concern:
`OutboxDisposal` is the case.

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
- `Dbal\SchemaDiff` — the verdict half of the probe: the rows the probe read, set against the
  catalog and rendered as the problems an installer reports, in a fixed order. Pure, so every
  comparison stays in the mutation field, while the probe keeps only its queries, the `search_path`
  restore, and its `@infection-ignore-all`.
- `Dbal\ColumnShape` + `Dbal\ConstraintShape` — the two comparison rules the diff applies, pure
  functions kept outside the probe's `@infection-ignore-all`: a column fragment naming a collation
  equals the live shape, any other opens it; a constraint needle opening on its keyword equals the
  live definition, any other is contained in it.
- `Dbal\InstallLock` — the installers' advisory lock, taken inside the caller's transaction or
  refused, within a few bounded attempts under a `lock_timeout`, so two installs of one schema never
  interleave; shared by `storm:install`, `storm:saga:install` and `storm:telemetry:install`.
- `Console\DaemonLoop` — makes a one-shot "drain once and exit" console command an optional
  in-process daemon (`--daemon`/`--sleep`/`--time-limit`, parsed strictly — a malformed value
  refuses loud, never falls back), amortising the framework bootstrap.
- `Console\GracefulSignals` — the SIGTERM/SIGINT stop of a looping command: a signal waits for the
  unit in flight to commit while a loop reads the flag, and exits at once when none does; composed
  by `DaemonLoop`, `storm:bus:consume-batched` and `storm:projection:run`.
- `Console\PositiveIntOption` — strict positive-integer option parsing; rejects what PHP's silent
  `(int)` cast would turn into a hidden 0, and what it would saturate past `PHP_INT_MAX`.
- `Console\TimeLimitOption` — the strict `--time-limit` parse: `0` means unlimited, any other value
  is a positive number of seconds up to a year; read by `DaemonLoop`, `storm:projection:run` and
  `storm:bus:consume-batched`.
- `Console\DestructiveConfirmation` — the host trait a destructive console command clears before its
  first irreversible call: `--force` skips the question, a non-interactive session without it is
  refused, an interactive one is asked and defaults to no, and a decline exits as a success. A host
  that declares `--json` and receives it counts as non-interactive, and the refusal goes to the
  error output so the document on stdout stays parsable.
- `Console\DurationOption` — reads a console option naming a duration through `Clock\Duration`,
  required or optional, so every command refuses a bad one with the same message on the error
  output; an optional one that cannot be parsed is refused, never read as no filter at all.
- `Error\AuditDigest` — Throwable → compact persist-safe audit string for `last_error` columns
  (cause chain walked, scrubbed to valid UTF-8, controls neutralised, NUL-stripped, char-capped),
  shared by both outbox relays, the saga TimerRunner and the bundle's SagaCommandFailureListener.
- `Error\TransientFailure` — the MECHANISM of rule 3 again, on the sibling question: does this
  Throwable's cause chain name an outage (a Messenger transport failure or its recoverable marker,
  a Doctrine DBAL failure that clears by itself or by deployment, recognized by class or SQLSTATE;
  any other DBAL failure stays poison) rather than the message itself? Shared by both outbox relays,
  which ask it before their own dead-letter gate so a broker outage is never converted into an
  operator's replay. It answers; the *decision* of what to do with the answer, and at which gate,
  stays with each relay. Messenger is an optional companion here, matched by name so the substrate
  keeps no transport of its own.
- `Random\NativeJitter` — the production draw behind the `Contracts` `Jitter` port: a uniform
  `random_int` between the bounds a backoff asks for. The saga activity runner, both outbox relays,
  the event store's retry decorator, the concurrency-conflict middleware and the projection batch
  executor take the port, defaulting to this draw, so a test reads the range they ask for and
  answers either end of it instead of sampling. `RandomSourceConventionTest` refuses a `random_int`
  call anywhere else in `src`.
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
