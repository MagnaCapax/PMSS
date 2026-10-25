# ADR 0178: Shared storage benchmark job execution

Date: 2026-10-25
Category: architecture

## Context

The file-backed, member-device and mounted-device benchmark modes each built
fio parameters and iterated execution and JSONL publication. Both device modes
also repeated dd offset selection, execution, publication and display. Mounted
device inspection and live summaries were mixed into the execution modules.

The consumers are operators reading terminal summaries and tools reading the
JSONL log. Test fixtures also load the stable facade to inject command failures.
ADR 0085 requires the mounted-device path to remain read-only and independent
of file allocation and member inventory.

## Decision

Keep the CLI, checked commands and file lifecycle in the existing facade.
Move catalogs and execution into `storageBenchmark/jobs.php`: one fio sequence
projects each mode's ordered parameter schema, runs the job, persists its result
and yields it to the caller for presentation. One dd sequence owns offset
selection, execution, persistence and the caller-supplied display format.
Delete the old single-job fio wrapper and the one-caller cleanup-registration
wrapper. No alternate execution loops remain.

Keep mounted-device inspection in `homeDeviceInspection.php` and both live and
saved presentation in `report.php`. The existing mounted-device controller
retains its unavailable-fio record and dd fallback. Mode policy remains with
the callers: job order, the short file-write duration, read-only flags, parameter
keys and nulls, error defaults, and successful versus failed result display.

## Consequences

- Three fio execution/logging sequences become one; two dd sequences become
  one. File reservation, inode-checked cleanup, checked command fields and
  md degraded-count validation retain their existing guards.
- Logging precedes result display and the next job. A thrown runner or failed
  publication cannot produce a fabricated success or start the next job.
- Parameter projection is explicit data because the three persisted schemas
  differ. It preserves field insertion order, including file-only null fields
  and size, and the member-device omission of `direct`.
- Characterization coverage locks catalogs, complete mounted-device JSONL
  bytes and text, success/failure cases, throwable identity and summary text.
  Existing safety and CLI characterization tests remain intact. Execution
  verification belongs on a testing machine; no benchmark runs on the control
  host as part of this refactor.
