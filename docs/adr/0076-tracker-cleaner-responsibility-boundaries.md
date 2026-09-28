# ADR 0076: Separate tracker-cleaner policy, logs, backups, and run flow

Date: 2026-09-28
Category: architecture

## Status

Accepted

## Context

`trackerCleaner.php` held tracker selection, torrent mutation, backup safety,
logging, and the bounded per-user run in one 431-line library. The cron and
tests load that library as one facade. A one-use session-plan helper also
carried five fields between adjacent parts of the run function.

## Options Considered

- Keep the combined library: no load-order change, but unrelated concerns stay coupled.
- Create separate cron implementations: adds parallel behavior and a migration burden.
- Keep the facade and move each responsibility into one module (chosen).

## Decision

`trackerCleaner.php` loads `trackerCleaner/{policy,log,backup,run}.php` in
dependency order. The public function names and cron entrypoint stay intact.
The one-use session-plan helper is removed; the run checks skip reasons in
the same priority order and builds the same log lines locally.

## Consequences

- Each module has one purpose and stays below 150 lines.
- The run has no intermediate session-plan result or duplicate session paths.
- The existing backup, private-torrent, limits, and log contracts stay in place.
  A scrub-result snapshot and the development suite check the preserved behavior.
- No deployed state or customer migration is needed.

## References

- [Tracker cleaner](../tracker-cleaner.md)
- [Shell command guardrails](0004-shell-command-guardrails.md)
