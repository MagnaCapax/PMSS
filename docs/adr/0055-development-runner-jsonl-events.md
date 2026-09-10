# ADR 0055: Development runner lifecycle JSONL shards

Date: 2026-09-10
Category: architecture

## Status
Accepted

## Context

Development launchers converge on `development/codex-run.sh`. Its previous
event file lived in a temporary prompt workspace, swallowed write errors,
manually escaped JSON strings, and could omit the terminal event when prompt
assembly or executable lookup failed. Operators need persistent, separate run
records and a consistent timestamp format to inspect launcher failures.

## Options Considered

- Keep temporary per-workspace files: low change cost, but records disappear
  with temporary artifacts and failures remain ambiguous.
- Append all runs to one file: easy collection, but unbounded growth and mixed
  runs complicate inspection.
- Use one dated shard per run with explicit path overrides: keeps each run
  independently inspectable while preserving existing collector destinations.

## Decision

Default to `log/codex-run/YYYY-MM-DD/<run-id>.jsonl` within the checkout.
Use UTC `YYYY-MM-DD HH:mm:ss` timestamps, an explicit `timezone` field, and retain
the established event fields.
Explicit `--event-log` and `PMSS_CODEX_RUN_EVENT_LOG` destinations remain supported
in that precedence order. PHP encodes JSON and locks each append; write failure
is visible and nonzero. The initial write must succeed before assistant launch.

The shell owns one terminal-event EXIT trap after argument parsing. Prompt and
invocation failures retain their actual status. Help and invalid CLI arguments
do not start a run. SIGKILL and host failure can leave an incomplete shard.

## Consequences

- Runner failures become inspectable without scraping prose or assuming an
  assistant exit means files changed.
- Existing collector paths remain usable, with the requested timestamp change.
- PHP CLI is required for logging; it is already a PMSS development prerequisite.
- Local shards accumulate until the operator's normal artifact retention removes
  them. No launcher deletes old logs or unrelated temporary work.

## References

- `development/README.md`
- `development/lib/codex-events.php`
- `development/lib/codex-run-lifecycle.sh`
- `scripts/lib/tests/development/CodexRunnerEventsTest.php`
