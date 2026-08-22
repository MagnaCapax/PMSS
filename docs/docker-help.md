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

For the common LinuxServer.io media stack, PMSS also ships `docker-install-lsio`
in `~/bin`. It supports `jellyfin`, `qbittorrent`, `radarr`, `sonarr`,
`prowlarr`, `mariadb`, and `phpmyadmin`, keeps their mounts under `~/`, joins
them to a shared `pmss-media` network, and starts them with
`--restart unless-stopped` so the existing rootless Docker watchdog can bring
them back with the daemon. MariaDB and phpMyAdmin bind to `127.0.0.1` by
default, and the MariaDB helper writes separate service credentials to
`~/docker/mariadb/pmss-credentials.env` on first install:

```
docker-install-lsio qbittorrent
docker-install-lsio radarr
docker-install-lsio sonarr 18989
docker-install-lsio mariadb
docker-install-lsio phpmyadmin 18082
```

The legacy `linuxserverInstall.sh` name remains as a compatibility wrapper for
existing users, but new examples and support guidance should prefer
`docker-install-lsio`.

## Storage drivers on PMSS

On PMSS, rootless Docker prefers overlay-style drivers so containers stay fast and space-efficient:

- Cgroup v2 hosts: PMSS seeds `~/.config/docker/daemon.json` with `"exec-opts": ["native.cgroupdriver=cgroupfs"]` so first-start rootless Docker avoids the transient systemd scope permission failure seen on unified cgroup hosts.
- Debian 10/11: when `fuse-overlayfs` is available, PMSS writes `~/.config/docker/daemon.json` with `"storage-driver": "fuse-overlayfs"` and disables Docker's containerd image store via `"features": {"containerd-snapshotter": false}` so Docker honours the classic graphdriver on kernels without native rootless overlay support.
- Debian 12+: when no driver is configured yet and `fuse-overlayfs` is available, PMSS writes `~/.config/docker/daemon.json` with `"storage-driver": "fuse-overlayfs"`. This is the default and recommended mode for rootless Docker on PMSS.
- Custom drivers: if `daemon.json` already contains `storage-driver` (for example `overlay2` or `vfs`), PMSS leaves it untouched and logs that it is reusing the existing configuration. `vfs` is supported but slow and space-heavy, and should generally be considered a last resort.

`fuse-overlayfs` is in the PMSS dpkg baseline (Debian 11/12/13), so every regular update reinstalls it if it is ever removed — notably by the recurring fuse2/fuse3 apt cascade (`apt-get install fuse …` pulls bare `fuse` v2, which conflicts with and evicts `fuse3` + `fuse-overlayfs`; see GH #464/#643). This makes rootless Docker's storage driver self-heal without manual intervention. Dist-upgrades also install it best-effort. Debian 10 (buster, EOL) is not baselined for it because availability in the archived buster repos is not guaranteed.

If you ever need to change the driver, edit `~/.config/docker/daemon.json` and restart Docker. On PMSS the daemon is normally managed for you; reach out to support if you believe a driver change is required so they can coordinate it with platform tooling.

## Additional tools

If you need docker-compose, download the latest binary into `~/bin` and make it executable. The helper script `docker-install-wireguard.sh` defaults to a random port if none is supplied and prints the chosen port.

The default skeleton under `/etc/skel` provides a `~/bin/docker-install-wireguard.sh` helper and appends `~/bin` (and `~/.bin` when present) to `PATH` via `~/.bashrc` after system paths, so new accounts have the script available immediately after provisioning.

For operators, per-user Docker can be controlled via:

```
/scripts/userDocker.php USER {start|stop|restart|status}
```

This helper **defaults to starting `dockerd-rootless.sh` directly** (non-systemd rootless mode) once it has confirmed no rootless Docker process is running. Systemd user units are treated as advisory for start, while `stop` and `restart` pass the user's runtime directory to the user bus, fall back to a user-scoped process stop on failure, and verify that Docker is no longer running before reporting success. When `dockerEnabled=false`, the stop path also disables the user unit. Actions are logged to `/var/log/pmss/users/<username>.log` (and mirrored into `/var/log/pmss/users.log`/`.jsonl` when available).

On cgroup v2 hosts, `userDocker.php start` also backfills the same `daemon.json` override for older accounts that were provisioned before the skeleton shipped it.

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

## Running your own stack alongside the managed defaults

A shared seedbox gives you an interactive shell as your own user, rootless
Docker, and your own web space &mdash; but not root. The default services (the
torrent client, your control panel, the host VPN endpoint) are managed for you
and kept alive automatically, so they are not yours to switch off system-wide.
That is by design, and it does not get in the way of a container stack: the
managed defaults are small and idle when unused, and your containers run
alongside them.

- **You cannot permanently disable a managed default from your account.** If you
  stop one, it is brought back automatically. Rather than fight it, just leave
  an unused default idle &mdash; it costs almost nothing.
- **Reach your own apps without exposing them.** Bind an app to `127.0.0.1` and
  reach it over an SSH tunnel or WireGuard; the transport is already encrypted,
  so the app needs no certificate and there is no port to negotiate with other
  tenants. See [`Docker on PMSS`](https://wiki.pulsedmedia.com/index.php/Docker_on_PMSS)
  for the full pattern.
- **Direct-published ports are not guaranteed reachable from outside.** Whether a
  `-p`-published port is reachable from the public internet depends on host
  firewalling &mdash; confirm it from an external network before relying on it.
- **Clean up your own content freely, leave the plumbing alone.** Your data and
  anything you create is yours to remove; the account&rsquo;s managed files are
  restored on the next update if removed, so deleting them is harmless but
  pointless.
