# Cron jobs

This document summarizes the scripts launched by cron and what they monitor.

Most files live under `scripts/cron/` and are scheduled through
`/etc/seedbox/config/root.cron` which is installed for the root account.
These cron jobs keep user services running and collect statistics.

## Service supervision
- `checkInstances.php` – ensures all rTorrent instances are running and
  responsive.
- `checkLighttpdInstances.php` – verifies per-user Lighttpd processes via a
  quick HTTP request.
- `checkQbittorrentInstances.php`, `checkDelugeInstances.php` and
  `checkRcloneInstances.php` – start or restart the respective programs when not
  detected.

## System monitoring
- `trafficLog.php` and `trafficStats.php` – record network usage.
- `cpuStat.php` and `diskIostat.php` – log CPU and disk statistics.
- `userIoStats.php` – collects per-user I/O operation counts from cgroup
  statistics (see below).
- `cgroup.php` – applies cgroup settings.
- `checkDirectories.php` – recreates expected log and runtime directories.

### userIoStats.php
`userIoStats.php` reads the `io.stat` file from each user's cgroup slice and logs
per-run differences. Log entries are written to
`/var/log/pmss/ioStats/<user>` in the format:

```
YYYY-mm-dd HH:MM:SS: READ_OPS WRITE_OPS
```

A state file in `/var/run/pmss/ioStats/` tracks previous values so that each
execution appends only the delta since the last run. The script only operates
when the unified `io` controller is available (Debian 11+).
