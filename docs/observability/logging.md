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

## Runbooks
See `docs/runbooks/update-failures.md` for quick diagnosis steps.

