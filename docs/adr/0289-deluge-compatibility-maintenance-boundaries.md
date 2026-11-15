# ADR 0289: Deluge compatibility maintenance boundaries

Date: 2026-11-15
Category: architecture

## Status
Accepted

## Context
Deluge compatibility maintenance mixes line transformations with four copies of
the read/change/write lifecycle. Shared file helpers live in the WebUI patch
module, although the three other patches also depend on them. The system path
roots repeat in each patch's candidate list. The installer also owns discovery,
validation and cleanup of obsolete Python 2 artifacts, with three separate
success-count updates.

Existing public helpers, includes, diagnostics and patch applicability are
compatibility contracts. Required WebUI repair must still succeed before a venv
is published (ADR 0129). No validator or installed dependency policy may change.

## Options Considered
- Retain the mixed responsibilities and repeated lifecycle decisions.
- Introduce patch classes or a general source-rewriting framework: unnecessary
  machinery for four established repairs.
- Keep the existing entrypoints and transformations, sharing only the file
  lifecycle and path-root projection; isolate legacy artifact maintenance.

## Decision
Use the third option. `deluge/patchFile.php` owns the existing guarded reader,
bounded search and complete-write helper plus one file-patch controller.
Patch callbacks only inspect/change the acquired lines and report applicability;
their existing shape-refusal diagnostics remain local. Accepted unchanged input
returns without writing or dry-run logging. Changed input uses the existing
writer, including its target recheck and newline normalization.

The existing patch catalog projects an ordered list of system roots onto each
relative source path, appending store candidates only for the WebUI entry.
`delugeWebBasePatch.php` retains its public entrypoints and venv preflight.
`deluge/legacyArtifacts.php` owns the original artifact helpers, with one count
update after successful removal or an accepted dry run. The original installer
loads it; discovery, guards, command bytes and failure messages are unchanged.

## Consequences
- Runtime source falls from 453 to 446 lines across five files. The four patch
  lifecycle owners become one, three cleanup count sites become one, and four
  system-root catalogs become one. Conservatively charging two new module
  boundaries and the new controller against those eight removed repetitions
  leaves five fewer structural concepts.
- Original include paths and public function signatures remain available.
  There is one implementation of each operation; no migration or alternate mode
  is introduced. The helper modules are below the automatic installer glob.
- `DelugeCompatibilityCharacterizationTest` exercises original public entrypoints
  and reflected controllers, so it can also characterize the clean baseline.
  It locks exact patch bytes, CRLF normalization, idempotence without rewriting,
  dry-run messages, malformed and redirected targets, logger throwables, candidate
  ordering, status output, command arguments and cleanup success/failure counts.
- Test files execute only on the testing VM. Devbox verification is changed-file
  PHP syntax checking, reference inspection and diff review. No installation,
  removal command or operational dry run is executed here; the characterization
  uses temporary trees and substitutes directory-removal and candidate probes.

## References
- [Curated Deluge entries and required WebUI repair](0129-curated-deluge-virtualenv-entries.md#webui-base-path-compatibility-2026-10-24)
- [Installer contracts](../contracts.md#application-installers-contracts)
