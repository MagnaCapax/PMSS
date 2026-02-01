# Rootless Docker Basics

PMSS provisions rootless Docker for each account so you can run containers without `sudo` once your per-user daemon is running. On current hosts the daemon is managed in **non-systemd rootless mode** (via `dockerd-rootless.sh`) and kept alive by platform tooling; most users only need the standard Docker CLI:

```
docker ps         # running containers
docker images     # downloaded images
docker pull IMG   # fetch image
docker run IMG    # run container
```

Rootless Docker is started automatically when needed (by update-time hooks and cron watchdogs). If `docker ps` reports a socket error, wait a minute for the watchdog to kick in or contact support rather than trying to run `dockerd-rootless.sh` manually.

To ensure Docker commands talk to the correct daemon, the `DOCKER_HOST` environment variable is set in your `~/.bashrc`:

```
export DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock
```

Pull images and run containers normally:

```
docker pull lscr.io/linuxserver/wireguard:latest
```

A helper script `docker-install-wireguard.sh` resides in `~/bin` for quick setup of the
linuxserver.io Wireguard container. Invoke it with an optional port:

```
docker-install-wireguard.sh 51820
```

## Storage drivers on PMSS

On PMSS, rootless Docker prefers overlay-style drivers so containers stay fast and space-efficient:

- Debian 10+: when no driver is configured yet and `fuse-overlayfs` is available, PMSS writes `~/.config/docker/daemon.json` with `"storage-driver": "fuse-overlayfs"`. This is the default and recommended mode for rootless Docker on PMSS.
- Custom drivers: if `daemon.json` already contains `storage-driver` (for example `overlay2` or `vfs`), PMSS leaves it untouched and logs that it is reusing the existing configuration. `vfs` is supported but slow and space-heavy, and should generally be considered a last resort.

During dist-upgrades, PMSS also attempts to install `fuse-overlayfs` (best-effort) so existing rootless Docker configurations keep working after the reboot.

If you ever need to change the driver, edit `~/.config/docker/daemon.json` and restart Docker. On PMSS the daemon is normally managed for you; reach out to support if you believe a driver change is required so they can coordinate it with platform tooling.

## Additional tools

If you need docker-compose, download the latest binary into `~/bin` and make it executable. The helper script `docker-install-wireguard.sh` defaults to a random port if none is supplied and prints the chosen port.

The default skeleton under `/etc/skel` provides a `~/bin/docker-install-wireguard.sh` helper and appends `~/bin` (and `~/.bin` when present) to `PATH` via `~/.bashrc` after system paths, so new accounts have the script available immediately after provisioning.

For operators, per-user Docker can be controlled via:

```
/scripts/userDocker.php USER {start|stop|restart|status}
```

This helper **defaults to starting `dockerd-rootless.sh` directly** (non-systemd rootless mode) once it has confirmed no rootless Docker process is running. Systemd user units are treated as advisory: their *presence on disk* is reported by `status` and, when available, used for a polite `stop`, but the helper does not query the user bus (to avoid hangs) or rely on systemd for `start`/`restart` until it has dedicated test coverage on the current distro mix. Actions are logged to `/var/log/pmss/users/<username>.log` (and mirrored into `/var/log/pmss/users.log`/`.jsonl` when available).

## Troubleshooting

If `docker ps` fails with a socket error like:

```
failed to connect to the docker API at unix:///run/user/UID/docker.sock
```

check whether the daemon is running:

```
systemctl --user status docker.service
journalctl --user -u docker.service --no-pager -n 50

If `systemctl --user` hangs or you are not in a real user session, use:

```
/scripts/userDocker.php USER status
ps -u USER -o pid,cmd | grep dockerd
```
```

If `systemctl --user` complains about missing `$DBUS_SESSION_BUS_ADDRESS` or `$XDG_RUNTIME_DIR`, you are not in a real user session. Log in directly as the user (for example `ssh user@host`) and re-run the commands, or contact support so the host-level rootless Docker configuration can be checked.

See the [rootless Docker limitations](https://docs.docker.com/engine/security/rootless/#known-limitations) for details.

For a deeper guide to running linuxserver.io application containers on PMSS, see
[`docs/linuxserver.io.md`](./linuxserver.io.md). For the host-managed WireGuard
VPN service (recommended default), see [`docs/wireguard.md`](./wireguard.md);
the linuxserver.io WireGuard container is optional and runs under your own
account with Docker.
