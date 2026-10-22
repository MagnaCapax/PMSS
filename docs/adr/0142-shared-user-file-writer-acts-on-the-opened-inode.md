# ADR 0142: Shared user-file writer acts on the opened inode

Date: 2026-10-22
Category: architecture

## Status

Accepted

## Context

The shared PHP writer stages content beside its destination before publishing
with rename. Checking a name once does not keep later writes or metadata calls
on the same file when entries in that directory change. Existing append and
metadata operations also need to distinguish a single-link regular file from
linked entries before making changes.

ADR 0121 and the current account-path routing already separate account work
from root work. Root-owned home files are prepared in a private root-owned
stage outside the account home, then published into a checked, pinned parent.
Account-owned replacements, metadata changes, and appends run as the account.
Those mechanisms remain authoritative; the shared PHP writer must compose
with them rather than restore direct root writes inside account homes.

## Options Considered

- Keep checking names before each path-based operation. This cannot bind the
  following write or metadata call to the file that was checked.
- Keep a verified file open, write through that handle, and apply metadata
  through its procfs descriptor path. Chosen for the remaining shared PHP
  operations because callbacks can still use the filesystem API.
- Replace the account writer and private root stage with the PHP writer.
  Rejected because it would discard the identity and directory boundaries
  established by ADR 0121.

## Decision

1. Preserve all account-home dispatch, account identity checks, legacy-owner
   repair, root staging, immutable handling, and bounded account append locks.
2. For shared PHP replacements, generate a bounded random staging name beside
   the destination and open it exclusively under umask `0077`. Retry a name
   collision at most four times and restore the original umask on all exits.
   Match the opened device/inode against a single-link regular directory entry
   before writing. Bound the prefix so long destination basenames still work.
3. Write through that handle and flush before publication. Any failed, zero,
   or short nonempty write fails the operation. Empty payloads remain valid.
4. Give preparation callbacks `/proc/self/fd/N` for the opened file, rather
   than its staging name. Existing chmod callbacks remain usable. Ownership
   uses `chown`/`chgrp` for these descriptor paths and `lchown`/`lchgrp` for
   real paths. Reuse the existing stream and device/inode comparison helpers.
5. Before rename, recheck both staging identity/link count and destination
   path safety. Close the handle on every exit and unlink the staging name on
   failed publication or a thrown callback. If exclusive creation opens a
   file whose name fails the initial identity proof, close it without deleting
   an entry that has not been established as ours.
6. For metadata on other real paths, require a safe single-link regular entry,
   open without creating or truncating, verify its identity, then act through
   its descriptor path. Linked, missing, and non-regular entries are untouched.
   Account-home metadata keeps its existing routing and also rejects entries
   that are not single-link regular files.
7. For an existing shared PHP append target, use a verified noncreating `r+`
   handle, acquire an exclusive lock, recheck identity, seek to the end, and
   write through that handle. Apply metadata to the opened inode only after
   a complete write. A missing target uses staged replacement. Initial links,
   including dangling links and multiple hard links, are refused.

## Consequences

Preparation callbacks receive a descriptor path valid only during the call;
they must not retain it, rename it, or derive the staging filename from it.
Linux procfs is required for callback metadata. Replacement fails without
publication when a required descriptor path cannot be found.

Failed replacements preserve the destination. A failed append can leave bytes
already appended, as before, but does not proceed to metadata changes. These
checks bind writes to opened inodes; they do not make the final path check and
rename a single syscall or serialize independent publishers. The private root
stage and account execution boundaries therefore remain necessary.

Fixtures cover staging-name replacement during callbacks, metadata after a
name change, linked metadata/append targets, callback descriptor paths, long
basenames, empty/binary payloads, incomplete writes, and cleanup after errors.
The shared fault shims support both `tempnam`/`file_put_contents` and exclusive
`fopen`/`fwrite` writers. Tests run on the development sandbox; deployment and
live-host verification remain a separate release step.

Rootless Docker `daemon.json` convergence also uses these existing boundaries.
Root callers resolve the home account, match the requested UID and primary GID,
and use `pmssReplaceAccountFile()` with mode `0600` and legacy-owner repair.
Account callers use the shared opened-inode writer. Failed publication reports
`write_failed` without changing the previous config; unsafe targets still report
`unsafe_config_file`. Deluge's metadata-preserving writer and the skeleton-copy
helper already dispatch through the shared/account writers and retain their
existing ownership behavior. This applies ADR 0121 rather than adding a new
ownership policy.

The review considered Deluge's documented WebUI file-read and script-loading
issues and runc's rootless cgroup write issue. Account-writable configuration
can affect that account's daemon and files, so root config preparation must not
inherit the authority of the calling cron or updater. No account or service
credentials, listener addresses, or ports change in this batch. Docker configs
remain account-owned and mode `0600`; failure reporting contains no config
payload. Deluge's separate service credentials and existing config modes remain
on their current writer paths.

## References

- [ADR 0121: Root operations and account-controlled objects](0121-root-operations-and-account-controlled-objects.md)
- [ADR 0046: Per-user config ownership classes](0046-provisioned-in-home-file-ownership-and-enforcement-classes.md)
- [PHP fopen modes](https://www.php.net/manual/en/function.fopen.php)
- [PHP fwrite return values](https://www.php.net/manual/en/function.fwrite.php)
- [Deluge WebUI findings](https://securitylab.github.com/advisories/GHSL-2024-188_GHSL-2024-191_Deluge/)
- [runc rootless cgroup write advisory](https://github.com/opencontainers/runc/security/advisories/GHSA-m8cg-xc2p-r3fc)
- [Docker rootless mode](https://docs.docker.com/engine/security/rootless/)
