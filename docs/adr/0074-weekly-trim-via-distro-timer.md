# ADR 0074: Weekly TRIM via the distro timer

Date: 2026-09-28
Category: operations
Status: Accepted

## Context

PMSS scheduled no TRIM. A manual batch on 2026-09-15 trimmed 17.2 TiB across
four guests in 27–45 seconds each, with load falling.

## Decision

Enable the distro's weekly `fstrim.timer` from the batched PMSS update. Start
the first `fstrim.service` run without blocking the update. Leave a masked
timer untouched; masking is the operator opt-out.

## Consequences

Freed blocks return to discard-capable backing storage. No read/write
performance improvement is claimed. Operators can opt out with
`systemctl mask fstrim.timer`.
