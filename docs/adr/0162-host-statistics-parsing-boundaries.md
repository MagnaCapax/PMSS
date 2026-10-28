# ADR 0162: Separate host statistics parsing from acquisition

Date: 2026-10-23
Category: architecture

## Status

Accepted

## Context

System statistics and iostat combined pure parsing with command execution,
filesystem observations and publication. Both interpreted PSI percentages with
their own validation. Iostat found the final header in one traversal, then
rescanned the remaining lines for its group row before collecting extra probes.

## Options Considered

- Retain the combined libraries and independent PSI validation.
- Move functions into smaller files without changing the duplicated mechanisms.
- Separate parsing and acquisition, share PSI validation, and select the latest
  iostat sample in one pass (chosen).

## Decision

Keep `systemStats.php` and `diskIostat.php` as the existing entry points.
`systemStats/parse.php` owns pure CPU, disk, memory and PSI interpretation;
the facade retains collection, log publication and its legacy line renderer.
`diskIostat/devices.php` owns discovery, topology and command construction.
`diskIostat/sample.php` projects only the eight iostat columns without probes;
the facade appends PSI, latency and metadata in their historical order before
the existing snapshot writer runs.

Both PSI consumers use one field validator. System-stats formatting retains its
required first field, nullable later fields and one-decimal rounding; iostat
retains its nullable, unrounded full-average value. One iostat traversal resets
the selected group at every header and keeps its first group row. No alternate
parser or compatibility implementation remains.

## Consequences

The former header-index/rescan protocol and independent PSI validation are
removed. All existing callable names, output keys and types, column aliases,
error messages, deadlines, device checks and publication guards remain intact.
Consumers keep their existing includes; the new modules travel with the normal
repository snapshot. Characterization tests freeze shared PSI results, CPU/disk
formatting, last-header selection, truncated rows, alias precedence, missing
samples and assembled field order. Fault-injection tests load the module that
now owns the relevant operation. Verification runs on testing machines.

The shared runtime ioping parsers accept decimal latency fields and reject
malformed numbers or non-finite millisecond conversions. Invalid request rows
are skipped in order, and an invalid summary average retains the nullable
failure result. An even-sized median halves its middle values before adding
only when their sum overflows; ordinary median arithmetic is unchanged.
`IopingOutputSafetyTest` covers these boundaries with pure fixtures, including
unit conversion, zero, sample ordering, and very large finite latencies.

The shared ioping capture checks its exit status before interpreting stdout or
stderr. Failed, timed-out and lock-contended captures retain the nullable
unavailable result even when partial output contains valid samples or a summary.
Successful captures retain stream precedence, trimming, mean/median selection,
command quoting, lock wrapping and the 15-second deadline. Isolated capture
fixtures in `IopingOutputSafetyTest` cover both streams without running probes.
