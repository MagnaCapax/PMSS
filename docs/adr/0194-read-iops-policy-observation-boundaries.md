# ADR 0194: Read IOPS policy observation boundaries

Date: 2026-10-27
Category: architecture

## Status
Accepted

## Context
Legacy read-IOPS cap policy also performed sysfs storage classification. Shadow
policy combined settings, pressure windows and quota allocation, rebuilding
account facts inside each exponent variant. These responsibilities made it
harder to review policy independently of observation and variant arithmetic.

## Options Considered
- Keep the mixed modules: small include surface, coupled responsibilities.
- Introduce a new allocation framework: unnecessary interfaces and migration.
- Retain the includes and APIs, separating existing responsibilities internally.

## Decision
Keep `readIopsPolicy.php` and `dynamicIopsPolicy.php` as their existing load
points. Move storage observations to `readIopsStorage.php`, pressure sampling
to `dynamicIopsPressure.php`, and allocation to `dynamicIopsAllocation.php`.
The allocation implementation is loaded by its policy facade; no second
allocator or alternate enforcement path remains.

Build quota validity and invariant account rows once per allocation, then copy
the cohort for each exponent. Only shares, floor charges and proposals vary.
Retain the share field's position so JSONL bytes remain compatible. Nominal
IOWeight uses nullable precedence directly, and inline/catalog service tiers
converge on one ceiling decoder. Existing coercions and guards remain intact.

## Consequences
- Sysfs observations, pressure sampling and pure allocation have distinct owners.
- Variant evaluation no longer repeats account floor, ceiling and legacy-cap policy.
- Existing function names, include paths, md exclusion, fallback precedence,
  floating-point arithmetic and shadow-only behavior remain unchanged.
- `DynamicIopsShadowTest` locks full payload bytes for eight scenarios;
  `CgroupReadIopsDerivationTest` characterizes weight and tier resolution and
  retains its storage-topology coverage. Tests run on a testing machine.
- No new configuration, dependency, public endpoint, cgroup-v2 behavior or
  controller writer is introduced.

## References
- ADR 0063: passive host I/O ceiling estimates.
- ADR 0177: shadow pressure and quota IOPS allocation.
