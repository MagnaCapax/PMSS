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

SMART passes its ordered patterns directly to the shared parser; NVMe consumes
captured stdout directly, retaining failed/empty-probe handling.

NVMe rejects NUL-containing device paths before command lookup or shell quoting,
returning the existing unavailable (`null`) result. Valid path bytes, command
arguments and successful snapshot payloads remain unchanged.
`StorageHealthNvmePathSafetyTest` covers leading, embedded and trailing NULs
and preserves whitespace and shell metacharacters in valid arguments through
an isolated probe stub. These tests run only on the testing machine.

NVMe also treats absent, null and non-scalar inventory paths as unavailable
before string conversion or command lookup. Arrays, resources and objects
cannot warn, throw through conversion hooks or supply a probe target. Scalar
casts, valid path bytes, NUL rejection and successful payloads retain their
contracts. The same path-safety test covers malformed values with warnings
promoted to exceptions and command lookup intercepted, without probing devices.

The same NVMe boundary rejects non-scalar non-null optional inventory metadata
(`kname`, `model`, `serial`, `rota`, and `size`) before command lookup. These values
are cast for the probe lock or final entry; rejecting containers, resources and
objects prevents warnings and conversion hooks before or after a probe. Missing
and null metadata retain their defaults; scalar conversions, probe arguments,
capture timeout and successful payloads remain unchanged. The inventory cases in
`StorageHealthNvmePathSafetyTest` cover both refusal and compatibility through
isolated command shims on the testing machine.

NVMe also rejects NUL-containing probe stdout before metric parsing, using the
same `null` unavailable result as failed or empty probes. Corrupt text cannot
yield a plausible partial snapshot. NUL-free output, including unknown text,
whitespace, repeated metrics and CRLF lines, retains its existing payload and
first-match behavior. `StorageHealthSnapshotNvmeTest` intercepts capture and
parsing to cover refusal ordering, command bytes, timeout and valid payloads
without accessing devices; execution stays on the testing machine.

## References

- [Storage findings](0058-storage-health-status-derived-from-findings.md)
- [Function contracts](../contracts.md)
