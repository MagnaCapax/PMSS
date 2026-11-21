# ADR 0317: Recover cgroup-v1 writes with a complete recursive throttle pair

Date: 2026-11-21
Category: architecture

## Status
Proposed

## Context

Issue #1296 reports account slices on BFQ/md hosts with positive BFQ read
counters, zero BFQ write counters, and positive throttle write counters.
The BFQ-first selector takes both directions from BFQ whenever either is
positive. It therefore discards the available throttle writes. The cause of
the missing BFQ write attribution remains unproven; this fix recovers only
account-attributed writes already exposed by the kernel.

Write operations feed monthly IOPS enforcement. Combining accounting families
without changing the source marker would charge cumulative throttle writes as
new usage. ADR 0045's source-change reseed guard must remain effective.

## Options Considered

- Keep BFQ for both directions: leaves available writes unmetered.
- Switch both directions to throttle: discards the preferred BFQ read counters.
- Retain BFQ reads and substitute a complete throttle write pair only when
  BFQ write bytes and operations are both zero (chosen).
- Attribute root-cgroup writes to accounts: not implemented; aggregate root
  counters do not identify the responsible account.

## Decision

Refine ADRs 0045 and 0161 at the shared `resources/v1.php` acquisition boundary.
When selected BFQ bytes and operations both report zero writes, read the
recursive throttle bytes and operations. Use their writes only when both files
parse successfully and throttle write bytes are positive. Each direction keeps
bytes and operations in the same family. Positive BFQ writes retain precedence.
The existing selector's public return contract stays unchanged.

The combined sample carries `io_source=bfq-read-throttle-write`. Existing source
and scope guards reseed all four IO deltas on entry and exit. Billing still
requires the complete IO group; telemetry retains its partial-source behavior.
No validation, overflow checks, runaway ceilings or enforcement limits change.

## Consequences

- Both collectors recover available account-attributed throttle writes without
  summing BFQ and throttle counters or replacing BFQ reads.
- Source transitions intentionally leave one interval uncharged, as in ADR 0045.
- This does not reconstruct historical writes or recover writes attributed
  exclusively to the root cgroup. Logical md IO and member-disk IO may differ.
- Hermetic `resourceLogWriteFallbackTest` covers both projections, invalid and
  incomplete fallback sources, BFQ precedence, and source-transition reseeding.
  Execute on the testing VM; devbox verification is changed-file PHP lint only.

## References

- Issue #1296.
- [BFQ-first accounting and reseeding](0045-per-user-blkio-accounting-bfq-first-with-enforcement-reseed-guard.md).
- [Shared cgroup-v1 acquisition](0161-resource-cgroup-v1-acquisition.md).
- [Kernel blkio counter definitions](https://docs.kernel.org/admin-guide/cgroup-v1/blkio-controller.html).
