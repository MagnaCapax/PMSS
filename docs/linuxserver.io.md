# LinuxServer.io Containers on PMSS

This guide explains how to run LinuxServer.io Docker images on a Pulsed Media
Seedbox (PMSS). It is aimed at both Linux beginners and experienced users who
want a clean, repeatable way to run containers in PMSS’s multi-tenant,
rootless-Docker environment.

For general Docker usage on PMSS, see [`docs/docker-help.md`](./docker-help.md)
or run `docker-help` in your shell. WireGuard VPN usage is covered separately in
[`docs/wireguard.md`](./wireguard.md). Operational guardrails for multi-tenant
hosts are described in [`docs/security/operational-safety.md`](./security/operational-safety.md).

## 1. What PMSS provides

- **Rootless Docker per account** – Every user gets an isolated Docker daemon
  running without root privileges.
- **Environment pre-wired** – `DOCKER_HOST` is already exported so `docker`
  talks to your user daemon out of the box.
- **Helper commands** – `docker-help` prints common commands and tips; the MOTD
  and documentation link here for LinuxServer.io specifics.
- **No system-level changes** – Containers and bind mounts must live under your
  home directory; you cannot (and should not) write to system paths from
  rootless Docker.

You manage your daemon with:

```bash
systemctl --user status docker
systemctl --user start docker
systemctl --user restart docker
```

PMSS updates (see [`docs/update.md`](./update.md)) replace `/scripts`, `/etc`,
and parts of `/var` but do not touch your home directory. That means containers
and their data under `~/docker` survive normal platform updates; you are
responsible for managing and backing them up.

## 2. Core concepts (PUID/PGID, volumes, ports)

LinuxServer.io images share a common pattern:

- `PUID` / `PGID` – Control which user inside the container owns files.
- `TZ` – Timezone.
- Volumes – Bind mounts that keep config and data persistent.
- Ports – Mapping container ports to the outside world.

### 2.1 PUID / PGID on PMSS

In rootless mode the container’s internal UID 0 is mapped to your PMSS account
on the host. For LinuxServer.io images on PMSS:

- **Always set** `PUID=0` and `PGID=0`.
- This ensures files created by the container stay owned by you on the host.
- Without these variables, some images may use a non-root internal user, which
  can lead to confusing ownership/permission behaviour in rootless setups.

### 2.2 Volumes (where your data lives)

For each app, create directories in your home and bind-mount them:

```bash
mkdir -p ~/docker/jellyfin/{config,data}
```

Then map them into the container:

```bash
-v ~/docker/jellyfin/config:/config \
-v ~/docker/jellyfin/data:/data
```

Principles:

- Keep **all** mounts under your home (for example `~/docker/...`).
- Reuse the same `downloads` or `media` directories across related containers
  (Radarr/Sonarr/qBittorrent) so they see the same files.

### 2.3 Ports (how you reach services)

Publishing ports follows the usual Docker syntax:

```bash
-p HOST_PORT:CONTAINER_PORT
```

Examples:

- Jellyfin HTTP UI: `-p 8096:8096`
- qBittorrent WebUI: `-p 8080:8080`

If a `docker run` command fails with a “bind: address already in use” error,
either you already have a container using that port or another process is
listening on it. Pick a different host port or stop the conflicting container.

From your browser you normally reach services via:

```text
http://<your-seedbox-hostname>:<host-port>/
```

The exact hostname and connectivity depends on your PMSS plan; if in doubt,
check your welcome email or contact support.

## 3. First container (beginner-friendly)

This section walks through a simple Jellyfin media server deployment.

1. **Verify Docker is running**

   ```bash
   docker ps
   systemctl --user status docker
   ```

2. **Create directories for config and data**

   ```bash
   mkdir -p ~/docker/jellyfin/{config,data}
   ```

3. **Run Jellyfin**

   ```bash
   docker run -d \
     --name jellyfin \
     -e PUID=0 \
     -e PGID=0 \
     -e TZ=Etc/UTC \
     -p 8096:8096 \
     -v ~/docker/jellyfin/config:/config \
     -v ~/docker/jellyfin/data:/data \
     --restart unless-stopped \
     lscr.io/linuxserver/jellyfin:latest
   ```

4. **Check status and logs**

   ```bash
   docker ps
   docker logs -f jellyfin
   ```

5. **Connect from your browser**

   Visit `http://<your-seedbox-hostname>:8096/` in a browser. Complete the
   Jellyfin setup wizard, pointing it at your media directories under `~/`.

Once this is working you can reuse the same pattern for other images.

## 4. Common seedbox recipes

This section shows typical LinuxServer.io containers seedbox users care about.
All commands assume your shell is the default PMSS environment (from `~/.bashrc`)
with `DOCKER_HOST` set.

### 4.1 qBittorrent (torrent client)

```bash
mkdir -p ~/docker/qbittorrent/config ~/downloads

docker run -d \
  --name qbittorrent \
  -e PUID=0 \
  -e PGID=0 \
  -e TZ=Etc/UTC \
  -e WEBUI_PORT=8080 \
  -p 8080:8080 \
  -p 6881:6881 \
  -p 6881:6881/udp \
  -v ~/docker/qbittorrent/config:/config \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/qbittorrent:latest
```

- Web UI: `http://<your-seedbox-hostname>:8080/`
- Torrents are stored under `~/downloads`.

### 4.2 Radarr (movies)

```bash
mkdir -p ~/docker/radarr/config ~/movies

docker run -d \
  --name radarr \
  -e PUID=0 \
  -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 7878:7878 \
  -v ~/docker/radarr/config:/config \
  -v ~/movies:/movies \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/radarr:latest
```

- Web UI: `http://<your-seedbox-hostname>:7878/`
- Configure Radarr to use qBittorrent at `http://qbittorrent:8080` if you use
  Docker networking between containers, or the host/port you actually connect
  with if you prefer plain host access.

### 4.3 Sonarr (TV shows)

```bash
mkdir -p ~/docker/sonarr/config ~/tv

docker run -d \
  --name sonarr \
  -e PUID=0 \
  -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8989:8989 \
  -v ~/docker/sonarr/config:/config \
  -v ~/tv:/tv \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/sonarr:latest
```

- Web UI: `http://<your-seedbox-hostname>:8989/`

### 4.4 Prowlarr (indexer manager)

```bash
mkdir -p ~/docker/prowlarr/config

docker run -d \
  --name prowlarr \
  -e PUID=0 \
  -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 9696:9696 \
  -v ~/docker/prowlarr/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/prowlarr:latest
```

- Web UI: `http://<your-seedbox-hostname>:9696/`

These four containers plus Jellyfin cover the typical “media stack” many users
want on a seedbox.

### 4.5 Putting media containers on a shared Docker network (advanced)

If you want containers to address each other by name (for example Sonarr
talking to qBittorrent as `http://qbittorrent:8080`), create a dedicated Docker
network and attach the relevant containers to it:

```bash
docker network create media
```

Add `--network media` to each `docker run` command:

```bash
docker run -d \
  --name qbittorrent \
  --network media \
  ...

docker run -d \
  --name radarr \
  --network media \
  ...
```

Within that network:

- qBittorrent is reachable as `http://qbittorrent:8080`.
- Radarr is reachable as `http://radarr:7878`.
- Sonarr is reachable as `http://sonarr:8989`.

This avoids hard-coding IPs and keeps service discovery simple. The published
ports (`-p ...`) still work as before for browser access.

### 4.6 URL helpers and built-in media stack

Many PMSS accounts ship with a convenience alias called `arrinfo` in the
default `~/.bashrc`. It prints URLs for the built-in *ARR + Jellyfin* stack
when that package is installed:

```bash
arrinfo
```

If you deploy your own linuxserver.io stack with custom ports, adjust those
URLs accordingly. You can check whether the alias exists with:

```bash
type arrinfo
```

The ARR/Jellyfin media package is managed by Pulsed Media tooling; this guide
focuses purely on user-managed linuxserver.io containers. You can run both
side by side as long as you choose non-conflicting ports and separate config
directories.

## 5. Docker Compose (optional, advanced)

Docker Compose is not required, but it can simplify running multiple services.
If you choose to use it:

- Install the `docker-compose` binary into `~/bin` and make it executable, as
  described in general Docker documentation.
- Ensure `~/bin` is on your `PATH` (this is already true in the default PMSS
  shell profile).

Example `docker-compose.yml` for a small dashboard:

```yaml
version: "3.8"
services:
  heimdall:
    image: lscr.io/linuxserver/heimdall:latest
    container_name: heimdall
    environment:
      - PUID=0
      - PGID=0
      - TZ=Etc/UTC
    volumes:
      - ~/docker/heimdall/config:/config
    ports:
      - "8081:80"
    restart: unless-stopped
```

Usage:

```bash
mkdir -p ~/docker/heimdall
cd ~/docker/heimdall
nano docker-compose.yml   # or use your editor
docker-compose up -d
docker-compose ps
```

If you use the Docker CLI plugin variant instead, the commands are the same
with `docker compose` in place of `docker-compose`.

### 5.1 Compose file for a minimal media stack (advanced)

You can also manage the basic media stack via Compose. Example:

```yaml
version: "3.8"
services:
  qbittorrent:
    image: lscr.io/linuxserver/qbittorrent:latest
    container_name: qbittorrent
    environment:
      - PUID=0
      - PGID=0
      - TZ=Etc/UTC
      - WEBUI_PORT=8080
    volumes:
      - ~/docker/qbittorrent/config:/config
      - ~/downloads:/downloads
    ports:
      - "8080:8080"
      - "6881:6881"
      - "6881:6881/udp"
    restart: unless-stopped

  radarr:
    image: lscr.io/linuxserver/radarr:latest
    container_name: radarr
    environment:
      - PUID=0
      - PGID=0
      - TZ=Etc/UTC
    volumes:
      - ~/docker/radarr/config:/config
      - ~/movies:/movies
      - ~/downloads:/downloads
    ports:
      - "7878:7878"
    restart: unless-stopped

  sonarr:
    image: lscr.io/linuxserver/sonarr:latest
    container_name: sonarr
    environment:
      - PUID=0
      - PGID=0
      - TZ=Etc/UTC
    volumes:
      - ~/docker/sonarr/config:/config
      - ~/tv:/tv
      - ~/downloads:/downloads
    ports:
      - "8989:8989"
    restart: unless-stopped

  prowlarr:
    image: lscr.io/linuxserver/prowlarr:latest
    container_name: prowlarr
    environment:
      - PUID=0
      - PGID=0
      - TZ=Etc/UTC
    volumes:
      - ~/docker/prowlarr/config:/config
    ports:
      - "9696:9696"
    restart: unless-stopped
```

Usage is the same pattern as in the Heimdall example:

```bash
mkdir -p ~/docker/media
cd ~/docker/media
nano docker-compose.yml
docker-compose up -d
docker-compose ps
```

If you later change configuration, re-run `docker-compose up -d` to apply
updates. To update images, use `docker-compose pull` followed by
`docker-compose up -d`.

## 6. Multi-tenant safety and good citizenship

PMSS is a shared platform; keep these rules in mind:

- Stay under your storage and traffic allotments; avoid running containers that
  behave like CPU or disk miners.
- Keep all container data under your home directory so permissions and quota
  accounting remain predictable.
- Avoid `--privileged` containers and low-level host access patterns; they are
  not needed in rootless mode and may fail or behave unexpectedly.
- Use `docker stats` to watch CPU and memory usage of your containers.

The same principles are described at a higher level in
[`docs/security/operational-safety.md`](./security/operational-safety.md); this
guide simply applies them to Docker workloads.

### 6.1 Securing web UIs

LinuxServer.io applications often expose HTTP interfaces (Jellyfin, qBittorrent,
Radarr, Sonarr, Prowlarr, etc.). Treat them as internet-facing services:

- Always change default passwords and create strong credentials.
- Prefer reaching UIs over an encrypted channel (for example via WireGuard as
  described in [`docs/wireguard.md`](./wireguard.md)) rather than sending
  credentials over unencrypted networks.
- When possible, bind services to non-obvious ports and avoid exposing admin
  interfaces you do not need.
- Periodically review which containers are running and which ports are exposed
  with `docker ps` and `ss -tulpen` (the latter may be restricted depending on
  your plan, but is useful when available).

If you are unsure whether a particular configuration is safe in a shared
environment, contact support with your `docker run` or Compose snippet before
putting it into long-term use.

If you are ever unsure whether a container is appropriate for a seedbox, ask
support before running it.

## 7. Troubleshooting

- **Permission denied / wrong owner**
  - Confirm you used `PUID=0` and `PGID=0`.
  - Check directory ownership on the host:

    ```bash
    ls -ld ~/docker/* ~/downloads ~/media 2>/dev/null
    ```

- **Daemon not running**
  - Inspect status and logs:

    ```bash
    systemctl --user status docker
    journalctl --user -u docker
    systemctl --user restart docker
    ```

- **Container exits immediately**
  - Check logs:

    ```bash
    docker logs <container-name>
    ```

  - Common causes: invalid env vars, missing bind mounts, or ports already in
    use.

- **Need a shell inside a container**

  ```bash
  docker exec -it --user 0 <container-name> bash
  ```

- **Disk space usage**
  - Remove unused containers and images:

    ```bash
    docker ps -a
    docker stop <old-name>; docker rm <old-name>
    docker image prune
    ```

- **Upgrading a LinuxServer.io container**
  - For single `docker run` containers:

    ```bash
    docker pull lscr.io/linuxserver/<image>
    docker stop <name>
    docker rm <name>
    # re-run your original docker run command
    ```

  - For Compose-managed stacks:

    ```bash
    docker-compose pull
    docker-compose up -d
    ```

    Volumes keep your configuration and data; the container image is what
    changes.

If a problem clearly involves PMSS-specific limits or network quirks and you
cannot resolve it with the above steps, contact Pulsed Media support with the
relevant `docker run` or `docker-compose.yml` snippet and error messages.

## 8. Further reading

- LinuxServer.io image catalog: https://docs.linuxserver.io/images/
- Docker rootless mode docs: https://docs.docker.com/engine/security/rootless/
- LinuxServer.io Docker Compose notes: https://docs.linuxserver.io/general/docker-compose/
- PMSS rootless Docker notes and WireGuard helper: [`docs/docker-help.md`](./docker-help.md)
- PMSS WireGuard usage (host-level VPN, not container-only):
  [`docs/wireguard.md`](./wireguard.md)

For operators interested in how Docker is provisioned at the platform level,
see the Docker app notes in [`docs/update.md`](./update.md) and the cron-based
rootless watchdog description in [`docs/cron.md`](./cron.md). Those documents
describe how PMSS keeps your Docker daemon healthy between updates.
