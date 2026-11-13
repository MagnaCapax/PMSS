# ADR 0279: Scheduled configuration backup responsibility boundaries

Date: 2026-11-13
Category: architecture

## Status
Accepted

## Context

The scheduled-backup controller combines a customer PHP program, command building
and capture, JSON result decoding, archive verification, per-account sequencing,
queue reporting and CLI dispatch. PHP binary selection, local include resolution
and process capture each have a forwarding helper with one caller and no other
repository references. These extra boundaries obscure the command's complete
validation and execution path.

The root cron consumes exit status and operator logs; the customer helper returns
one JSON line. Retention must follow successful creation and root verification of
a nonempty archive inside the same home. ADR 0200 governs the separate customer
archive transaction and its private file modes.

## Options Considered

- Keep the combined controller and its forwarding helpers.
- Introduce transport classes or an action registry, adding dispatch concepts.
- Separate command/protocol handling and account policy behind the existing
  include, placing single-use operations directly at their consumers.

## Decision

Keep `scheduledConfigBackup.php` as the queue/CLI facade.
`scheduledConfigBackupCommand.php` owns the customer program, command construction,
bounded process capture and result decoding. `scheduledConfigBackupAccount.php`
owns archive verification, opt-in policy and create/verify/prune ordering.

Inline PHP binary selection and include resolution into command construction,
and process capture into the customer action. Retain all original guards,
short-circuit order, arguments, exception boundaries and remaining helper names.
No parallel command path or replacement archive implementation is introduced.

## Consequences

- Thirteen functions become ten across three focused modules. Counting the two
  new module boundaries against the three removed helper boundaries still gives
  one fewer structural concept. Runtime source shrinks from 226 to 221 lines.
- Command bytes, PHP fallback order, timeout and capture settings, JSON field
  order, last-line selection, diagnostic compaction, archive checks and queue
  reporting retain their contracts. Tests use injected captures and temporary
  account trees; the native capture trace substitutes every process operation.
- The characterization can run against the original facade on the testing VM.
  No test or customer command executes on the devbox; only changed PHP syntax
  checks and reference/diff inspection run here.

Security review retains identity validation, local include containment, account
execution and archive guards. Backups can expose private tracker URLs and separate
application credentials, as recorded in ADR 0200; this restructuring changes no
primary/service credential separation, secret logging, permissions, routes or
listeners. PHP's CVE-2026-6103 documents TAR parser disagreement and injected
entries, reinforcing the existing archive boundary. This change neither parses
nor extracts archives and preserves the customer-owned archive implementation.

## References

- [Customer archive transaction and security analysis](0200-customer-backup-archive-transaction.md)
- [Customer backup contract](../contracts.md#customer-torrent-state-backup--etcskelwwwscriptsincbackupphp-and-welcomephp)
- [PHP TAR entry-injection advisory, CVE-2026-6103](https://github.com/php/php-src/security/advisories/GHSA-j3wh-g957-2m85)
