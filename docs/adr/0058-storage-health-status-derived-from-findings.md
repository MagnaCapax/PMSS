# ADR 0058: Derive storage-health status from findings

Date: 2026-09-10
Category: architecture

## Status
Accepted

## Context
SMART, NVMe, and RAID independently maintained flags, severity, and OK state.
Snapshot JSONL, CLI reports, and RAID notices consume the resulting payloads.

## Options Considered
- Keep independent status mutations alongside each finding.
- Derive status once from the existing flags across all three backends.

## Decision
Use one flag classifier. Failure flags dominate; standby and informational
counter increases stay OK; other findings warn. Metric growth returns flags
without a second warning-metric list. Entry finalization takes an optional error
after flags, replacing its internal severity argument; all callers migrate together.

## Consequences
Payload fields, ordering, thresholds, probe behavior, and RAID duplicate flags
are preserved. The 365-payload snapshot and explicit SMART/NVMe cases lock these
contracts. New findings must respect the classifier's failure/informational categories.
