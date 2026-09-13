# Logging & JSON Events

Long-running PMSS operations should emit structured logs for traceability.

## Fields (recommended)
- `timestamp` ISO-8601
- `event` short identifier (e.g., `step`, `phase`, `command`)
- `level` info|warn|error
- `step` human label
- `rc` exit code
- `duration` seconds (float)
- `host` hostname
- `distro` name/codename/version
- `correlationId` optional request/run id

## Current Emitters
- Updater `runStep()` and profiling helpers already write JSON lines to `/var/log/pmss-update.jsonl`.
- Other scripts should align to these fields when emitting JSON for consistency.

## Storage
- Text logs: `/var/log/pmss/*.log`
- JSON events: `/var/log/pmss-update.jsonl`
- Per-user action logs:
  - Consolidated stream: `/var/log/pmss/users.log` + `/var/log/pmss/users.jsonl` (JSONL), emitted via `userLifecycle` helpers.
  - Per-user files: `/var/log/pmss/users/<username>.log` (best-effort text lines for actions taken on behalf of users).

## Runbooks
See `docs/runbooks/update-failures.md` for quick diagnosis steps.

## Counter-state persistence
Resource and ingress accounting share `pmssCounterStateUpdate()`. Its writer
checks rewind before truncating, stops on truncate or write failure, and verifies
the full payload and flush result. Persistence or mode-setting failures emit a
PHP error-log warning with the JSON-quoted state path, without the counter payload.
The caller's lock is released even when persistence raises an exception.
Normal JSON storage, mode 0600, delta calculations, and return keys stay unchanged.
This remains an in-place write: a short write or failed flush can leave incomplete
state; the warning reports that failure without changing accounting policy.
