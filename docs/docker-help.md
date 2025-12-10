# Rootless Docker Basics

PMSS provisions rootless Docker for each account so you can run containers without `sudo` once your per-user daemon is running. Useful commands:

```
systemctl --user start docker.service   # start daemon
systemctl --user restart docker.service # restart daemon
docker ps                               # running containers
docker images                           # downloaded images
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


To ensure Docker commands talk to the correct daemon, the `DOCKER_HOST` environment variable is set in your `~/.bashrc`:

```
export DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock
```

## Storage drivers on PMSS

On PMSS, rootless Docker prefers overlay-style drivers so containers stay fast and space-efficient:

- Debian 10/11: when no driver is configured yet, PMSS writes `~/.config/docker/daemon.json` with `"storage-driver": "fuse-overlayfs"`. This is the default and recommended mode for rootless Docker on these releases.
- Debian 12+: PMSS does not force a driver; Docker’s own defaults apply unless you explicitly set `storage-driver` in `daemon.json`.
- Custom drivers: if `daemon.json` already contains `storage-driver` (for example `overlay2` or `vfs`), PMSS leaves it untouched and logs that it is reusing the existing configuration. `vfs` is supported but slow and space-heavy, and should generally be considered a last resort.

If you ever need to change the driver, edit `~/.config/docker/daemon.json` and restart Docker with:

```
systemctl --user restart docker.service
```

## Additional tools

If you need docker-compose, download the latest binary into `~/bin` and make it executable. The helper script `docker-install-wireguard.sh` defaults to a random port if none is supplied and prints the chosen port.

The default skeleton under `/etc/skel` provides a `~/bin/docker-install-wireguard.sh` helper and wires `~/bin` (and `~/.bin` when present) into `PATH` via `~/.bashrc`, so new accounts have the script available immediately after provisioning.

For operators, per-user Docker can be controlled via:

```
/scripts/util/dockerUserService.php USER {start|stop|restart|status}
```

This helper prefers the systemd user unit when available and falls back to starting `dockerd-rootless.sh` directly when the user bus is unavailable, logging actions to `/var/log/pmss/pmss-update-user-USER.log`.

## Troubleshooting

If `docker ps` fails with a socket error like:

```
failed to connect to the docker API at unix:///run/user/UID/docker.sock
```

check whether the daemon is running:

```
systemctl --user status docker.service
journalctl --user -u docker.service --no-pager -n 50
```

If `systemctl --user` complains about missing `$DBUS_SESSION_BUS_ADDRESS` or `$XDG_RUNTIME_DIR`, you are not in a real user session. Log in directly as the user (for example `ssh user@host`) and re-run the commands, or contact support so the host-level rootless Docker configuration can be checked.

See the [rootless Docker limitations](https://docs.docker.com/engine/security/rootless/#known-limitations) for details.

For a deeper guide to running linuxserver.io application containers on PMSS, see
[`docs/linuxserver.io.md`](./linuxserver.io.md). For the host-managed WireGuard
VPN service (recommended default), see [`docs/wireguard.md`](./wireguard.md);
the linuxserver.io WireGuard container is optional and runs under your own
account with Docker.
