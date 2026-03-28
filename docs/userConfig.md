# Adjusting User Settings

`userConfig.php` modifies an existing account's resource limits and service configuration.

```
Usage: ./userConfig.php USERNAME MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [CPUWEIGHT=1000] [IOWEIGHT=1000] [CPUQUOTAPCT] [--upload-throttle-kib=KIB] [--docker-enabled=true|false]
```

Parameters:
- **USERNAME** – user to update
- **MAX_RAM_MB** – account memory limit (used for cgroups and rTorrent tuning)
- **DISK_QUOTA_IN_GB** – storage quota
- **TRAFFIC_LIMIT_GB** (optional) – monthly traffic cap
- **CPUWEIGHT** (optional) – systemd CPU weight (default 1000)
- **IOWEIGHT** (optional) – systemd IO weight (default 1000)
- **CPUQUOTAPCT** (optional) – systemd CPUQuota percentage (e.g., 85 for 85%); omit to leave unchanged/inherit slice baseline
- **--upload-throttle-kib=KIB** (optional) – per-user torrent upload limit in KiB/s (0 removes the throttle file)
- **--docker-enabled=true|false** (optional) – explicit rootless Docker policy written to the per-user config store

The script rewrites rTorrent and ruTorrent configs, applies disk quota changes and restarts the user's rTorrent process.

Docker rootless safety gate:
- If `MAX_RAM_MB` is below `245`, PMSS automatically stores `dockerEnabled=false` for the user and blocks rootless Docker starts until RAM is raised.

Provisioning note:
- Product metadata no longer changes `dockerEnabled` automatically. Provisioners that
  want Docker disabled by default for a product must pass `--docker-enabled=false`
  when they call `userConfig.php` or `addUser.php`.

Example:
```
/scripts/util/userConfig.php alice 1024 200 750 500 500
```

**Documentation quality**: Due to the script's length and many TODO comments, more structured documentation would aid maintenance.
