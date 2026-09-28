# ADR 0077: Separate rTorrent process inspection and lifecycle

Date: 2026-09-28
Category: architecture

## Status

Accepted

## Context

The process library mixed PID discovery, state parsing, SCGI restart decisions,
signals, and launch tracking in one file. Restart also repeated the same snapshot
logging sequence before and after signaling.

## Options Considered

- Keep the combined library and duplicate snapshot sequence.
- Change watchdog callers to load multiple modules directly.
- Keep the existing facade and move inspection and lifecycle behind it (chosen).

## Decision

`rtorrent/process.php` remains the load point for watchdog and maintenance
callers. Inspection owns PID normalization, probes, and state parsing.
Lifecycle owns SCGI decisions, signals, starts, and restarts. One snapshot
logger emits the existing heading and row callback sequence for both phases.

## Consequences

No caller path or public function signature changes. The repeated snapshot
flow and queue text construction are removed. Existing process and watchdog
tests plus a callback-order characterization test lock the observable behavior.
No deployed state or migration is needed.

## References

- [rTorrent watchdog accept-queue gate](0020-rtorrent-watchdog-accept-queue-wedge-gate.md)
- [Contracts](../contracts.md#utilities-script-contracts)
