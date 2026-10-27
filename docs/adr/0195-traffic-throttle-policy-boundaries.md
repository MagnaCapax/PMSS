# ADR 0195: Traffic throttle policy responsibility boundaries

Date: 2026-10-27
Category: architecture

## Status
Accepted

## Context
The throttle-policy include combines network configuration, historical tier
tables, tier normalization and ordering, progressive arithmetic, and planning.
Its planner separately constructs four result records and repeats message framing.

## Decision
Keep configuration and account-cap projection at the existing include. Move all
tier rules to `user/trafficThrottle/tiers.php` and progressive arithmetic and
planning to `plan.php`. Preserve every existing function name and signature.

Use one result construction and shared log framing after the existing ordered
decision: account-plan cap, matched tier, progressive cap, unchanged base cap.
Let the tier selector handle empty catalogs through its existing no-match path.
Calculate progressive results directly without separate raw/effective temporaries.
Retaining the mixed file or adding adapters would preserve the duplicated concepts.

## Consequences
Configuration defaults, legacy-tier recognition, validation, stable tie ordering,
integer caps, numeric clamps, and message bytes stay unchanged. In particular,
an unmatched tier must not replace a literal negative base cap with the selector's
clamped result. Locks, persistence, cron scheduling and FireQOS remain with their
existing owners. No data migration or provisioning dry run is required.

Existing characterization cases and six additional serialized-result snapshots
lock precedence, malformed tiers, zero/negative caps and progressive grace.
Run these on testing machines; only static checks run on the control host.
