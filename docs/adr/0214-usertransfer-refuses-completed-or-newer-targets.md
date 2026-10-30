# ADR 0214: User transfer refuses completed or newer targets

Date: 2026-10-30
Category: data

## Status
Accepted

## Context
Repeating a migration can replace a live target's data with an older source copy.
Rsync's existing options retain target-only files but overwrite shared paths and
restore files removed on the target. Validating the local account/home alone
cannot establish whether another migration is safe.

## Options Considered
- Use rsync `--update`: still resurrects removed files and mixes two histories.
- Trust operator automation to track completion: leaves PMSS without a guard.
- Record successful sources and check file ages before copying: protects new
  migrations and provides a conservative check for transfers predating records.

## Decision
Before authentication or account setup, normal migrations check the target's
durable `UserConfigStore` payload. `userTransferCompleted` maps each lowercase
source hostname plus `/` plus source account to its last successful UTC timestamp.
An existing key refuses the pull. Missing user payloads or malformed history
refuse even with `--force`. After successful passes, post-setup and source
hand-over, write through canonical-only `set()` before reporting completion.
Preserve existing metadata and all previous source keys; failed writes fail the
command. Customer-side cached state is never read to authorize a transfer.

After namespace probes and before the first rsync pass, compare the newest
regular-file mtime on each side. Use physical `find -P -xdev` walks and a
constant-memory decimal maximum, retaining nanosecond precision. Source scans
run as the authenticated account, using its namespace mapping when available;
target scans reuse the account-mapped receiver and private mount view. Emit
only aggregate timestamps, and refuse on failed walks or unreadable output.

`--force` is a bare, explicit opt-in to these two overwrite risks and logs a
warning. It leaves every pre-existing guard intact. Recovery with `--into`
neither replaces existing files nor hands over services, so skips these checks
and does not record completion. Dry runs read history but make no remote probes
or completion writes. There is no automatic record backfill or source deletion.

## Consequences
- Repeat normal migrations from a recorded source refuse before network access.
- File ages are a conservative heuristic: new provisioning files and host clock
  skew can require operator review and `--force`, even on a first migration.
  Inaccessible files in the compatibility path fail the preflight.
- Each non-forced migration adds one home walk on each side. Links and nested
  different-device mounts are not followed. This is not a receiver mount audit.
- History keys use the CLI hostname; DNS aliases/IP spellings remain distinct.
  The age check is a snapshot, not a lock against later customer writes or
  concurrent migrations. Failed transfers create no completion record.
- The moved-source check requested in #1140 remains outstanding. ADR 0131 stores
  `movedUntil` only in root:root 0640 canonical state; the source account's SSH
  session cannot read it. A source-side authoritative status interface is needed
  before the target can enforce it. No root-store permission relaxation,
  customer-home authority, or extra remote-root access is introduced here.
- Hermetic coverage is added for completion history, CLI opt-in, timestamp
  precision, physical scans, failed probes, and recovery behavior. Execute on the
  testing VM/CI; devbox verification is syntax-only, without a transfer dry run.

## References
- Issue #1140.
- ADR 0092: inode-safe account data movers.
- ADR 0095: transfer rsync in account user namespaces.
- ADR 0127: user transfer recursion stays on one filesystem.
- ADR 0131: moved state for a migrated account's old copy.
