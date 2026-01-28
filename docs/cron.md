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
  `/etc/seedbox/config/users.json` to keep provisioning data accurate. Logs to
  `/var/log/pmss/userDbCleanup.log` when changes are made (or with `--debug`).
  `30 2 * * * root /scripts/cron/cleanupUserDb.php >> /var/log/pmss/userDbCleanup.log 2>&1`

Audit these lines whenever you review a host. Missing watchdogs usually signal
manual edits that need to be reconciled.

## Script Catalogue

All cron helpers follow the same pattern: lightweight shell or PHP scripts that
reuse the shared libraries under `scripts/lib`, produce idempotent changes, and
append logs to `/var/log/pmss/<script>.log`. Highlights include:

- `backupEtc.sh` – Snapshot `/etc` into timestamped archives.
- `cgroup.php` – Apply cgroup limits for active users.
- `checkDelugeInstances.php` – Ensure Deluge daemons stay running when enabled.
- `checkDirectories.php` – Repair expected directory hierarchy if it drifts.
- `checkGui.php` – Verify the management GUI responds.
- `checkRtorrent.php` – Monitor rTorrent instances and restart as needed.
- `checkLighttpdInstances.php` – Confirm each user’s lighttpd/php-cgi pair.
- `checkQbittorrentInstances.php` – Restart qBittorrent if processes exit.
- `checkRcloneInstances.php` – Maintain rclone mount processes.
- `cpuStat.php` – Periodically record CPU usage statistics.
- `diskIostat.php` – Collect disk I/O metrics for later analysis.
- `diskSmart.php` – Prototype SMART monitoring (still experimental).
- `trafficLimits.php` – Refresh per-user traffic throttling configuration.
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
