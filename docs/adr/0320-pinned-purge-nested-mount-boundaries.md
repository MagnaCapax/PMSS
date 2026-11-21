# ADR 0320: Detach nested mounts before pinned directory purges

Date: 2026-11-21
Category: security
Status: Proposed

## Context
Issue #1278 reports FUSE mounts surviving process termination and preventing
home removal. Readable mounts also expose remote contents to recursive deletion.
The inode pin in ADR 0092 protects the purge root but sets no mount boundary.

## Options Considered
- Only add filesystem flags: inaccessible FUSE mountpoints still block removal,
  and same-device bind mounts remain within a device boundary.
- Detach mounts by a separate cleanup step: later purge passes could proceed
  after a failed detachment, including the immutable scan.
- Detach inside each pinned purge guard, then retain filesystem flags as a
  second boundary: one shared flow for termination and superseded archives.

## Decision
Before either recursive action, invoke `pathSafety/mountsDetach.sh` from the
already checked physical directory. Read mountinfo targets without statting
FUSE entries. Decode kernel escapes, select only strict descendants of the
purge root, and detach children before parents, retaining stacked targets.
Unmount uses bounded `--lazy --no-canonicalize --internal-only` calls with a
quoted target and option terminator. No filesystem-specific helper is invoked.
Missing, empty or malformed mountinfo and failed unmounts stop that pass.

Keep the existing labels, path validation, inode checks, synchronous order,
dry-run handling and completion rules. Group the best-effort immutable action
so its existing fallback cannot mask a failed detachment. Both removal passes
use `--one-file-system --preserve-root=all`: the latter protects top-level
mounts expanded as separate glob operands. The immutable scan uses `-xdev`.

## Consequences
Nested FUSE, bind and other mounts are detached without deleting their data.
No mounts equal to or outside the root are selected. The shared pinned purge
also protects recreate's superseded-archive cleanup. Lazy unmount does not
flush remote caches or revoke existing open handles. Concurrent remounts remain
a race; filesystem flags do not exclude same-device bind mounts, nor metadata
access to mountpoint entries. No other mount namespaces are inspected.

## Security review and verification
Read the operational-safety and security-testing doctrines. Debian records
[CVE-2021-3995](https://security-tracker.debian.org/tracker/CVE-2021-3995) and
[CVE-2021-3996](https://security-tracker.debian.org/tracker/CVE-2021-3996) document
cross-user FUSE unmount authorization flaws in util-linux. This root cleanup
does not infer authorization from FUSE owner strings: its scope remains the
validated, pinned purge root. A wrong target could disrupt another account;
strict descendant selection, literal arguments and existing root guards limit
that exposure. No account/service credentials, secret permissions, redaction,
binds, ports or web endpoints change.

Hermetic fixtures stub unmount and simulate detachment to preserve a remote
sentinel. They cover ordering, stacked mounts, escaped names, adjacent roots,
failed discovery/detachment, immutable-fallback grouping and absent/linked roots.
Only changed-file PHP/Bash syntax checks run on the devbox; fixtures run on the
testing machine/CI. No production termination or dry run was executed here.

## References
- ADRs 0062, 0092, 0115, 0191, 0206, 0282; issue #1278.
- [GNU rm filesystem boundaries](https://www.gnu.org/software/coreutils/manual/html_node/rm-invocation.html).
- [Debian umount options](https://manpages.debian.org/bookworm/mount/umount.8.en.html).
