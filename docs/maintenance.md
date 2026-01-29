# Maintenance Guide

This short checklist makes it easy to rehearse and diagnose an update.

## 1. Run The Tests
```
php scripts/lib/tests/development/Runner.php
```
Runs the development suite (self-contained, no system changes). Ensure it
passes before packaging or deploying changes.

## 2. Dry-Run The Updater
```
/scripts/update.php --dry-run --scriptonly --verbose
```
This executes the logging pipeline without mutating the system. The summary is
still printed, and the profiler records each step with `status=SKIP`.

## 3. Capture Structured Logs
```
/scripts/update.php --jsonlog --profile-output=/var/log/pmss-update.profile.json
```
- `/var/log/pmss-update.jsonl` receives one JSON object per step.
- The profile output file stores the full runtime breakdown.
- Combine with `--dry-run` for a rehearsal log you can share with teammates.

## 4. Review Log Rotation
`/etc/logrotate.d/pmss-update` is installed automatically and rotates the text
log, JSON log, and profile snapshot daily (7 copies, compressed, copytruncate).
Verify the file exists and tweak the template under
`etc/seedbox/config/template.logrotate.pmss` if retention needs to change.
System stats snapshots append to `/var/log/pmss/system-stats.log` and are
rotated by the same policy.

## 5. Confirm Version Metadata
After a real run, `/etc/seedbox/config/version` contains the canonical spec plus
timestamp, e.g.
```
git/main:2025-01-01@2025-01-02 03:04
```
`version.meta` records the resolved branch, commit, and log destinations in a
human-readable JSON structure for audits.

## 6. Preserve dpkg Baseline
Before altering anything under `scripts/lib/update/dpkg`, review the full capture
workflow documented in [`docs/dpkg-baseline.md`](./dpkg-baseline.md). The
snapshots are lifted from production systems and must remain untouched unless a
new baseline is captured and validated. Use the commands in that guide to record
human-readable (`systemTest.php`) and JSON (`systemTest.php --json` or
`componentStatus.php --json`) health reports after provisioning.
