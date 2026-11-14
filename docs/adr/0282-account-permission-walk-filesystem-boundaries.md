# ADR 0282: Account permission walk filesystem boundaries

Date: 2026-11-14
Category: safety

## Status
Accepted; partial implementation of issue #1268.

## Context
Permission walks could descend into customer-mounted remote storage. They also
reported errors when entries vanished between directory enumeration and stat.
ADR 0127 already bounds transfer recursion to its starting filesystem.

## Options Considered
- Retain unbounded walks: leaves remote traversal and timeout exposure.
- Apply the existing filesystem boundary to permission walks.
- Prune mountinfo paths: insufficient evidence that this avoids root's stat error.

## Decision
Add `-xdev -ignore_readdir_race` to the home owner walk, home directory-mode
walk, web executable cleanup, recursive configured mode repairs and anchored
owner traversal. Preserve every selection predicate, exclusion and action.
Direct depth-zero repairs retain their existing depth limit and behavior.

## Consequences
Walks do not descend onto a different filesystem from their starting root.
Ordinary nested `~/data` directories remain in the home mode walk; owner repair
still prunes that tree. Same-device bind mounts and explicit starting roots
that are mounts remain within find's existing semantics. A filesystem boundary
does not exclude the mount-point entry itself from metadata repair.

This is partial progress: root's attribute lookup on a FUSE mount without
`allow_other` can still fail. Path pruning alone is not claimed to prevent
that lookup. Real FUSE acceptance and a safe solution for inaccessible mount
entries remain outstanding. Errors other than find's vanished-entry race,
including failed chmod/chown actions, still propagate through the existing
failed-command summary. No blanket stderr suppression or success override is added.

Regression fixtures retain exact command/phase assertions, exercise ordinary
nested data modes and excluded app trees, and cover recursive mode policies
and anchored owner guards. Execution belongs on the testing machine and CI;
the devbox only checks changed PHP syntax. No production repair or dry run
was performed here.

## Security review
The operational-safety and security-testing doctrines were read. Debian's
[CVE-2017-18018 record](https://security-tracker.debian.org/tracker/CVE-2017-18018)
documents recursive ownership races involving symlink replacement. Existing
account execution, owner predicates, single-link checks, opened-file repairs,
anchored traversal and `-execdir chown -h` remain intact. A bad privileged repair
could affect another account or root-owned service state; this patch adds no
privileged operation or path dereference.

Credential contents and primary/service credential separation are untouched.
Secret modes, ownership and redaction behavior remain as before. No binds,
ports, web endpoints, certificates or DNS records change.

## References
- ADR 0127: transfer filesystem boundaries.
- ADR 0281: permission convergence responsibilities.
- [Linux FUSE attribute lookup](https://github.com/torvalds/linux/blob/master/fs/fuse/dir.c)
  rejects disallowed callers in `fuse_getattr()`.
