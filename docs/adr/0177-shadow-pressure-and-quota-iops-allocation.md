# ADR 0177: Shadow pressure and quota IOPS allocation

Date: 2026-10-25
Category: architecture

## Status
Accepted for shadow measurement only; enforcement remains separate work.

## Context
Issue #1154 describes pressure-sensitive, quota-proportional I/O allocation.
Its reviewed algorithm can be measured independently of cgroup-v1 placement
(ADR 0176). Existing read-IOPS derivation and all enforcement guards must remain
unchanged while collecting evidence. Kernel PSI alone is not proof of contention.

## Decision
Add `cgroup.dynamicIops` with `mode=off` by default and `shadow` as the only
active mode. The existing BFQ cycle supplies its already bonus-adjusted, clamped
weights, retaining the bonus reader and its 300% guard in their current location.
No new timer, benchmark, customer artifact or cgroup-v2 path is introduced.

Shadow reads complete records from the last 64 KiB of each live history and `.1`.
It averages distinct five-minute buckets over 5/15/60 minutes, logs I/O, memory
and CPU full pressure separately, and blends I/O with weights 12/4/1. A linear
multiplier drops from 10 at 5% pressure to 2 at 15%; both endpoints are configured.
Host capacity is the maximum of a passive estimate refreshed within 24 hours and
the latest successful matching-device 4k random-read benchmark in the bounded
tail. Missing benchmarks do not prevent using passive capacity, and vice versa.

Both quota exponents 1.0 and 0.5 are logged, normalized by median quota. The
default proposed floor is 15; account `resourceIOPSReadMin` overrides it, and
the existing plan ceiling always wins. Charge 25% of the floor lift to the pool,
then round proposals to two significant figures and reapply floor and ceiling.
Explicit IOPS settings are never replaced. Missing capacity, complete pressure
windows or any account quota/weight produces the legacy proposal instead.

One root-only JSONL record per cycle contains settings, input metrics, legacy
caps and proposed shares/caps, with daily rotation, 14 generations and a 16 MiB
split. Dry runs and disabled mode create no shadow log. Logging failures warn
without changing the existing BFQ exit result. No proposal reaches a writer.

## Consequences
This implements the read-only measurement phase. Enforcing proposals, removing
the md exclusion, bandwidth/write allocation and customer at-limit notices remain
out of scope pending measurements and their enforcement/counter prerequisites.
Rollback is `mode=off`; no account configuration or throttle state needs repair.
