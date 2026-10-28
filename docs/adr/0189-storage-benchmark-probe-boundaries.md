# ADR 0189: Storage benchmark probe boundaries

Date: 2026-10-26
Category: architecture

## Status
Accepted

## Context

The storage benchmark facade mixed CLI policy, file reservation and cleanup,
member-device inspection, fio command execution and metric aggregation. Fio
kept six independent read/write accumulators and duplicated their overflow,
latency and result handling. Operators consume terminal summaries; automation
consumes the ordered JSONL records. ADRs 0085 and 0178 govern read-only mounted
device mode and shared job execution.

## Options Considered

- Keep the mixed facade and the paired aggregation paths.
- Add a benchmark class hierarchy with separate read/write implementations.
- Move the existing responsibilities behind the stable facade and aggregate
  both directions through one record shape.

## Decision

Keep the public include and every existing function name. Place option policy
in `storageBenchmark/cli.php`, the file lifecycle in `file.php`, member-device
reads in `device.php`, and fio execution/decoding in `fio.php`. Shared records,
idle evidence and checked command fields remain in the facade. The existing
job controller, mounted-device controller, inspection and report modules stay
authoritative; no alternate implementations remain.

Use one direction-keyed accumulator and validation/projection path for fio.
Retain each direction's sample order and present-percentile count, including
zero samples. Initialize output keys in their historical order before filling
them. Failed jobs, malformed sections, integer overflow and nonfinite totals
still invalidate the complete result. Temporary-output cleanup still precedes
decoding and preserves execution/read exceptions.

## Consequences

- CLI help, option precedence, command bytes, exit codes, JSONL keys and ordering,
  reservation and inode checks, read-only flags and cleanup retain their contracts.
- Four focused modules replace the mixed orchestration file; paired read/write
  aggregation no longer needs separate control flow or independent variables.
- Existing characterization and safety tests retain their assertions, with
  namespace injection and source checks following the moved functions. Added
  cases lock asymmetric jobs, absent versus zero latency samples, strict job
  errors, missing job zero and ordered metric/raw payloads.
- Static checks run on the development control host. The lane runs the development
  suite on a testing machine before publishing; no benchmark or test runs locally.
- Mounted-device sysfs values reject NUL bytes before whitespace normalization.
  Malformed rotational samples retain the unknown value; malformed md state,
  level or degraded counts retain the existing status-read failure, including
  when a missing degraded counter would otherwise use the raid0/linear fallback.
  Ordinary whitespace and valid metadata retain their existing result shape.
