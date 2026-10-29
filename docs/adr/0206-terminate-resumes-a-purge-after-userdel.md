# ADR 0206: Terminate resumes a purge after userdel

Date: 2026-10-29
Category: security
Status: Accepted

## Context

ADR 0062 puts bounded route and port cleanup before the synchronous purge.
ADR 0115 verifies completion, but after `userdel_initial` an interrupted purge
could not be resumed: the managed-user, home-existence and passwd guards rejected
the departed account. An interruption during backup removal can leave no home.
Those guards originate in the destructive-path hardening of `229c1e7a`; simply
removing them would permit deleting unrelated directories. A reused UID also
allows a different live account to access the former account's leftover data.

## Options Considered

- Restore a reclaim worker, sweeper or rename-aside: rejected by `34115ab7`
  and the synchronous-not-queued contract of #729.
- Correct only the resumability comments: leaves the requested behavior in
  #1218 and the promise in ADR 0062 unimplemented.
- Remove existing leftovers through a new cleanup facility: existing residue
  requires separately authorized operational handling, outside this change.
- Resume an explicitly requested termination in place under a leftover proof.

## Decision

Before the existing guards, a missing passwd entry permits resume only when
at least one of the three exact purge paths exists. Each existing path must be
a non-link directory, resolve exactly to itself, share `/home`'s device and
have the same owner UID >= 1000. Require readable `/etc/passwd`, available POSIX
functions and successful name/UID lookups proving the name and UID unassigned.
Lookup failures refuse. Filesystem proof belongs in `termination/filesystem.php`
under ADR 0191. No existing purge helper changes.

Refuse a concurrent termination of the same name using complete process argv,
excluding self; an unreadable live process scan also refuses. A lock file is
unsuitable because final cleanup removes per-account lifecycle lock files.
Keep confirmation before resume process signaling and recheck proof afterward.
Kill leftover processes once by the proven UID, with a bounded command, before
cleanup. Skip only the initial account phase, which requires a resolving account.
Keep the final kill name-based: a long purge may outlive UID reassignment.
The lifecycle start event records both resume mode and UID.

On UID reassignment, refuse with the live account name and UID as a privacy
finding. First seal each existing leftover top directory to root:root 0700.
Pin device/inode, use `cd -P`, verify physical path and inode again, then change
ownership and mode on `.` only. Never recurse, follow links or change metadata
by a named path. Report failed seals; dry runs only log planned commands.

## Consequences

Proof failures exit 1 with a named reason (`symlink`, `unsafe_path`,
`not_directory`, `not_same_fs`, `owner_mismatch`, `uid_out_of_range`,
`lookup_failed`, `uid_reassigned:<name>`, or `terminate_running`). No leftovers
means `nothing_to_resume` and the old managed-list refusal text and exit 0.
Refusals delete nothing; sealing intentionally restricts access to former data.
Root-owned sealed residue requires operator inspection rather than automatic
deletion. Sealing cannot revoke data already accessed or open descriptors.

Route retirement, nginx reload, port release, synchronous purge and completion
remain ordered as before (#908). There is no sweeper, queue or new CLI flag.
`addUser.php` already refuses an orphaned home; #729 still requires synchronous
completion, regardless of that additional guard. The proof assumes `/home` is
controlled by root; UID/name allocation and termination are not one transaction.

Unit fixtures cover the proof and process scan failure boundaries; source
contracts retain every existing forbidden and ordered entry. Regression suites
run only on testing machines. End-to-end acceptance covers interruptions during
home and backup purges, refusals, sealing, confirmation and reprovisioning.

References: ADRs 0062, 0115, 0191; #1218, #729, #908; `229c1e7a`, `34115ab7`.
