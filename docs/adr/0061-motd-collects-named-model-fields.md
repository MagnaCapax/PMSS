# ADR 0061: MOTD collects named model fields

Date: 2026-09-11 — Status: Accepted
Category: architecture

## Context and options
MOTD collection returned four positional tuples, then unpacked and rebuilt them into the renderer's named model. Retaining those adapters preserves needless representations; introducing collector classes adds lifecycle concepts to stateless probes.

## Decision
Collect directly into the existing named model in `scripts/lib/motd/model.php`. Network fallback probing and health-log projection occupy dedicated modules. `Motd` retains its public generation/rendering methods and PAM output handling.

## Consequences and verification
Four tuple contracts disappear. Probe order, commands, defaults, ANSI bytes, template substitutions, and storage-warning policy remain unchanged. The existing MOTD tests and the network/warning byte matrix in `motdRenderTest.php` lock rendering behavior; see [refactoring contracts](../refactoring.md#minimal-contract-rules-joukahainen).
