# Timeout Discipline

Timeouts in PMSS are hard process backstops, not runtime budgets. They must
protect the updater and cron jobs from permanently wedged children while leaving
legitimate slow runs enough room to finish.

## Rules

1. GNU `timeout` invocations must include `--kill-after=<grace>`. A SIGTERM-only
   timeout can wait forever when the child ignores or masks SIGTERM.
2. Intuition-picked timeout values must be conservative. Use a value at least
   five times higher than the expected normal runtime unless measured fleet data
   justifies a tighter limit.
3. Timeout-fire events must be logged structurally. PMSS records
   `timeout_fired` JSONL events with the command, intended seconds, actual
   seconds, signal, exit status, and correlation ID.
4. Timeout failure modes must be safe. A fired timeout should trigger a clean
   retry, reinstall, skip, or fail-soft path, not a silent partial state.

## Logging

Central PMSS command helpers append timeout-fire events to
`/var/log/pmss-timeout-fires.jsonl` by default. Hermetic tests may override the
destination with `PMSS_TIMEOUT_FIRE_LOG`.

When update JSON logging is configured, the same event is also emitted to
`PMSS_JSON_LOG` so timeout fires can be correlated with the parent update run.
