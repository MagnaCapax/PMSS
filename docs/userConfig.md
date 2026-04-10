# Adjusting User Settings

`userConfig.php` modifies an existing account's resource limits and service configuration.

```
Usage: ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT]
   or: ./userConfig.php USERNAME --welcome-message=HTML

Options:
  --upload-throttle-kib=KIB          Per-user torrent upload limit
  --welcome-message=HTML             Per-user welcome page message (empty to clear)
  --docker-enabled=true|false        Rootless Docker policy
```

Parameters:
- **USERNAME** – user to update
- **RAM_MiB** – account memory limit (used for cgroups and rTorrent tuning)
- **DISK_QUOTA_GiB** – storage quota
- **TRAFFIC_LIMIT_GB** (optional) – monthly traffic cap
- **CPUWEIGHT** (optional) – systemd CPU weight
- **IOWEIGHT** (optional) – systemd IO weight
- **IO_READ_BW** / **IO_WRITE_BW** (optional) – block I/O bandwidth overrides in the `device:rate` format accepted by `systemd-run`
- **IO_READ_IOPS** / **IO_WRITE_IOPS** (optional) – block I/O IOPS overrides in the `device:iops` format accepted by `systemd-run`
- **CPU_QUOTA_PERCENT** (optional) – systemd CPUQuota percentage (e.g., 85 for 85%); omit to leave unchanged/inherit slice baseline
- **TRAFFIC_CAP_MBIT** (optional) – traffic shaper ceiling in Mbit/s
- **--upload-throttle-kib=KIB** (optional) – per-user torrent upload limit in KiB/s (0 removes the throttle file)
- **--welcome-message=HTML** (optional) – per-user welcome page message override (empty value clears it)
- **--docker-enabled=true|false** (optional) – explicit rootless Docker policy written to the per-user config store

The script rewrites rTorrent and ruTorrent configs, applies disk quota changes and restarts the user's rTorrent process.

Docker rootless safety gate:
- If `RAM_MiB` is below `245`, PMSS automatically stores `dockerEnabled=false` for the user and blocks rootless Docker starts until RAM is raised.

Provisioning note:
- Product metadata no longer changes `dockerEnabled` automatically. Provisioners that
  want Docker disabled by default for a product must pass `--docker-enabled=false`
  when they call `userConfig.php` or `addUser.php`.

Example:
```
/scripts/util/userConfig.php alice 1024 200 750 500 500
```

**Documentation quality**: Due to the script's length and many TODO comments, more structured documentation would aid maintenance.
