# ADR 0092: Bind account data movers to checked filesystem objects

Date: 2026-10-08
Category: security

## Status

Accepted for the four original flows and the account-level webroot operations
below. Remaining root operations retain their existing behavior.

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

## Decision

- `userTransfer/sessionRewrite.php` rewrites a regular file through a verified
  open handle. The handle and current name must have the same device and inode;
  symlinks, non-regular files, and hard links are refused. The rewrite retains
  the file inode and imported UID while producing the same bencoded bytes.
- `userTransfer/postSetup.php` renames the ruTorrent share as the account after
  the existing permission normalization. ADR 0041's exact managed share link
  is resolved to its durable in-home directory before validation; unexpected
  links are refused.
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

- `userTransfer/remoteScripts.php` generates root `rsync -a` passes, including
  `-R` for volatile paths. Upstream documents an implied-parent receiver write
  escape for rsync through 3.4.4. Removing `-R` changes where session, share,
  and public data land; running the receiver as the account loses archived UID
  ownership. The newer receiver confinement cannot be assumed on the supported
  Debian baselines. A destination guard or no-follow default alone does not
  close the implied-parent case, so no such partial change was retained.
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

- Four flows have normal-path and linked-path tests; the CI account-path test
  pins their reviewed operations. Existing customer data movement and cleanup
  ordering remain the expected behavior.
- The rsync flow and remaining root webroot operations must not be described
  as hardened by this ADR. Their exact constraints above remain open.

## References

- ADR 0041: managed and customer-owned webroot state.
- ADR 0062: synchronous termination purge order.
- ADR 0084: recreate restore and backup semantics.
- [Rsync security advisories](https://rsync.samba.org/security.html).
