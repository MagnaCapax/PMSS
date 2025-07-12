# Adding a New User

The `addUser.php` script provisions a seedbox account with the required quota and rTorrent settings.

```
Usage: addUser.php USERNAME PASSWORD MAX_RTORRENT_MEMORY_IN_MB DISK_QUOTA_IN_GB [trafficLimitGB]
```

Arguments:
- **USERNAME** – login name to create
- **PASSWORD** – set the initial password (use `rand` for a random password)
- **MAX_RTORRENT_MEMORY_IN_MB** – memory limit applied to rTorrent
- **DISK_QUOTA_IN_GB** – storage quota
- **trafficLimitGB** (optional) – monthly traffic cap

On success the script:
- creates the Unix user and home directory
- assigns an HTTP service port via `portManager.php`
- writes rTorrent/ruTorrent configuration
- enables quotas and traffic limits
- starts rTorrent and lighttpd

Example:

```
/scripts/addUser.php alice rand 512 100 500
```

This adds user `alice` with a random password, 512 MB rTorrent limit, 100 GB disk quota and a 500 GB monthly traffic limit.

**Documentation quality**: The script itself is largely uncommented and could benefit from a more detailed explanation of the setup steps performed.
