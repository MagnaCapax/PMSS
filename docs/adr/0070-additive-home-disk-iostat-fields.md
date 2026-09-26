# ADR 0070: Add /home disk latency fields to the iostat snapshot

Date: 2026-09-26
Category: data
Status: Accepted

## Context

The grouped iostat row includes all sampled data devices. Swap, root, and cache
traffic can dilute the latency of disks backing `/home`. Existing consumers have
thresholds calibrated to the group fields.

### Options considered

- Replace the group fields with `/home` values. Rejected because it changes the
  meaning of existing snapshot keys.
- Add `/home` fields beside the group fields. Chosen to preserve the old contract
  while giving consumers a separate measurement.

## Decision

Keep the sampled device list, iostat command, and all existing snapshot values.
Add `homeDiskAwait`, `homeDiskServiceTime`, and `homeDiskQuantity`. Resolve the
`/home` mount source through the shared resolver, walk block-device slaves to
leaf disks, map partitions to parent disks, and require each disk to be sampled.
Read and write latency use their respective request rates as weights; zero-rate
samples use the plain mean. Failed resolution or missing rows yields three nulls.

## Consequences

Existing consumers continue to receive the same group measurements. Consumers
can adopt the new fields separately and distinguish missing data from latency.
