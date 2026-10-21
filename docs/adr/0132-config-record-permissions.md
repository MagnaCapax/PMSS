# ADR 0132: Keep per-user config records outside the general mode sweep

Date: 2026-10-19
Category: architecture
Status: Accepted

## Context

The general config sweep set directories to `0775` and files to `0664` before
resetting per-user JSON records to `0640`. During each run, records carrying
billing identifiers briefly had the general file mode. The `users` directory
also retained the general directory mode even though its writer uses `0750`.

## Options considered

- Keep the sweep and repair records afterward. This retains a temporary wider
  mode on every run.
- Exclude the `users` subtree from the sweep, then set its intended modes.
  Chosen because it preserves the existing modes of other config entries.
- Narrow every config entry. This would alter files read by the per-user panel.

## Decision

The recursive iterator skips `users` and everything below it. The `users`
directory converges to `0750`; regular top-level `*.json` records converge to
`0640`. Linked paths remain untouched. Other directories and files retain the
general `0775` and `0664` modes.

## Consequences

No sweep step applies a wider mode to per-user records. Existing root-side
readers continue to work. The fixture test records each mode application as
well as the final modes.
