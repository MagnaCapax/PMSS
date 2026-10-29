# ADR 0207: Accounts that cannot take a managed file update receive it through the root stage

Date: 2026-10-29
Category: architecture

## Status

Accepted

## Context

#1162: since 03f7c53c / ADR 0121, skeleton replacements run as the account. Accounts over
hard quota, over soft quota with expired grace, or on a full `/home` cannot receive panel
updates. The remote on-load heal requires 150 KB free and skips these accounts too.
Partial delivery mixes new callers with old helpers and can leave the panel unusable.

## Decision

Keep the account writer first, including its literal mode 0755 and legacy-owner repair.
Only after it fails, measure eligibility from root-owned observations:

- Resolve the mount holding `PMSS_HOME_DIR` (default `/home`) with `stat -c %m`.
  Run `repquota -u -n` once per process, cache failures too, and parse stdout through
  `pmssQuotaSnapshotParseRepquotaUserRows()`. Select the passwd UID's row. Required KiB
  is `ceil(content bytes / 1024) + 1`; a positive hard limit below used plus required,
  or a positive soft limit at or below used, authorizes the quota fallback.
- Otherwise, ordinary available space below content bytes plus 64 KiB authorizes the
  full-home fallback. Unknown quota state only permits this space test.
- Every candidate requires root-available free blocks (`stat -f -c '%f %S'` on the
  home root) above content bytes plus 256 MiB. A low or unknown reserve logs a skip.

Publish the same managed skeleton set through `pmssRootStageFileWrite()` as
`root:<account primary group>` 0755, without immutability. Never parse account stderr,
retry, write in place, or chown/chmod customer paths in the delivery helper. Log each
root-stage result and one per-user delivery/failure summary after skeleton maintenance.

The existing final `pmssUserRefreshPermissions()` hands files back to the account.
Running as root, this charges the files to its user quota even past the limit. `.bashrc`
retains its separate root:root 0644 contract. If permission refresh does not run, files
remain root-owned and readable; the account writer's legacy-owned branch takes them
back on the next content change when the account can write again.

This extends ADR 0121 with the sanctioned root-stage primitive; root ownership is a
transient lifecycle state of these managed paths. It is the explicit lifecycle contract
required by ADR 0046 rule 5. ADR 0024's heal remains unchanged and skips these accounts
anyway. ADR 0041's recovery policy remains mutable, without a root-owned panel tree.

## Options Considered

- Registry for the 72 nightly-only www files: skews callers versus helpers for precisely
  the accounts that cannot heal.
- Registry for all www files: supersedes ADR 0024's delivery model.
- Temporary quota uplift: provisioning backend owns quota authority, not PMSS.
- One root-owned panel tree: redesign against ADR 0022 / ADR 0024.
- In-place write: reintroduces truncation outages.

## Consequences

Accounts that cannot allocate a replacement can still receive managed updates. The
reserve floor bounds root's use of remaining space; failures remain visible and do not
invoke another writer. Existing root-stage parent pinning and same-device publication
retain the path-race boundary. Credentials, public names, listeners and secret storage
are unchanged; logs carry paths and failure causes, never delivered content.

## References

- #1162 (over-quota and full-`/home` accounts stop receiving panel files)
- ADR 0121, ADR 0046, ADR 0024, ADR 0041, ADR 0022
- #1203 / e2f3a466 (account writer failure cause)
