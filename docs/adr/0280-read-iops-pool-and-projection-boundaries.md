# ADR 0280: Read-IOPS pool and projection boundaries

Date: 2026-11-13
Category: architecture

## Status
Accepted

## Context
The pure allocator combined host pressure/latency steering with account quota
allocation. Proposed and counterfactual results carried separate charged-pool,
water-fill and proposal paths. Demand construction first built a bounds map,
then passed it through a single-caller helper before annotating the same rows.

## Options Considered
- Retain the paired paths and intermediate bounds representation.
- Introduce allocation classes or a new enforcement path: unnecessary interfaces.
- Separate host-pool calculation and share ordered projection maps (chosen).

## Decision
Keep the existing policy and allocation includes and public allocation API.
`dynamicIopsPool.php` owns pressure, latency/probe selection and iowait curbing,
returning the existing ordered result record with empty variants. The allocator
adds account facts, the cohort fallback and quota variants to that record.

Key floor lifts, charged pools and water allocations by `proposed`/`would_be`.
Pool charging, water filling and proposal generation each have one implementation
over those keys. Keep the explicit-account floor-charge distinction, legacy
proposal selection and counterfactual failure result explicit. Construct bounded
demand and its row annotations together; remove the bounds map and its
single-caller demand helper. No parallel allocator remains.

## Consequences
- Three paired calculation paths become three shared paths; the separate
  bounds-to-demand handoff disappears: concepts delta -4.
- Runtime PHP decreases by five physical lines across the three runtime files.
  The pool boundary is pure and adds no host probes or configuration knobs.
- Existing numeric conversions, summation order, rounding, tier/explicit caps,
  fallback precedence and JSON field order stay unchanged. Existing production
  policy, controller writers and cgroup-v2 handling are untouched.
- Literal full-payload characterization covers eleven allocation scenarios;
  a separate case locks mixed numeric/string account keys and enforced caps.
  Existing quota, work-conserving, latency and enforce-cycle tests remain intact.
- Local verification is changed-file PHP syntax and deleted-symbol searches.
  Behavior tests and controlled operational verification run on the testing VM;
  no allocator or cron is executed on the dev box.

## References
- [Read-IOPS observation boundaries](0194-read-iops-policy-observation-boundaries.md)
- [Work-conserving read caps](0256-read-caps-are-work-conserving.md)
- [Resource contracts](../contracts.md)
