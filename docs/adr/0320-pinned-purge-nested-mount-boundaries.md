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

## Unterminated mount observation safety follow-up

The reader retains each raw mountinfo row before splitting its fields. A
nonempty final fragment without a newline now refuses the snapshot before any
unmount, immutable scan or removal. Previously Bash's failed final `read` skipped
that fragment, allowing preceding valid rows to authorize recursive work while
the last mount entry was unexamined. Complete kernel rows retain their existing
selection, decoding, order and command arguments. This guard detects a partial
final row; it cannot detect truncation exactly at a row boundary.

`TerminateUserMountBoundaryTest` covers fragments at multiple field boundaries,
a complete-looking row missing its newline, invalid text and whitespace-only
tails. Each follows both a root-only row and a queued descendant, checking both
purge passes, no unmount and preservation of the fixture's remote sentinel.
The existing security review still applies: account authority, credentials,
permissions, logs and exposure paths are unchanged. Devbox verification is
changed-file PHP/Bash syntax only; fixtures run on the testing machine/CI.
No live unmount, purge or dry run was executed here.

## Canonical mount target safety follow-up

Decoded targets must have no `.` or `..` path components, repeated separators
or non-root trailing slash before prefix selection. A malformed target refuses
the complete snapshot, even outside the purge root or after queued descendants.
It is never normalized or resolved through filesystem probes. Otherwise a
lexical descendant containing `..` could select an unmount outside the pinned
root. Canonical targets, dotted filenames, escaped names, sorting and command
bytes retain their contracts.

`TerminateUserMountBoundaryTest` extends both purge-pass refusal fixtures and
retains a valid dotted descendant in the ordered-unmount fixture. The existing
security review applies: CVE-2021-3995 and CVE-2021-3996 concern cross-user FUSE
unmounts; a wrong target can disrupt another account. This guard reduces that
path-selection risk without changing account/service credential separation,
secret permissions, logging/redaction, binds, ports or web endpoints. Devbox
verification is changed-file PHP/Bash syntax only; fixtures run on the testing
machine/CI. No live unmount, purge or dry run was executed here.

## Fixed mount field safety follow-up

Snapshot discovery also requires decimal parent IDs, decimal `major:minor`
device fields and absolute mount roots before considering any target. Previously
a plausible target could let a malformed fixed field pass discovery and authorize
detachment or recursive work. The guard rejects the complete snapshot before
executing queued detaches; valid rows, escaped names, ordering and command bytes
retain their contracts. It adds no filesystem probes or normalization.

`TerminateUserMountBoundaryTest` covers malformed parent IDs, incomplete device
pairs and relative mount roots before and after queued descendants, through both
purge passes, with no unmount and the remote sentinel preserved. The existing
security analysis applies: CVE-2021-3995 and CVE-2021-3996 concern cross-user FUSE
unmount authorization, and a wrong unmount target can disrupt another account.
Primary/service credentials, secret permissions, logging/redaction, binds, ports
and web endpoints are unchanged. Devbox verification is changed-file PHP/Bash
syntax only; fixtures run on the testing machine/CI. No live unmount, purge or
dry run was executed here.

## Mandatory mount tail safety follow-up

After the separator, discovery requires exactly three nonempty fields: filesystem
type, mount source and superblock options, as defined by the
[kernel mountinfo format](https://www.kernel.org/doc/Documentation/filesystems/proc.txt).
A separator followed by an incomplete or overlong tail refuses the complete
snapshot before queued detaches or recursive work. Previously the separator alone
could let such a newline-terminated row authorize cleanup. Optional fields before
the separator remain ignored, including unknown tags; escaped sources, valid
kernel rows, ordering and unmount command bytes retain their contracts.

`PinnedPurgeMountTailSafetyTest` covers missing and extra tail fields, unrelated
rows, and corruption before and after queued descendants, using only private
fixtures and a recording unmount stub. Positive cases retain propagation tags,
unknown optional tags, escaped sources, a dash source and whitespace handling.
The existing purge-pass failure coverage remains in `TerminateUserMountBoundaryTest`.
The CVE-2021-3995/CVE-2021-3996 analysis above still applies: incorrect unmounts can
disrupt other accounts. No account/service credentials, secret permissions,
logging/redaction, binds, ports or web endpoints change. Devbox verification is
changed-file PHP/Bash syntax only; tests run on the testing machine/CI.
No live unmount, recursive purge or dry run was executed here.

## References
- ADRs 0062, 0092, 0115, 0191, 0206, 0282; issue #1278.
- [GNU rm filesystem boundaries](https://www.gnu.org/software/coreutils/manual/html_node/rm-invocation.html).
- [Debian umount options](https://manpages.debian.org/bookworm/mount/umount.8.en.html).
