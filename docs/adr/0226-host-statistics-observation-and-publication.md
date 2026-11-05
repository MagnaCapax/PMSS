# ADR 0226: Host statistics observation and publication

Date: 2026-11-02
Category: architecture

## Status
Accepted

## Context

ADR 0162 separated raw host-statistics parsing from acquisition. The remaining
facades still combined process/latency/pressure observations, log rendering,
publication and collection. Iostat also maintained separate invocation paths
for its default capture and injected runner. Six memory fields repeated the
same conversion, and a counter-validation wrapper had only test callers.

## Options Considered

- Keep the mixed facades and separate runner branches.
- Add collector classes and a configurable probe framework.
- Keep the existing includes, separate their responsibilities, and remove
  redundant adapters and dispatch state (chosen).

## Decision

`systemStats.php` retains collection and its sampling sequence. Its
`observe.php` module owns process, latency and pressure observations; `log.php`
owns the legacy line renderer and checked append. The existing `parse.php`
continues to own pure metric interpretation and formatting. One ordered memory
field map applies the existing KiB conversion and missing-field default.

`diskIostat.php` retains the checked writer and cron controller. Its
`observe.php` module owns supplemental probes and ordered snapshot assembly,
leaving the existing sample parser free of host observations. The controller
selects its runner once, then uses one invocation path. The default runner
retains capture status validation and the 180-second deadline. Publication
still precedes the public copy, and a failed publication prevents that copy.

Remove `pmssSystemStatsCounterIsValid`, whose repository callers were tests
only. Runtime parsers already use `pmssUnsignedDecimalIntParse` directly;
the wrapper's boundary assertions now exercise that same parser. No validation
is weakened, and no replacement implementation or alias is introduced.

## Consequences

- Existing collector includes and runtime entry points keep working. File paths,
  sampling intervals, probe order, key order, value types, log bytes, fallback
  policies, deadlines and filesystem guards retain their contracts.
- Observation, pure parsing and publication can be inspected separately without
  introducing a probe framework or changing cron orchestration.
- Namespace-based fault fixtures follow the moved functions. Characterization
  cases lock collection order, memory defaults and unit boundaries, the ioping
  gate, process exceptions/errors, injected runner failures and publication order.
- Local verification is limited to syntax and reference checks. The lane runs
  behavior tests on the testing machine; no host measurements or tests run here.

## References

- [ADR 0229: I/O sharing observation and audit history](0229-io-sharing-observation-and-audit-history.md)
  deliberately extends iostat serialized bytes with nine host fields after
  `time`; the original fourteen keys, their order and values remain unchanged.
- [ADR 0162: Host statistics parsing boundaries](0162-host-statistics-parsing-boundaries.md)
