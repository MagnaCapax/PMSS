# ADR 0095: Transfer rsync in account user namespaces

Date: 2026-10-14
Category: security
Status: Accepted

## Context

`userTransfer` pulls account data over SSH. The source rsync runs as the source
account and cannot read owner-only rootless Docker files owned by its subordinate
IDs. A plain rsync can return rc=23 after omitting those files. Source and target
accounts may also have different subordinate ranges, so host-numeric owners are
not portable. ADR 0072 gives containers a quiesced final pass. ADR 0092 already
runs the receiver as the target account in a private mount view and maps source
host IDs onto the target's allocation.

## Options considered

- Pull as source root: can read the files, but grants the transfer host-wide
  source privilege.
- Remap host IDs after the copy: cannot recover files the source could not read
  and adds another traversal of the account home.
- Run both rsync processes in the respective account's Docker-style user
  namespace: permits access to mapped container files and carries their IDs
  relative to each account's allocation.

## Decision

After SSH authentication and identity validation, probe both accounts once with
`unshare --map-auto --map-root-user true`. The target probe drops to the target
account UID and GID; the source probe runs through the authenticated SSH account.
Only when both succeed, use `--rsync-path='unshare --map-auto --map-root-user rsync'`
on the source and run the local receiver rsync with the same unshare mapping as
the target account. Namespace ID 0 maps to the account, while IDs 1 onward map
to its first subordinate block. `--numeric-ids` retains the container-relative
IDs on the wire. In this path, omit the old source-host `--usermap` and
`--groupmap` options. Keep the existing receiver's private mount view, writable
home bind, read-only outside mounts, password environment removal, excludes,
and final-pass `-R` form.

If either probe fails, log one namespace-unavailable line and use the previous
rsync command and receiver mapping. That path still uses `--numeric-ids`: it is
required by the existing host-ID mapping and is not a new namespace-only option.
The probe does not claim that all files were copied; rsync errors and transfer
verification remain relevant.

## Consequences

Mapped owner-only container files become readable to the source rsync through
namespace DAC capabilities. A container UID such as 999 maps to the target's
subuid start plus 998, even when the two hosts allocate different ranges.
Neither rsync runs with host-root privileges. The existing root-owned parent
still establishes the receiver's private mount view before dropping to the
account. `newuidmap` and `newgidmap` may set only that account's delegated IDs;
unavailable helpers, util-linux support, or subordinate allocations trigger
the compatibility path. Accounts without subordinate IDs have no mapped
rootless Docker data for this path to recover.

The namespace probe verifies launch capability, not the contents of the home.
Files outside the first subordinate block can still fail to transfer in the
namespace path. Rsync remains transported through SSH rather than a listening
rsync daemon; its sender and receiver still process untrusted file metadata.
The rsync project's security advisories include CVE-2024-12084 in daemon
checksum handling, which does not create a new listening endpoint here.

## References

- Issues #961 and #964; container hand-over in ADR 0072; receiver boundary in
  ADR 0092.
- [util-linux unshare mapping reference](https://github.com/util-linux/util-linux/blob/master/sys-utils/unshare.1.adoc)
- [Linux user namespace capability rules](https://man7.org/linux/man-pages/man7/user_namespaces.7.html)
- [rsync security advisories](https://rsync.samba.org/security.html)
