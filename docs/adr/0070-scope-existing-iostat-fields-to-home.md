# ADR 0070: Scope existing iostat fields to /home disks

Date: 2026-09-26
Category: data
Status: Accepted

## Context

The grouped iostat row included every discovered data device. Swap, root, and
cache disks could dilute latency for disks backing `/home`, while consumers
read the existing snapshot fields as measures of `/home` disk health.

### Options considered

- Sample only `/home` leaf disks for the existing group fields. Chosen because
  one group row then gives every existing metric the same disk scope.
- Add parallel `/home` fields while keeping an all-device group. Rejected: it
  leaves the existing fields misleading and expands the snapshot contract.

## Decision

Resolve the `/home` mount source through the shared resolver, walk block-device
slaves to leaf disks, map partitions to parent disks, and require every leaf to
be in the discovered device list. Pass those leaves to iostat. When resolution
fails, pass the full discovered list as before. Parse the existing `grp1` row
without new per-device calculations. `diskQuantity` counts the sampled devices.
The snapshot key set remains the pre-change set; there are no parallel keys.

## Consequences

Existing consumers receive `/home` scoped `diskAwait` (`r_await`),
`diskServiceTime` (`w_await`), utilization, IOPS, throughput, and queue size
when `/home` resolves. On hosts where swap, root, or cache disks diluted the
group average, consumers will see higher and truer latency values. An
unresolvable `/home` retains the previous all-device behavior.
