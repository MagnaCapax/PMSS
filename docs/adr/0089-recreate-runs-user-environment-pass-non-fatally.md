# ADR 0089: Run the user environment pass after recreate handback

Date: 2026-10-07
Category: architecture

## Status

Accepted

## Context

`recreateUser.php` did not run `pmssUpdateUserEnvironment` (issue #1031).
Theme refresh, ruTorrent upgrade, ruTorrent PHP compatibility, and plugin
handlers therefore waited until the next node update. ADR 0084 requires the
backup to remain root-private until the password step succeeds.

## Options Considered

- Leave the environment pass to the next node update: preserves the existing
  rebuild flow but delays per-user convergence.
- Run the pass before the password step and abort on failure, as `addUser.php`
  does: converges earlier but can strand a rebuilt account with an unchanged
  password and a root-private backup.
- Run the pass after the password step and backup handback, and treat a false
  result as non-fatal: converges immediately when possible without stranding
  an otherwise working account.

## Decision

Run `pmssUpdateUserEnvironment` after a successful password step and backup
handback. Log a false result, including its reason, to STDERR and the user log,
then continue the rebuild. Recreate's own steps already produce a working
account. The node update likewise treats an environment failure as a
per-account skip rather than aborting all user maintenance.

## Consequences

- A successful recreate refreshes themes, ruTorrent, PHP compatibility, and
  plugins without waiting for the next node update.
- A failed environment pass is logged; the next PMSS update converges the
  account.

## References

- Issue #1031
- ADR 0084: restore recreated homes before ownership handoff
