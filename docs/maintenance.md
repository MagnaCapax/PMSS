# Maintenance Guide

This short checklist makes it easy to rehearse and diagnose an update.

## 1. Run The Tests
```
php scripts/lib/tests/development/Runner.php
```
Runs the development suite (self-contained, no system changes). Ensure it
passes before packaging or deploying changes.

## Common Operational Scripts

The repository is deployed under `/scripts` on production hosts. These helpers
are frequently used during day-to-day operations:

- `scripts/listUsers.php` - list managed tenant usernames (one per line).
- `scripts/showTraffic.php` - show per-user traffic summary (`--json` available).
- `scripts/util/userTrafficLimit.php` - set per-user traffic limit (expects validated units).
- `scripts/userTorrents.php` - count torrents per user (`--by-client` for breakdown).
- `scripts/addUser.php` - provision a new user account (creates services/config).
- `scripts/suspend.php` / `scripts/unsuspend.php` - toggle user suspension state.
- `scripts/terminateUser.php` - terminate a user account (`--confirm` required for non-interactive runs); the home and any matching `backup-<user>` recreate backup are removed synchronously.

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
`/etc/logrotate.d/pmss-update` is installed automatically and rotates PMSS
update logs plus high-volume `/var/log/pmss/` runtime logs such as
`users.log`, `users.jsonl`, `trafficStats.log`, and `check*.log`. Verify the
file exists and tweak the template under `etc/seedbox/config/template.logrotate.pmss`
if retention needs to change. System stats snapshots append to
`/var/log/pmss/system-stats.log` and are rotated by the same policy.

`/etc/logrotate.d/rsyslog` is also converged from
`etc/seedbox/config/template.logrotate.rsyslog`. PMSS keeps the standard Debian
rsyslog log list but rotates it daily with `maxsize 500M` so OS log storms
cannot grow until the next weekly rotation window.

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
