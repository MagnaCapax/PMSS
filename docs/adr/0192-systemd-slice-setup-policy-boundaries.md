# ADR 0192: Systemd slice setup policy boundaries

Date: 2026-10-27
Category: architecture

## Status

Accepted

## Context

The shared user-slice setup controller calculated host sizing, interpreted mount
policy, published manager drop-ins, maintained legacy shadows and refreshed live
limits in one function. CPU weight, IO weight and TasksMax each implemented the
same numeric override rule. Descriptor limits and log namespaces independently
assembled the same managed-writer metadata.

## Options Considered

- Keep all policy interpretation and publication inside the controller.
- Introduce a generic systemd drop-in writer, duplicating the existing managed
  file convergence helper.
- Separate sizing, I/O policy, related drop-ins and live refresh, using the
  existing writer directly and representing repeated policy as data.

## Decision

Keep `systemdSlicesEnsure.php` as the stable updater include and setup entrypoint.
Load four sibling modules through the existing runtime loader:

- `systemdSliceSizing.php` returns the existing template token map. One ordered
  table applies the numeric override rule for CPU weight, IO weight and TasksMax.
- `systemdSliceIo.php` renders optional latency and per-mount directives using
  the existing device and limit validators. Template reading remains between
  latency lookup and mount lookup, preserving failure and diagnostic ordering.
- `systemdSliceDropins.php` converges the legacy shadow and the manager's ordered
  descriptor/log-namespace artifacts. One publication loop calls
  `pmssRefreshManagedPathFile()` directly with the original options and bytes.
- `systemdSliceRuntime.php` reloads root protection and refreshes only active
  user slices that meet the existing legacy-cap predicates.

The descriptor-only internal installer is replaced by the manager artifact
installer; its only runtime caller and its development tests move together.
There is no compatibility alias or parallel implementation. The controller
retains template preflights, successful-slice gating, reload order and root's
replacement-before-removal protection.

## Consequences

Three separate scalar override paths become one; two manager artifact write
paths become one. The controller no longer interprets sizing or I/O policy or
enumerates live units. Numeric acceptance, memory floors/caps, CPU fallback,
TasksMax thresholds, existing v1/v2 selection and command bytes remain unchanged.
Production remains pinned to cgroup v1 under ADR 0019.

The existing managed writer retains path validation, ownership and modes.
This changes no authentication, credentials, public exposure or permission
policy. No production command is run during development verification.

`SystemdSlicesCharacterizationTest` records 19 setup traces using in-memory
dependencies and reflected production functions. Snapshots cover rendered bytes,
writer options, diagnostics, operation order, missing/unreadable templates,
failed writes, legacy shadows, host sizing, I/O policies and live-limit refresh.
The snapshots are captured from the original controller on a testing machine;
the temporary baseline implementation is removed after capture. Existing source
assertions follow the module that now owns manager publication.

## References

- [Architecture overview](../architecture.md)
- [Production cgroup v1 pin](0019-production-cgroup-v1-pin.md)
- [Update workflow](../update.md)
