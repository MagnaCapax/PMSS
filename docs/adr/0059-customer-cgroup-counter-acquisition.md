# ADR 0059: Shared customer cgroup counter acquisition

Date: 2026-09-10
Category: architecture

## Status
Accepted under the operator-approved architectural simplification run.

## Context
Stats and memory-pressure helpers duplicated counter paths, unlimited-sentinel
handling, and memory field selection. The pressure reader also copied local
counter variables into separate classification and output arrays.

## Options Considered
- Keep page-specific readers: retains parallel implementations of the same rules.
- Add a customer library: requires a new guiv delivery dependency (ADR 0022).
- Reuse `scriptsInc.php`: shares acquisition within the existing delivery set.

## Decision
Use the existing customer helper for one ordered counter-path builder, one
unsigned counter reader, and one memory-field schema. Build pressure output
fields once and substitute anonymous-memory values only for classification.
Keep directory-detection precedence and all v1 OOM/pressure rules unchanged.

## Consequences
Three path builders become one; two counter readers and two memory schemas each
become one. Classification consumes the output fields instead of a second map.
Pre-refactor payload snapshots and counter fixtures lock behavior. No new
customer delivery file, operator-tree dependency, or cgroup-v2 feature is added.

The memory-stat breakdown parser skips digit-only values that overflow to a
non-finite float, just as it skips malformed counters. An unavailable anonymous
counter retains the existing current-memory fallback. The memory-status byte
formatter returns `n/a` for non-finite input. Finite counters, field precedence,
and valid status payloads remain unchanged; overflow fixtures and the existing
payload snapshots cover these boundaries.

## OOM recency decision (2026-10-05)

The cgroup-v1 `oom_kill` counter is cumulative. A nonzero lifetime count is
history, not evidence of current pressure. Treating it as HIGH forever also
keeps showing RAM-upgrade advice after the last kill is long past.

Options considered: classify every nonzero count as HIGH; read a prior sample
from the account home; or retain the prior sample under root ownership. The
first option latches indefinitely. The second lets a tenant replace the file
between validation and read, potentially blocking the root metrics collector.

The metrics collector keeps the baseline and last observed increase in locked,
root-owned counter state under `/var/run/pmss/oomStatus` (ADR 0078). It only
writes the bounded projection into the account home using the managed writer.
The panel reads that projection as the owning user. HIGH from cgroup-v1 OOM
requires an observed count increase within 24 hours and a fresh sample. A
first sample of a nonzero count has no known event time and does not qualify.

The runtime directory is transient: after reboot, the first collection seeds a
new baseline. A tenant can alter only their own panel projection; root never
uses that file as input. Existing live memory-pressure thresholds are unchanged.
