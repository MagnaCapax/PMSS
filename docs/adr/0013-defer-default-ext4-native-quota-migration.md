# ADR 0013: Defer default migration to ext4 native quotas

Date: 2026-03-08
Category: architecture

## Status
Accepted (do not auto-migrate ext4 quota mode in default PMSS updates)

## Context

Issue #136 requests a migration plan from ext4 external quota files
(`aquota.user` / `aquota.group`) to ext4 native quota feature.

Current repository behavior and contracts:
- PMSS quota orchestration expects external-journaled quota mount options
  (`usrjquota=aquota.user`, `grpjquota=aquota.group`, `jqfmt=vfsv1`) in
  `scripts/lib/update/services/quota.php`.
- Quota maintenance helpers and tests currently validate the external quota
  file model (`aquota.*`) and related fstab state.
- Production update flow is online-first; default phase-2 updates are not
  designed to unmount `/home` or perform filesystem feature toggles.

Operational constraints:
- Enabling ext4 native quota feature (`tune2fs -O quota`) requires controlled
  maintenance windows and offline filesystem operations.
- PMSS doctrine prioritizes stability and backward compatibility for long-lived
  multi-tenant hosts.
- Fleet-wide automatic migration during routine updates would raise outage risk
  and recovery complexity beyond acceptable default behavior.

## Options Considered

- Option A — Auto-migrate all eligible hosts during normal update runs.
  - Pros: quickly converges fleet to native quota feature.
  - Cons: unsafe in online update path; requires unmount/offline operations and
    high-risk failure handling in the default flow.

- Option B — Keep current external quota mode by default and defer migration.
  - Pros: preserves established behavior; avoids surprise downtime; aligns with
    conservative update topology.
  - Cons: deprecation warnings from quota tooling continue until migration is
    explicitly scheduled.

- Option C — Remove quota automation and require manual operator handling.
  - Pros: avoids PMSS ownership of migration complexity.
  - Cons: creates drift and weakens convergence guarantees in provisioning.

## Decision

Choose **Option B**.

PMSS keeps the existing external quota mode as the default and does not perform
automatic ext4 native quota migration during routine updates.

Decision boundaries:
- No default update step may unmount `/home` or flip ext4 quota features.
- Existing quota helpers and validation around `aquota.user` /
  `aquota.group` remain authoritative.
- Native quota migration is an explicit, operator-planned maintenance action,
  not a background side effect of standard update runs.
- Any automation for native quota migration requires a follow-up ADR covering
  host eligibility checks, preflight backups, rollback path, and maintenance
  orchestration contract.

## Consequences

- Positive:
  - Preserves backward compatibility and idempotent online updates.
  - Avoids high-blast-radius filesystem operations in normal PMSS runs.

- Negative:
  - Deprecation warnings remain until dedicated migration windows are executed.

- Follow-ups:
  - Design a maintenance-mode migration workflow outside the default update
    path, then validate it on representative Debian 10/11/12 hosts before
    wider rollout.

## References

- GH issue #136
- `scripts/lib/update/services/quota.php`
- `scripts/lib/tests/development/quotaFstabOptionsTest.php`
- `docs/update.md`

