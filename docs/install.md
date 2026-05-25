# PMSS Installer Notes

`install.sh` is a thin bootstrapper; keep it stable and conservative.
Responsibilities are intentionally limited to:

1. Ensure core tooling (`bash`, `php` CLI, `git`, `curl`, `wget`, `ca-certificates`, `rsync`).
2. Capture essential initial host config when an operator is present (hostname and `/etc/fstab` quota guidance).
3. Apply the minimum multi-tenant hardening required by the update workflow:
   - `/proc` mounted with `hidepid=2`.
   - `systemd.unified_cgroup_hierarchy=0` present in `/etc/default/grub` (reboot required) so rootless Docker remains compatible with `hidepid=2`.
4. Pull the repository into `/scripts`, `/etc`, and `/var`, then hand off to `/scripts/update.php`.

Interactivity contract:
- The documented pipe installer (`wget -qO- .../install.sh | bash -s -- ...`) must still be able to prompt in SSH/console sessions by using the controlling TTY (`/dev/tty`) when present.
- When running the installer via an `ssh host "..."` remote command, force a pseudo-TTY with `ssh -t` (otherwise no prompts are possible).
- For unattended runs, use `--non-interactive` (or `--skip-hostname` / `--skip-quota`) to suppress prompts.

## Filesystem provisioning

`/home` must be formatted with default-or-denser inode allocation for shared
seedbox workloads. Media stacks create many small files under application
metadata, queues, caches, and rootless container storage, so low-inode profiles
such as ext4 `-T largefile4` are contraindicated even when the host is intended
for large media files.

For ext4, choose the inode ratio at filesystem creation time; routine online
PMSS updates cannot add a usable inode budget after the filesystem is full.
`update-step2.php` warns when `/home` exceeds 256 KiB per inode so operators can
plan user migration or host evacuation/reformat before customer writes hit
`ENOSPC`.

## Development capture (interactive TTY)

To capture the full interactive session (including prompts) while keeping output visible on screen, use `script` with a TTY:

```
script -q -c "bash install.sh release" /tmp/pmss-install.typescript
```

For a remote host, force a TTY and capture output on the host:

```
ssh -t root@HOST "script -q -c 'wget -qO- https://github.com/MagnaCapax/PMSS/raw/main/install.sh | bash -s -- release' /tmp/pmss-install.typescript"
```

Logs are also written to `/var/log/pmss-install.log`, and the update phase continues logging under `/var/log/pmss/update.log` and `/var/log/pmss-update.jsonl`.

Do not move heavyweight orchestration into `install.sh`; it belongs in `update.php` and `update-step2.php`.
