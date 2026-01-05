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

Do not move heavyweight orchestration into `install.sh`; it belongs in `update.php` and `update-step2.php`.
