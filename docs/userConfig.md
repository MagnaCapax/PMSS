# Adjusting User Settings

`userConfig.php` modifies an existing account's resource limits and service configuration.

```
Usage: ./userConfig.php USERNAME MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [CPUWEIGHT=1000] [IOWEIGHT=1000]
```

Parameters:
- **USERNAME** – user to update
- **MAX_RAM_MB** – memory limit for rTorrent
- **DISK_QUOTA_IN_GB** – storage quota
- **TRAFFIC_LIMIT_GB** (optional) – monthly traffic cap
- **CPUWEIGHT** (optional) – systemd CPU weight (default 1000)
- **IOWEIGHT** (optional) – systemd IO weight (default 1000)

The script rewrites rTorrent and ruTorrent configs, applies disk quota changes and restarts the user's rTorrent process.

Example:
```
/scripts/util/userConfig.php alice 1024 200 750 500 500
```

**Documentation quality**: Due to the script's length and many TODO comments, more structured documentation would aid maintenance.
