# Adding a New User

The `addUser.php` script provisions a seedbox account with the required quota and rTorrent settings.

```
Usage: addUser.php USERNAME PASSWORD MAX_RAM_MB DISK_QUOTA_IN_GB [trafficLimitGB]
```

Arguments:
- **USERNAME** – login name to create
- **PASSWORD** – set the initial password (use `rand` for a random password)
- **MAX_RAM_MB** – account memory limit (used for cgroups and rTorrent tuning)
- **DISK_QUOTA_IN_GB** – storage quota
- **trafficLimitGB** (optional) – monthly traffic cap

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
- enables quotas and traffic limits
- starts rTorrent and lighttpd
- emits a summary marker (`###ADDUSER:SUCCESS|FAIL|ERROR`) to stdout and `/var/log/pmss/addUser.log`
- emits a JSON summary marker (`###ADDUSER_JSON:{...}`) with explicit `success`
  and `exit_code` fields for automation consumers

Operational notes:
- A per-user lock file prevents concurrent addUser runs for the same username.
- Provisioning fails fast if the user already exists, the home directory is missing after `useradd`,
  or critical steps (password + userConfig) fail.
- Step outcomes are logged in `/var/log/pmss/addUser.log`, `/var/log/pmss/users.log`,
  `/var/log/pmss/users.jsonl`, and per-user logs under `/var/log/pmss/users/<user>.log`.

Example:

```
/scripts/addUser.php alice rand 512 100 500
```

This adds user `alice` with a random password, 512 MB account RAM limit, 100 GB disk quota and a 500 GB monthly traffic limit.

**Documentation quality**: The script itself is largely uncommented and could benefit from a more detailed explanation of the setup steps performed.
