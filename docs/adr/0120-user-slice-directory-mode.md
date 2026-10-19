# ADR 0120: User slice directory mode

Date: 2026-10-19
Category: domain

## Status

Accepted

## Context

Each account has a known `user-<uid>.slice` path under the per-controller
`user.slice` parent. PMSS readers use that known path; the root-side diagnostics
utility reads the full set. The parent mode can therefore be set independently
of account slice modes.

## Options Considered

- Retain the parent mode as supplied by systemd. This does not provide the
  desired directory behavior.
- Set each mounted parent to 0711 while preserving child modes. Chosen.

## Decision

At boot and during update-step2, set the v1 `user.slice` parent of each mounted
controller to 0711. For a v2 hierarchy, apply the same mode to the single
parent. Keep every account slice's own mode unchanged. Customer panel readers
`webCgroupMemoryStatus.php`, `statsHelpers.php`, and `scriptsIncCgroup.php`
build the current account's known path from its UID; `userMediaStackPanel.php`
reads `/proc/self/cgroup`. Root-side resource readers and the cgroup CLI take a
known UID. `agentDiagnostics` runs from the root-side `/scripts` tree and is the
one reader that samples wildcard account slices.

## Consequences

The mode is renewed after boot because cgroup directories are runtime state.
No new service or persistent file is needed. The update and boot paths use the
same mode.

## References

- ADR 0118
- `scripts/lib/update/systemPrep/cgroupDirectoryMode.php`
- `etc/seedbox/config/template.pmss-boot-tuning.sh`
