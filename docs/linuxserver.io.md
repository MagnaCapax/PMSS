# LinuxServer.io Containers on PMSS

PMSS runs Docker in rootless mode per account. `DOCKER_HOST` is already set for
your user session and `docker-help` prints common commands if you need a quick
refresher.

## Quick start (generic recipe)

1. Create per-app directories inside your home so file ownership stays clean:

   ```bash
   mkdir -p ~/docker/jellyfin/{config,data}
   ```

2. Launch the container with explicit UID/GID and timezone values. Rootless
   Docker maps container UID 0 to your account, so `PUID=0` and `PGID=0` keep
   files owned by you on the host:

   ```bash
   docker run -d --name jellyfin \
     -e PUID=0 -e PGID=0 -e TZ=UTC \
     -p 8096:8096 \
     -v ~/docker/jellyfin/config:/config \
     -v ~/docker/jellyfin/data:/data \
     lscr.io/linuxserver/jellyfin:latest
   ```

3. Inspect logs and status as needed:

   ```bash
   docker ps
   docker logs -f jellyfin
   ```

## Permission and maintenance tips

- Keep bind mounts under your home directory; avoid writing to system paths
  because the daemon is rootless.
- If you need a shell for admin tasks, run `docker exec -it --user 0 <name> bash`
  so actions run as the mapped root user (your account on the host).
- Restart the daemon if needed with `systemctl --user restart docker` and relaunch
  containers if you changed configuration.
- Clean up old containers/images with `docker stop <name>; docker rm <name>; docker image prune`.

For broader Docker guidance, see `docs/docker-help.md` or run `docker-help` from
your shell. WireGuard setup remains covered separately in `docs/wireguard.md`.
