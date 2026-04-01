# Adding a New User

The `addUser.php` script provisions a seedbox account with the required quota and rTorrent settings.

```
Usage:
  addUser.php USERNAME PASSWORD MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [TRAFFIC_CAP_MBIT] [UPLOAD_THROTTLE_KIB]
  addUser.php --user=USERNAME --password=PASSWORD --ram-mib=MAX_RAM_MB --disk-quota-gib=DISK_QUOTA_IN_GB [RESOURCE_OPTIONS]
```

Arguments:
- **USERNAME** – login name to create
- **PASSWORD** – set the initial password (use `rand` for a random password)
- **MAX_RAM_MB** – account memory limit (used for cgroups and rTorrent tuning)
- **DISK_QUOTA_IN_GB** – storage quota
- **TRAFFIC_LIMIT_GB** (optional) – monthly traffic cap
- **TRAFFIC_CAP_MBIT** (optional) – sustained traffic cap written to the user config store
- **UPLOAD_THROTTLE_KIB** (optional) – torrent upload throttle persisted for rTorrent and qBittorrent

Resource options:
- `--traffic-limit-gb=GIB`
- `--traffic-cap-mbit=MBIT`
- `--upload-throttle-kib=KIB`
- `--cpu-weight=WEIGHT`
- `--io-weight=WEIGHT`
- `--io-read-bw=/dev/DEVICE:RATE`
- `--io-write-bw=/dev/DEVICE:RATE`
- `--io-read-iops=/dev/DEVICE:IOPS`
- `--io-write-iops=/dev/DEVICE:IOPS`
- `--cpu-quota-percent=PERCENT|infinity`
- `--docker-enabled=true|false`

Named options override legacy positional values when both are supplied, so existing
automation keeps working while operators can skip earlier optional slots when they
only want to set later resource knobs.

`--docker-enabled=` lets the provisioning caller store the initial rootless Docker
policy explicitly. PMSS no longer infers that policy from product-name strings when
enforcement code reads the per-user config.

Usernames are normalised to lowercase and must match `[a-z][a-z0-9]{2,7}`—a
leading letter followed by 2–7 lowercase letters or digits (3–8 characters
total). This keeps Unix account names predictable for admins and avoids shell
injection edge cases elsewhere in the tooling.

Email-style usernames (`name@example.com`) are rejected explicitly; callers
must provide a bare PMSS username.

On success the script:
- creates the Unix user and home directory
- assigns an HTTP service port via `portManager.php`
- writes rTorrent/ruTorrent configuration
- converges the full per-user update environment before services start
- enables quotas and traffic limits
- starts rTorrent and lighttpd
- emits a summary marker (`###ADDUSER:SUCCESS|FAIL|ERROR`) to stdout and `/var/log/pmss/addUser.log`
- emits a JSON summary marker (`###ADDUSER_JSON:{...}`) with explicit `success`
  and `exit_code` fields for automation consumers

Operational notes:
- A per-user lock file prevents concurrent addUser runs for the same username.
- Provisioning fails fast if the user already exists, the home directory is missing after `useradd`,
  or critical steps (password + userConfig) fail.
- If provisioning fails after `useradd` but before `userConfig.php` finishes, addUser removes the
  partially created account, home directory, ports, and per-user config store entry before exiting.
- Step outcomes are logged in `/var/log/pmss/addUser.log`, `/var/log/pmss/users.log`,
  `/var/log/pmss/users.jsonl`, and per-user logs under `/var/log/pmss/users/<user>.log`.

Example:

```
/scripts/addUser.php --user=alice --password=rand --ram-mib=512 --disk-quota-gib=100 --traffic-limit-gb=500 --cpu-weight=200 --cpu-quota-percent=150
```

This adds user `alice` with a random password, 512 MB account RAM limit, 100 GB disk quota, a 500 GB monthly traffic limit, and explicit CPU controls.

**Documentation quality**: The script itself is largely uncommented and could benefit from a more detailed explanation of the setup steps performed.
