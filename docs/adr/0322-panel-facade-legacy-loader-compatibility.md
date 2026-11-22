# ADR 0322: Preserve complete-group loading with older customer loaders

Date: 2026-11-22
Category: architecture

## Status

Proposed

## Context

#1254 reports a newer memory facade paired with an older `scriptsIncFiles.php`.
Storage and statistics use the same batch guard. That older Files part has the
checked single-file loader but lacks the batch API introduced by 9dc129273.
Present, readable sibling helpers therefore remain unloaded. The cause of the
observed version mismatch is not established; delivery ordering is outside this
code fix.

## Options Considered

- Require a node update or coordinated delivery: leaves existing mixed copies
  unable to use helpers already delivered to them.
- Add a new shared compatibility file: adds another delivery prerequisite to
  the failure being repaired (ADRs 0022 and 0099).
- Retain the batch API and use the existing single-file API only when the batch
  API is absent, with a local complete-group preflight: chosen.

## Decision

Each of the three existing facades keeps its fixed sibling list and current
batch-loader path. A batch refusal remains final. Only absent batch APIs select
the compatibility path, which requires the older single-file API, checks every
sibling for regular-file status, symlink refusal and readability, then loads
them in order through that checked API. Its filename validation stays active.
Missing loader APIs or incomplete deliveries retain the unavailable result.

The small preflight is deliberately local to each independently delivered
facade. No general loader is copied and no new customer dependency is added.
Remove these compatibility branches only after the supported delivery baseline
guarantees the batch API is present before any of these facades can arrive.

## Consequences

Mixed versions can use complete existing helper groups without a host update.
The three bounded compatibility branches add maintenance work; regression
coverage checks both loader versions, include order, missing/linked/non-file
siblings, absent APIs, batch refusal and the saved-data Welcome Memory card.
Test execution stays on testing machines; devbox verification uses PHP syntax
checks on changed files. This does not repair the external delivery ordering.

## References

- #1254
- ADRs 0016, 0022, 0099 and 0198
