# Cron Automation

PMSS schedules recurring maintenance work through lightweight scripts under
`/scripts/cron`. The repository ships with a canonical crontab template at
`etc/seedbox/config/root.cron`; apply it on a fresh host so monitoring and
recovery jobs start immediately:

```
crontab etc/seedbox/config/root.cron
```

Use the template as the base for any customisation. It already staggers
resource-intensive tasks to avoid I/O and CPU spikes, so only adjust the lines
that absolutely need changes for your deployment.

## High-Priority Watchdogs

The following entries must remain in the root crontab; they keep key services
healthy between full update runs:

- **Rootless Docker watchdog** – Restarts per-user Docker daemons when they
  exit unexpectedly. Logs to `/var/log/pmss/rootlessDocker.log`.
  `*/5 * * * * root /scripts/cron/checkRootlessDocker.php >> /var/log/pmss/rootlessDocker.log 2>&1`
- **WireGuard health check** – Ensures the WireGuard kernel module is loaded
  and `wg-quick@wg0` stays active. Logs to `/var/log/pmss/checkWireguard.log`
  when taking action (module load/restart) or when `--debug` is passed.
  `*/5 * * * * root /scripts/cron/checkWireguard.php >> /var/log/pmss/checkWireguard.log 2>&1`
- **System service hardening guard** – Reasserts that unwanted system-wide
  daemons stay stopped/disabled/masked (e.g. apache2/deluged/deluge-web/exim4/transmission-daemon/redis-server).
  Logs to `/var/log/pmss/systemdServicesGuard.log`.
  `* * * * * root sleep 25; /scripts/cron/systemdServicesGuard.php >> /var/log/pmss/systemdServicesGuard.log 2>&1`
- **User database cleanup** – Prunes stale entries from
  `/etc/seedbox/config/users/*.json` to keep provisioning data accurate. Logs to
  `/var/log/pmss/userDbCleanup.log` when changes are made (or with `--debug`).
  `30 2 * * * root /scripts/cron/cleanupUserDb.php >> /var/log/pmss/userDbCleanup.log 2>&1`

Audit these lines whenever you review a host. Missing watchdogs usually signal
manual edits that need to be reconciled.

## Script Catalogue

All cron helpers follow the same pattern: lightweight shell or PHP scripts that
reuse the shared libraries under `scripts/lib`, produce idempotent changes, and
append logs to `/var/log/pmss/<script>.log`. Highlights include:

- `backupEtc.sh` – Snapshot `/etc` into timestamped archives.
- `cgroupBfqWeightApply.php` - Reapply per-user cgroup-v1 BFQ kernel
  weights directly from PMSS user configuration so systemd translation caps do
  not flatten higher IOWeight tiers on BFQ hosts. Users without explicit
  `IOWeight` use the shared RAM fallback curve with 300% bonus headroom.
- `cgroup.php` – Apply cgroup limits for active users.
- `checkDelugeInstances.php` – Ensure Deluge daemons stay running when enabled.
- `checkDirectories.php` – Repair expected directory hierarchy if it drifts.
- `checkGui.php` – Restore missing `www/` + `data/` paths and GUI entrypoint.
- `checkRtorrent.php` – Monitor rTorrent instances, regenerate missing
  `~/.rtorrent.rc` from the canonical templates, and restart as needed. Its
  `startRtorrent` hand-off only reports success after rTorrent appears and
  survives a post-launch stability window, so brief crashes do not clear the
  failed-start counter. A live-but-unresponsive rTorrent is restarted only when
  the SCGI accept queue is saturated for consecutive watchdog runs and the
  process is not in uninterruptible I/O sleep.
- `checkLighttpdInstances.php` – Confirm each user’s lighttpd/php-cgi pair and probe the php-cgi sockets that should exist immediately after startup. Socket-probe failures must persist across consecutive watchdog runs before the stack is restarted.
- `lighttpdAccessLogTrim.php` – Truncate `~/.lighttpd/access.log` in place once
  it exceeds 100 MiB so long-lived reverse-proxied web UIs do not silently
  consume user quota. The root cron template runs it hourly and logs trims to
  `/var/log/pmss/lighttpdAccessLogTrim.log`.
- `checkQbittorrentInstances.php` – Restart qBittorrent if processes exit.
- `checkRcloneInstances.php` – Maintain rclone mount processes.
- `cpuStat.php` – Periodically record CPU usage statistics.
- `diskIostat.php` – Collect disk I/O metrics for later analysis. The live
  snapshot stays under `/var/run/pmss/iostat`; append-only history is persisted
  under `/var/log/pmss/iostat-history*.log` for reboot-safe postmortems.
- `storageHealthSnapshot.php` – Append SMART/NVMe/mdadm health snapshots to
  `/var/log/pmss/storage-health.jsonl` without waking standby disks. The root
  cron template runs it twice daily (06:00 and 18:00), and the PMSS logrotate
  policy retains the JSONL plus the cron wrapper log.
- `processSnapshot.php` – Append process tree snapshots for postmortem analysis (root-only log at `/var/log/pmss/process-snapshot.log`).
- `quotaSnapshot.php` – Append daily quota usage snapshots (machine-parseable; root-only log at `/var/log/pmss/quota-daily.log`).
- `resourceLog.php` – Capture per-user CPU, memory, and I/O samples every five minutes into the resource metering pipeline.
- `resourceStats.php` – Fold raw resource samples into per-user aggregates twice per hour.
- `resourceSnapshot.php` – Append a daily root-only snapshot of resource usage for long-term review.
- `trafficLimits.php` – Refresh per-user traffic throttling configuration (supports staged overage caps via `overageStages` and progressive post-cap reduction via `progressiveThrottleEnabled`, `progressiveThrottleFloorPercent`, and `progressiveThrottleGracePercent` in `/etc/seedbox/config/network`).
- `iopsLimits.php` – Refresh per-user monthly IOPS throttling by comparing `resourceStats` month totals against `/etc/seedbox/runtime/iopsLimits/<user>` and temporarily capping `/home` read/write IOPS via `userConfigCgroup.php`.
- `mdadmCheckarray.php` – Runs the quarterly mdadm redundancy check for
  non-degraded md arrays only, logging degraded or unknown arrays that are
  deferred until redundancy is restored.
- `trafficLog.php` – Capture recent traffic counters for aggregation.
- `trafficStats.php` – Fold raw logs into long-term statistics.
- `systemdServicesGuard.php` – Enforce stop/disable/mask policy for system services.
- `updateQuotas.php` – Refresh user disk quota information.
- `userTrackerCleaner.php` – Remove obsolete trackers from torrents.

Many scripts depend on standard Debian utilities (`iptables`, `pgrep`, `quota`).
Validate the required packages are present when enabling the tasks on a new
release.

## Logging and Troubleshooting

Cron job output lands in `/var/log/pmss`. Quiet logs usually indicate healthy
operation; spikes or repeated errors warrant inspecting the referenced helper in
`/scripts/cron`. Use the template as a checklist when onboarding new hosts so no
monitoring hooks are missed.

## User crontabs

PMSS does not overwrite per-user crontabs. Users can manage their own schedules
with `crontab -e` / `crontab -l`.

`scripts/util/setupRootCron.php` also keeps a PMSS-owned `cron.service` drop-in
under `/etc/systemd/system/cron.service.d/`. The drop-in restarts cron when it
exits and sets `TasksMax=8192` on the service as an aggregate guard: Debian cron
does not place per-user crontab jobs inside `user-UID.slice`, so this cap bounds
cron fork storms while PMSS continues to use the stable vixie-cron path.

Universal PMSS watchdogs (rTorrent, lighttpd, rootless Docker, quotas, traffic
aggregation, etc.) are owned by root and deployed via `/etc/cron.d/pmss` from
`etc/seedbox/config/root.cron`.

Older accounts may still have legacy per-user rTorrent cron entries; they are no
longer required because `checkRtorrent.php` runs from the root cron schedule.

## Scheduling Tips

- Keep heavy jobs (quota refresh, traffic aggregation) staggered to avoid I/O
  contention.
- When adjusting cadence, copy the template to a staging file, edit there, and
  reload via `crontab` so you can diff customisations later.
- Document any deviations directly in `/etc/seedbox/config/root.cron` with
  inline comments, then sync the change back into the repository so the template
  remains authoritative.

With the canonical crontab applied, the background maintenance flow stays
predictable across Debian releases and the update scripts can focus on
idempotent provisioning work.
