# ADR 0281: Account permission convergence boundaries

Date: 2026-11-14
Category: architecture

## Status
Accepted

## Context
The 330-line account permission utility mixed identity and lock ownership,
home preparation, ordered mode/owner policy, subordinate-ID traversal and
repair sequencing. Its checked-file mode and group setters separately owned
identical open/check/mutate/close lifetimes. A general owner-spec parser had
one caller, whose only recursive policy was the account's own lighttpd tree.

## Options Considered
- Retain the utility and duplicated checked-file lifetimes.
- Introduce a registry interpreting modes, owners, traversal and trust together.
- Separate the existing phases and share checked-file lifetime ownership.

## Decision
Keep the CLI, identity checks, blocking close-on-exec lock and final failure
summary in scripts/util/userPermissions.php. The lock remains held until exit.
The user/permissions.php module owns the existing convergence order;
permissionsHome.php prepares the home and executable directories;
permissionsPolicy.php returns the two ordered policy lists;
permissionsOwnership.php owns the pruned home walk and subordinate-ID queries.

Mode and group setters retain their typed entrypoints and share one
checked-file lifetime in permissionsApply.php. Every metadata check and
mutation remains inside its cleanup lifetime. The single recursive owner
predicate is constructed from the validated account name at its consumer;
there is no general owner-spec interpretation. The ownership walk renders
its fixed command suffix directly instead of accumulating its individual tokens.

This preserves the existing lists rather than implementing ADR 0046's future
cross-writer registry. No path, mode, owner, exclusion or trust gate changes.
No alternate permission implementation or execution path remains.

## Consequences
- Two mechanisms disappear: the separate mode/group acquisition lifetimes
  become one, and general owner-spec interpretation disappears (concept delta -2).
- Across the CLI, existing apply library and four new modules, runtime file
  lines fall from 531 to 525; nonblank, noncomment lines fall from 376 to 367.
  The CLI is 34 lines; every new runtime module is under 100 lines.
- Home preparation still precedes traffic snapshots. Root-trusted entries
  are checked once before mutable repair. Ownership precedes account directory
  modes, web executable stripping and configured modes; SSH and final immutable
  sets retain their positions. Ordinary repair failures still allow later work,
  while throwables retain their existing propagation boundaries.
- UserPermissionsFlowCharacterizationTest freezes the complete mode/owner
  lists, phase trace, every trace-position exception/error, home-preparation
  fallbacks, command bytes, exclusion order and subordinate-ID reporting.
  Existing inode, link, owner, strip-only and stream-cleanup tests remain.
  Source assertions follow their relocated code without changing the harness.
- No repair or dry run executes on the devbox. Changed-file PHP syntax and
  deleted-symbol searches are local; fixture execution belongs to the testing
  machine and CI.

## Security review
The operational-safety and security-testing doctrines apply because this code
repairs authorization metadata. The upstream
[Coreutils chmod manual](https://www.gnu.org/software/coreutils/manual/html_node/chmod-invocation.html)
describes recursive symlink substitution risks.
[Debian's CVE-2017-18018 record](https://security-tracker.debian.org/tracker/CVE-2017-18018)
records a chown/chgrp ownership race involving recursive link dereferencing.
These references inform the boundary review; this change claims no CVE fix.

A substituted account path could otherwise affect another account or a
root-owned file, with potential service compromise or privilege escalation.
Account-side mode changes, opened single-link regular files, expected owners,
descriptor locations, anchored traversal and non-dereferencing owner changes
retain their guards. Root-trusted billing, quota and traffic names are never
adopted from account-owned replacements. Subordinate IDs and strip-only config
modes retain their exact policies.

The utility does not read credential contents, create credentials or change
primary/service credential separation. Password-file modes and owners remain
identical; diagnostics retain metadata and paths without credential values.
No SSH, panel, API, bind address, port, certificate or DNS exposure changes.

## References
- [Provisioned in-home ownership classes](0046-provisioned-in-home-file-ownership-and-enforcement-classes.md)
- [Subordinate owner IDs](0071-user-permissions-owner-repair-preserves-subordinate-id-ownership.md)
- [System permission boundaries](0276-system-permission-policy-and-execution-boundaries.md)
- scripts/lib/tests/development/UserPermissionsStreamSafetyTest.php
- scripts/lib/tests/development/UserPermissionsApplyTest.php
