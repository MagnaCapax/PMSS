# ADR 0169: Opt-in ext4 group preallocation workaround

Date: 2026-10-24
Category: architecture

## Status
Accepted, default off. Host measurement and canary remain tracked in #1147.

## Context
Older ext4 allocators send stripe-multiple requests through an aligned scan
without a complex-scan fallback. Mount initialization rounds group preallocation
up to the stripe; locality-group allocations can therefore enter that path.
Upstream commit 1f6bc02f1848 adds the fallback in mainline 6.8. Whether this
mechanism causes the reported production stalls remains unproven. A synthetic
experiment is recorded in #1147; it does not establish production effectiveness.

## Options Considered
- A fixed preallocation value: rejected because stripes dividing that value
  leave requests aligned and the workaround ineffective.
- Clear the filesystem stripe: rejected because that also changes stream
  allocation alignment and requires a mount option change.
- Change other allocator knobs or ship an unconditional boot default: rejected
  because that bundles mechanisms and precedes the production measurement.
- Derive one value per superblock, gated by a host marker and kernel release:
  chosen as a small, reversible experiment lever.

## Decision
Following the host-marker precedent in ADR 0068, boot tuning first checks
`/etc/seedbox/config/ext4-group-prealloc.enabled`. No marker is shipped or enabled.
Without it, no ext4 helper, discovery, read or write runs. Its audit array is empty.

The helper under `scripts/lib/update/systemPrep/` enumerates mounted ext4
superblocks through `/proc/fs/ext4/*/options`, reads each live `stripe` and its
`/sys/fs/ext4/<device>/mb_group_prealloc`, and uses one pure decision function.
On releases below 6.8, a positive current value exactly divisible by a stripe
greater than one becomes `current - 1`. Other values are preserved. Unknown
kernel releases and invalid/missing input skip safely; unsigned 32-bit bounds
prevent invalid writes. Distribution backports are not detected: the marker
remains an explicit opt-in even when an older release carries the fix.

After a write, read back the knob. The existing `hardware.json` records an
`applied.ext4_group_prealloc` array of device, stripe, before/after values and
result (applied, skip reason, write failure or readback mismatch). Missing reads
are null. A missing helper is recorded explicitly. This does not use the existing
failure-swallowing `write_sys` helper. Non-md mounts follow the same path;
bind mounts sharing a superblock are handled once.

The boot unit reapplies the rule at every boot. A plain remount preserves the
knob, while unmount/mount resets it. Updates retain the existing oneshot start
behavior; an already-active unit does not rerun solely because its template
changed. Marker activation and production deployment are outside #1164.

## Consequences
Default behavior stays unchanged. Enabled hosts give up stripe alignment for
group allocations, with possible fragmentation/write amplification costs to
measure in #1147. Power-of-two stream allocations can still use aligned scans;
this workaround addresses only group preallocation.

Removing the marker prevents future writes; it does not restore live state.
Restore the recorded original value explicitly or unmount/mount the filesystem.
Remove the workaround when all supported deployed kernels carry the fallback.
Hermetic development tests cover arithmetic, kernel boundaries, missing knobs,
write/readback failures, multiple superblocks and the marker gate.

## References
- #1164 (code), #1147 (measurement and canary)
- [Opt-in marker precedent](0068-opt-in-tenant-socket-table-privacy.md)
- [Upstream allocator fix](https://github.com/torvalds/linux/commit/1f6bc02f1848)
