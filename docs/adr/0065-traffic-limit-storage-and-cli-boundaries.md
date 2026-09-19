# ADR 0065: Traffic limit storage and CLI boundaries

Date: 2026-09-19
Category: architecture
Status: Accepted

## Context
The traffic-limit library mixes quota state, throttle enforcement, and CLI execution.
Four forwarding aliases duplicate the existing integer-setting storage API.

## Decision
Keep the entrypoint; separate throttle operations, shared CLI execution, and command specifications.
Call integer-setting storage directly. Retain the quota persistence adapter because it owns GiB errors and zero-removal policy.
Keeping aliases or introducing another storage abstraction would preserve the duplication.

## Consequences
CLI characterization fixtures lock output, logs, file bytes, modes, and zero/unset behavior; fallback and override hooks remain intact. All internal alias callers migrate together.
