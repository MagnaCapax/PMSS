# ADR 0092: Bind account data movers to checked filesystem objects

Date: 2026-10-08
Category: security

## Status

Accepted for the original flows, the account-level webroot operations, and
the userTransfer receiver below. Remaining root operations retain their
existing behavior.

## Context

Root maintenance moves and deletes customer data under writable homes. A path
check followed by a separate root command can act on a replacement symlink or
parent directory. Hardening must preserve the bytes, inode identity where a
rename or in-place rewrite promises it, ownership, and the existing cleanup
order. A skipped legitimate migration is a data-preservation failure.

## Options Considered

- Validate path strings only: leaves a swap between validation and use.
- Run every operation as the account: limits privilege but cannot rewrite
  foreign-UID files imported by `rsync -a` before permission normalization.
- Use the narrowest existing boundary for each operation: checked open inode
  for the session file, account UID for the share rename, and pinned real
  directories for root moves and purges.
- Keep the transfer receiver as host root: preserves imported numeric owners,
  but allows sender-controlled path and hardlink operations with host authority.
- Run rsync as the account without a user namespace: confines host authority,
  but loses subordinate UID/GID ownership.

## Decision

- `userTransfer/sessionRewrite.php` rewrites a regular file through a verified
  open handle. The handle and current name must have the same device and inode;
  symlinks, non-regular files, and hard links are refused. The rewrite retains
  the file inode and imported UID while producing the same bencoded bytes.
- `userTransfer/postSetup.php` renames the ruTorrent share as the account after
  the existing permission normalization. ADR 0041's exact managed share link
  is resolved to its durable in-home directory before validation; unexpected
  links are refused.
- `userTransfer` now probes the source account's primary and subordinate IDs
  after authentication. It refuses malformed or oversized mappings, then runs
  each `rsync -aHAX --numeric-ids` receive as the target account in a user
  namespace. Namespace UID/GID 0 map to the target account; source subordinate
  ranges map to the target's allocated subordinate ranges. A private mount
  namespace remounts regular filesystem paths outside the target home read-only
  before rsync starts; proc/sys and root-only Docker mounts retain their
  existing access controls. The home bind remains writable. Missing namespace,
  mapping, mount, or kernel protected-link prerequisites fail closed. The account-owned child
  does not inherit the root Expect wrapper's password environment variable.
  SSH ignores per-account config so a target-owned ProxyCommand cannot reroute
  the password prompt.
- The main and final receives exclude root-managed `.billingId`,
  `.billingServiceId`, and `.billingClientId`. Provisioning writes the target
  `.billingId` through `writeHomeMarker.php`, which first records it in the
  target's root store, before transfer. Billing
  identity is read from that store (ADR 0152); the client ID is present once
  it is recorded there. Rsync does not
  interpret sender `user.rsync.*` fake-super xattrs as privileged metadata.
- `recreateUser.php` renames the top-level home and backup only between checked
  real directories under the same parent, then verifies the moved inode.
  Superseded archives use the same pinned purge as termination.
- `terminateUser.php` purges from a checked real directory as the current
  directory. Ordinary removal remains first; only residue receives an
  immutable-flag pass; removal is retried synchronously. The flag pass opens
  each final node with `O_NOFOLLOW`, verifies device and inode, and applies
  the Linux flag ioctl to that handle. This keeps a swapped name from changing
  flags outside the tree. Perl is present in the Debian 10–12 selection
  baselines; no Python runtime is introduced.
- `update/users/webRoot.php` runs directory migration, rollback, legacy copy
  publication, empty-target removal, and link creation with account authority.
  `webRootReconcile.php` uses the same authority for the final full-restore
  stage move and partial merge directory/link creation. Existing snapshots,
  conflicts, content, and modes remain unchanged. An already unprivileged
  process owning the fixture home may use its own UID when the fixture name
  has no passwd entry; this grants no additional authority. When the caller
  already is the named account, PHP `rename()` replaces an existing empty
  web root directly and verifies the moved inode. This covers both missing
  and empty full-restore targets without depending on an external move command.
  A root process acting as its own fixture account requires root-owned,
  group-and-other-unwritable parents for every account path. The root watchdog
  may publish a complete stage directly for another account only when both
  names are immediate children of a checked root-owned home with those same
  permissions. Customer-owned homes stay on the account path; failed account
  moves there are refused.

## Remaining boundaries

- Webroot legacy copy still copies source entries and applies captured UID,
  GID, mode, and timestamps as root before changing the staged tree to account
  ownership. Its failure cleanup also handles root-owned staged entries. Full
  reconciliation copies and chowns its root-owned stage; partial reconciliation
  installs root-owned managed files through a root temporary file. Those root
  file operations remain unchanged. PHP 7.3 has no `openat`/`fchown`/`fchmod`
  interface to bind them to descriptors; path predicates do not close a tenant
  parent swap. The root-owned-home watchdog fallback is limited to a top-level
  directory move and does not claim the other root webroot operations safe.

## Consequences

- The receiver test covers layout, hardlinks, subordinate owners, billing
  fallback, a mid-receive directory-to-symlink swap, and refusal to create a
  hardlink to a foreign-owned file. Existing cleanup order remains unchanged.
- Other root webroot operations retain the boundaries described above.

## References

- ADR 0041: managed and customer-owned webroot state.
- ADR 0062: synchronous termination purge order.
- ADR 0084: recreate restore and backup semantics.
- [Rsync security advisories](https://rsync.samba.org/security.html), including
  CVE-2026-53785 (implied-parent escape), CVE-2026-53796 (receiver chdir race),
  and CVE-2026-53799 (ACL/xattr symlink race) on versions through 3.4.4.
