# ADR 0127: User transfer recursion stays on one filesystem

Date: 2026-10-20
Category: data

## Status

Accepted

## Context

The account sender in ADR 0095 can read customer FUSE mounts beneath the home.
Recursing into rclone or mergerfs views materializes remote storage or a second
copy of local branch data on the target, and populates mount directories that
customers need empty to remount. The aggregate completeness probe also counts
these views. Receiver isolation from ADR 0092 does not set a recursion boundary.

## Options Considered

- Continue traversing mounts: retains unbounded copying of mounted views.
- Use one rsync filesystem-boundary option: preserves empty mount directories.
- Repeat the option: omits the mount directories, complicating remounts.
- Add an opt-in flag: leaves the default transfer exposed to the defect.

## Decision

Both transfer passes use one `--one-file-system`. The shared source and target
statistics shell uses `du -sbx` and `find -xdev`, retaining the existing advisory
threshold and aggregate parser. No authentication, ownership mapping, excludes,
or receiver prerequisites change.

The existing statistics connection also reads `/proc/self/mountinfo`. It reports
counts and filesystem types for nested mounts whose device differs from the
home's containing filesystem. It never reports customer-chosen paths or mount
sources. Missing mount information reports `nested_mounts=unavailable` without
failing the statistics probe. The transfer log records these aggregate tokens.

## Consequences

Nested FUSE views remain empty directories on a fresh target and customers
remount them using their own configuration. Same-device bind mounts still copy.
Rsync applies the boundary to each explicit source root, including the final
pass's selected directories; it does not exclude a source root that is itself
a mount. Mount counts describe the observed home layout, not an exact rsync
skip list: excludes, explicit roots, and concurrent mount changes can differ.

This prevents new copies through nested mounts; it does not remove duplicates
from earlier transfers or flush pending remote writes. Transfers already omit
`.cache`; customers must preserve or flush pending rclone cache writes before
a move. A disposable tmpfs test verifies copy, final-pass, and statistics
boundaries; real FUSE and production rollout checks remain outside local QA.

## References

- [Issue #1114](https://github.com/MagnaCapax/PMSS/issues/1114).
- ADRs 0092, 0095, and 0118.
- [Rsync filesystem-boundary semantics](https://download.samba.org/pub/rsync/rsync.1).
- [Linux mountinfo fields](https://man7.org/linux/man-pages/man5/proc_pid_mountinfo.5.html).
