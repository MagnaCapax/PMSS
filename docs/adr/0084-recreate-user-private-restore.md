# ADR 0084: Restore recreated homes before ownership handoff

Date: 2026-10-04
Category: security

## Status

Accepted

## Context

`recreateUser.php` handed the rebuilt home to the account before restoring
billing files and customer directories. It copied `data` and `session`, so
both the backup and rebuilt home held those trees during the rebuild. The
restore did not preserve `~/.local/share/pmss`, the customer web share state
defined by ADR 0041.

## Options Considered

- Copy the old trees after ownership handoff: simple ordering, but `data` and
  `session` use space in both homes during the rebuild.
- Move customer trees as the account: avoids duplicate data use, but requires
  separate ordering for the archive and billing identities.
- Move customer trees during one restore phase before ownership handoff: avoids
  duplicate data use and keeps the restore ordering together.

## Decision

Use the private restore phase. The archived home and rebuilt home are root-owned
and mode `0700` while billing identities, `.htpasswd`, `data`, `session`, and
`~/.local/share/pmss` are restored. Only regular root-owned billing sources are
copied; invalid sources stay in the backup and are reported without stopping the
rebuild. Restore destinations and their parent chains must be real directories
or regular files. Customer directories are renamed within `/home` into vacant
destinations. A skeleton directory containing only a regular, non-link `.gitkeep`
is vacant; its placeholder is removed before the rename. Other conflicts stop
the rebuild without overwriting either tree.
Customer configuration overrides remain in the backup and are reported for
manual review. Service configuration runs after the restore handoff. The rebuilt
home remains account-owned while the password command updates `.htpasswd` and
the qBittorrent config with account privileges. The backup stays root-private throughout
the rebuild and is handed back to the account as a top-level `0700` directory
only after the password step succeeds. No later rebuild step reads from it. An
in-script per-account runtime lock prevents two rebuilds from operating on the
same home at once (ADR 0049).
Before backup handling, a private exclusive write probe must succeed in `/home`, the port runtime directory (or its parent before creation), and `/etc`.

## Consequences

- Bulk customer data is moved once and does not occupy both homes.
- A failed restore leaves unmoved content in the private backup. Some
  directories may already be in the rebuilt home; operators inspect both
  homes before a retry.
- After a successful password step, the backup is returned to the account so
  remaining files are available for manual review.
- Configuration helpers run in their existing order after ownership handoff.

## References

- Issues #947 and #948; earlier billing ordering issue #772
- ADR 0041: customer-owned web share state
- ADR 0049: in-script runtime locking
- `docs/contracts.md` and `scripts/lib/user/recreateRestore.php`
