# ADR 0083: Require the home mount during fresh install preflight

Date: 2026-10-03
Category: provisioning

## Status

Accepted

## Context

User tools require `/home` to be mounted. The bootstrap previously continued
through provisioning when `/home` was part of the root filesystem, leaving the
failure to appear on the first user operation.

## Options Considered

- Keep the warning and quota editor: installation can finish unusably.
- Check `/home` in the installer preflight: fail before package and file changes.

## Decision

Require `/home` to be a mount point in the fresh-install preflight. Preserve
`PMSS_SKIP_HOME_MOUNT_CHECK=1` (or `true`) for intentional non-standard
deployments, matching the existing user-tool override. Existing installs,
including the bootstrap fallback when `update.php` is missing, bypass the check.

## Consequences

New installs without a mounted `/home` exit before package work, including
`--dry-run`. Operators must provision and mount `/home` first or explicitly use
the override. Existing installs retain their established paths.

## References

- Issue #999
- `docs/install.md`
- `docs/adr/0007-install-bootstrap-interactivity-and-contract.md`
