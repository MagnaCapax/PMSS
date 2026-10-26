# ADR 0173: Storage device parsing boundaries

Date: 2026-10-24
Category: architecture

## Status
Accepted

## Context
SMART and NVMe used separate integer-pattern loops and threshold branches.
NVMe also mixed command execution with text interpretation. The shared
entry/finding helpers were coupled to runtime loading through common.php.

## Options Considered
- Keep two independent parsing and threshold implementations.
- Introduce a configurable backend framework, increasing the policy surface.
- Share ordered algorithms behind pure device parsing and finding modules.

## Decision
Keep backend entrypoints and probe policies. Move text interpretation into
deviceParse.php and pure entry/finding logic into deviceMetrics.php. One matching
loop accepts caller-selected chunks; one threshold loop selects the first reached
tier per metric. SMART supplies individual lines; NVMe supplies the whole response.
Keep health-line absence as a nullable observation instead of separately tracking
presence, normalized health and a success boolean. Remove the redundant SMART
194 temperature pattern already covered by the general temperature pattern.

## Consequences
Two matching algorithms and two threshold algorithms become one of each.
The pure modules need no runtime, command or filesystem imports. Existing facade
and backend paths remain stable, with no parallel implementations. Counter casts,
Kelvin conversion, severity policy, key/flag order, matching precedence, legacy
CRC history, probe locks and timeout handling retain their existing behavior.
The existing SMART/RAID payload hash and NVMe probe characterization cases lock
the contracts; all execution verification runs on a testing machine.

History ingestion skips records whose selected kind or device/array key is a
JSON container, before string conversion. Scalar keys, missing/null defaults,
encounter order and last-entry precedence remain unchanged. SMART treats
non-array persisted metrics as absent history, matching NVMe; valid counters
and legacy CRC migration retain their existing results. The SMART failure
tests cover malformed history without probing real devices.

Parsers pass findings directly to finalization; NVMe adds metrics to the base
entry without a mutable copy. Reports likewise avoid redundant color state,
integer casts and empty-timestamp branches, retaining field order and bytes.

SMART/NVMe probe counters use the shared bounded unsigned-decimal parser before
integer conversion. Overflowed counters retain the existing unavailable (`null`)
metric shape instead of reporting a saturated integer. NVMe temperature validates
the raw sample before Kelvin conversion. Representable values, leading zeroes,
SMART last-line precedence, NVMe first-match precedence, payload ordering and
thresholds remain unchanged. Parser tests cover ATA/SCSI counters and both
temperature units at the integer boundary without running storage probes.

## References
- [Storage findings](0058-storage-health-status-derived-from-findings.md)
- [Function contracts](../contracts.md)
