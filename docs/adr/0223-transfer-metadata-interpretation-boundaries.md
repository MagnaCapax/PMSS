# ADR 0223: Transfer metadata interpretation boundaries

Date: 2026-10-31
Category: architecture

## Status
Accepted

## Context
Post-transfer helpers mixed session decoding with inode-safe file mutation,
category probe/preservation ordering with JSON storage, and aggregate probe
construction/parsing with ownership checks and advisory reporting. Bencode and
Qt text decoding each maintained a byte-at-a-time digit scan. Bencode replacement
also kept a separate changed-value branch, while category paths had separate
exact-home and descendant projections.

## Options Considered
- Keep the mixed files and simplify only their local expressions.
- Introduce a general transfer parser or pluggable probe framework.
- Separate the existing responsibilities while keeping their public includes
  and helpers, and remove redundant scanner and projection states.

## Decision
Choose focused modules without adding a parser framework:

- `sessionBencode.php` owns the pure bencoded transformation;
  `sessionRewrite.php` retains the inode-safe session file controller.
- `qbittorrentCategoryPreservation.php` owns remote probe construction and
  preservation order; `qbittorrentCategories.php` retains JSON reads, merges,
  option normalization and its original include contract. Legacy config and Qt
  readers remain in their existing modules.
- `homeStats.php` owns aggregate probe construction, interpretation and the
  completeness calculation; `completenessVerify.php` retains ownership checks,
  execution, diagnostics and advisory orchestration.

Use bounded `strspn()` scans for digit runs. Accumulate replacement counts
directly, share exact/descendant category path projection, and emit both category
probes through one ordered traversal. Remove the unreachable QString `-1`
success branch: the existing odd-length guard rejects that sentinel first.

## Consequences
- Existing function names, includes, probe bytes, validation, persisted JSON,
  permissions, log messages and exit behavior remain the compatibility boundary.
  No second parser or preservation implementation remains in-tree.
- Bencode still accepts its historical token stream rather than imposing new
  structural validation. It normalizes string lengths, requires at most nine
  length digits, and retains earlier replacement counts on a later parse failure.
  Identical account names still bypass parsing.
- Qt escape decoding still consumes at most two hexadecimal digits, preserves
  trailing backslashes, and returns null for the `-1` QString length. Source
  category preference, existing-local-category precedence and overflow refusal
  remain unchanged.
- `UserTransferMetadataCharacterizationTest` locks parser bytes, consumed
  offsets, partial counts, category projections, aggregate precedence, threshold
  boundaries and the complete category probe script without running commands.
  Existing transfer tests retain end-to-end filesystem and probe coverage.
- The trade-off is three additional focused include files. No CLI option,
  dependency, credential handling or deployment step is introduced. Static
  verification runs in the dev box; characterization execution belongs on the
  testing machine and CI.

## References
- [Transfer contracts](../contracts.md#user-environment-orchestration--scriptslibupdateusersphp-and-submodules).
- [ADR 0092: inode-safe account data movers](0092-inode-safe-account-data-movers.md).
