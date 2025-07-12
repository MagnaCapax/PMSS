# Cron Scripts

Various maintenance scripts executed by cron live in `scripts/cron`. The default
schedule is defined in `etc/seedbox/config/root.cron` and is installed for the
`root` user during setup. You can review or customise that file before running
`crontab etc/seedbox/config/root.cron`. Output from these jobs is appended to
log files under `/var/log/pmss`.

Many scripts assume standard Debian paths and utilities such as `iptables`,
`pgrep` and `quota`. When adding new scripts, follow the same lightweight
approach – no heavy frameworks, just simple shell or PHP helpers.

## Available scripts

- **backupEtc.sh** – Backup configuration files under `/etc`.
- **cgroup.php** – Apply cgroup limits for active users.
- **checkDelugeInstances.php** – Ensure Deluge daemons are running when enabled.
- **checkDirectories.php** – Create or fix expected directory structures.
- **checkGui.php** – Verify the web management GUI is accessible.
- **checkInstances.php** – Monitor rTorrent instances and restart when needed.
- **checkLighttpdInstances.php** – Validate each user's lighttpd/php-cgi pair.
- **checkQbittorrentInstances.php** – Restart qBittorrent for users if it stops.
- **checkRcloneInstances.php** – Maintain rclone mount processes.
- **cpuStat.php** – Periodically log CPU usage statistics.
- **diskIostat.php** – Collect disk I/O statistics for analysis.
- **diskSmart.php** – Prototype for SMART monitoring (currently experimental).
- **trafficLimits.php** – Update bandwidth throttling configuration per user.
- **trafficLog.php** – Record recent network usage from iptables counters.
- **trafficStats.php** – Summarise traffic logs into long‑term statistics.
- **updateQuotas.php** – Refresh user disk quota information.
- **userTrackerCleaner.php** – Remove obsolete trackers from user torrents.

The cron scripts rely on helper libraries under `scripts/lib`. For example,
`trafficLog.php` loads `networkInfo.php` to detect the primary interface and its
link speed. That helper works on Debian 10, 11 and 12 (and usually even 8) and
falls back to `eth0` if automatic detection fails.

All log output goes to `/var/log/pmss/<script>.log`. Most scripts write only a
few lines unless something goes wrong. Check these logs if you suspect a cron
job failed.

When adjusting schedules, copy `etc/seedbox/config/root.cron` to another file
and load it with `crontab yourfile`. The template spaces the jobs out to avoid
large resource spikes.
