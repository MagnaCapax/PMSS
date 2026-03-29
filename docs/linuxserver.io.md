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

If you want the most common PMSS-ready presets without retyping the full
`docker run` lines, the default skeleton now includes `~/bin/linuxserverInstall.sh`.
It supports `jellyfin`, `qbittorrent`, `radarr`, `sonarr`, `prowlarr`,
`mariadb`, and `phpmyadmin`, creates the expected home-directory mounts,
attaches the containers to a shared `pmss-media` Docker network, and keeps
`--restart unless-stopped` enabled. The database presets bind to
`127.0.0.1` by default so they stay local to the host.

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

### 4.7 Example commands for other popular linuxserver.io images

The following examples show how to launch other common linuxserver.io images on
PMSS. Treat them as **starting points**, not copy‑paste gospel:

- Adjust host ports to avoid clashes with anything you already run.
- Adjust paths under `~/docker/...` and `~/media`, `~/downloads`, etc. to match
  your layout.
- Always cross‑check the official docs at https://docs.linuxserver.io/images/
  for the current list of environment variables and ports.

All examples follow the same baseline:

```bash
docker run -d \
  --name <name> \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -v ~/docker/<name>/config:/config \
  ... \
  --restart unless-stopped \
  lscr.io/linuxserver/<image>:latest
```

#### 4.7.1 Media servers

**Jellyfin** – Open-source media server (see section 3 for a full walkthrough).

**Emby** – Alternative media server:

```bash
mkdir -p ~/docker/emby/config ~/media

docker run -d \
  --name emby \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8097:8096 \
  -v ~/docker/emby/config:/config \
  -v ~/media:/media \
  --restart unless-stopped \
  lscr.io/linuxserver/emby:latest
```

**Plex** – Media server with rich client ecosystem:

```bash
mkdir -p ~/docker/plex/config ~/media

docker run -d \
  --name plex \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 32400:32400 \
  -v ~/docker/plex/config:/config \
  -v ~/media:/media \
  --restart unless-stopped \
  lscr.io/linuxserver/plex:latest
```

Plex has additional optional variables (for example `PLEX_CLAIM`) and ports for
DLNA and discovery; review the linuxserver.io Plex docs when using it heavily.

#### 4.7.2 Download clients (torrent and Usenet)

**qBittorrent** – See section 4.1 for the full example.

**Deluge** – Alternative torrent client:

```bash
mkdir -p ~/docker/deluge/config ~/downloads

docker run -d \
  --name deluge \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8112:8112 \
  -v ~/docker/deluge/config:/config \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/deluge:latest
```

This maps only the WebUI port (8112). For optimal torrent performance, add the
listen ports recommended in the official Deluge container docs.

**SABnzbd** – Usenet downloader with WebUI:

```bash
mkdir -p ~/docker/sabnzbd/config ~/downloads

docker run -d \
  --name sabnzbd \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8081:8080 \
  -v ~/docker/sabnzbd/config:/config \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/sabnzbd:latest
```

**NZBGet** – Lightweight Usenet client:

```bash
mkdir -p ~/docker/nzbget/config ~/downloads

docker run -d \
  --name nzbget \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 6789:6789 \
  -v ~/docker/nzbget/config:/config \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/nzbget:latest
```

**pyLoad-ng** – Download manager for one-click hosters:

```bash
mkdir -p ~/docker/pyload/config ~/downloads

docker run -d \
  --name pyload-ng \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8000:8000 \
  -v ~/docker/pyload/config:/config \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/pyload-ng:latest
```

#### 4.7.3 Automation, indexers, and request tools

The ARR tools work best when they share `~/downloads` and media directories.
Sonarr/Radarr examples already live in sections 4.2 and 4.3.

**Lidarr** – Music library manager:

```bash
mkdir -p ~/docker/lidarr/config ~/music

docker run -d \
  --name lidarr \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8686:8686 \
  -v ~/docker/lidarr/config:/config \
  -v ~/music:/music \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/lidarr:latest
```

**Readarr** – Books/audiobooks manager:

```bash
mkdir -p ~/docker/readarr/config ~/books

docker run -d \
  --name readarr \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8787:8787 \
  -v ~/docker/readarr/config:/config \
  -v ~/books:/books \
  -v ~/downloads:/downloads \
  --restart unless-stopped \
  lscr.io/linuxserver/readarr:latest
```

**Bazarr** – Subtitle downloader for Sonarr/Radarr:

```bash
mkdir -p ~/docker/bazarr/config

docker run -d \
  --name bazarr \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 6767:6767 \
  -v ~/docker/bazarr/config:/config \
  -v ~/movies:/movies \
  -v ~/tv:/tv \
  --restart unless-stopped \
  lscr.io/linuxserver/bazarr:latest
```

**Jackett** – Indexer proxy (many setups now prefer Prowlarr, but Jackett is
still widely used):

```bash
mkdir -p ~/docker/jackett/config

docker run -d \
  --name jackett \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 9117:9117 \
  -v ~/docker/jackett/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/jackett:latest
```

**NZBHydra2** – Usenet indexer/search proxy:

```bash
mkdir -p ~/docker/nzbhydra2/config

docker run -d \
  --name nzbhydra2 \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 5076:5076 \
  -v ~/docker/nzbhydra2/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/nzbhydra2:latest
```

**Overseerr** – Modern request management UI:

```bash
mkdir -p ~/docker/overseerr/config

docker run -d \
  --name overseerr \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 5055:5055 \
  -v ~/docker/overseerr/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/overseerr:latest
```

**Ombi** – Alternative request manager:

```bash
mkdir -p ~/docker/ombi/config

docker run -d \
  --name ombi \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 3579:3579 \
  -v ~/docker/ombi/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/ombi:latest
```

**Tautulli** – Plex statistics and monitoring:

```bash
mkdir -p ~/docker/tautulli/config

docker run -d \
  --name tautulli \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8181:8181 \
  -v ~/docker/tautulli/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/tautulli:latest
```

#### 4.7.4 Storage, sync, and cloud-style apps

**Syncthing** – Encrypted file sync between devices:

```bash
mkdir -p ~/docker/syncthing/config ~/sync

docker run -d \
  --name syncthing \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8384:8384 \
  -p 22000:22000 \
  -p 22000:22000/udp \
  -v ~/docker/syncthing/config:/config \
  -v ~/sync:/sync \
  --restart unless-stopped \
  lscr.io/linuxserver/syncthing:latest
```

**Nextcloud** – Self-hosted file sync and collaboration (advanced):

```bash
mkdir -p ~/docker/nextcloud/config ~/nextcloud-data

docker run -d \
  --name nextcloud \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8445:80 \
  -v ~/docker/nextcloud/config:/config \
  -v ~/nextcloud-data:/data \
  --restart unless-stopped \
  lscr.io/linuxserver/nextcloud:latest
```

For Nextcloud you will almost always pair this with a database container such
as MariaDB or PostgreSQL; see their docs for the recommended configuration.

**MariaDB** – MySQL-compatible database:

```bash
linuxserverInstall.sh mariadb
```

PMSS writes separate MariaDB service credentials to
`~/docker/mariadb/pmss-credentials.env` on first install and binds the service
to `127.0.0.1:3306` by default.

**Postgres** – PostgreSQL database:

```bash
mkdir -p ~/docker/postgres/config

docker run -d \
  --name postgres \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -e POSTGRES_PASSWORD=change_me \
  -p 5432:5432 \
  -v ~/docker/postgres/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/postgres:latest
```

**phpMyAdmin** – Web UI for MySQL/MariaDB:

```bash
linuxserverInstall.sh phpmyadmin
```

The helper binds phpMyAdmin to `127.0.0.1:8082` and points it at the shared
`mariadb` service name on the per-user Docker network.

#### 4.7.5 Dashboards, proxies, and utilities

**Heimdall** – Application dashboard (see section 5 for a Compose example). A
simple `docker run` variant:

```bash
mkdir -p ~/docker/heimdall/config

docker run -d \
  --name heimdall \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 8081:80 \
  -v ~/docker/heimdall/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/heimdall:latest
```

**SWAG** – Secure Web Application Gateway (reverse proxy + TLS, advanced):

```bash
mkdir -p ~/docker/swag/config

docker run -d \
  --name swag \
  -e PUID=0 -e PGID=0 \
  -e TZ=Etc/UTC \
  -p 80:80 \
  -p 443:443 \
  -v ~/docker/swag/config:/config \
  --restart unless-stopped \
  lscr.io/linuxserver/swag:latest
```

SWAG is powerful but requires careful configuration for virtual hosts, DNS, and
TLS; treat the above as scaffolding and follow the linuxserver.io docs closely
before exposing anything to the public internet.

## 5. Docker Compose (optional, advanced)

Docker Compose is not required, but it can simplify running multiple services.
If you choose to use it:

- Install the `docker-compose` binary into `~/bin` and make it executable, as
  described in general Docker documentation.
- Ensure `~/bin` is on your `PATH` (this is already true in the default PMSS
  shell profile, appended after system paths).

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

## 6. WireGuard: host service vs linuxserver.io container

PMSS ships two distinct WireGuard options:

- A **host-level WireGuard service** managed by PMSS itself.
- An optional **linuxserver.io WireGuard container** you can run under your own
  account with Docker.

They solve different problems; understanding the split helps you pick the right
tool.

### 6.1 Host-level WireGuard (recommended default)

The platform-wide WireGuard service is documented in
[`docs/wireguard.md`](./wireguard.md). Key properties:

- Managed at the host level under `/etc/wireguard/wg0.conf`.
- Provisioning generates server keys, enables `wg-quick@wg0`, and writes
  connection instructions to `/etc/wireguard/README` and `~/wireguard.txt`.
- You add client public keys to `~/.wireguard-public-key`; the updater rebuilds
  the server config to include each `[Peer]`.
- A cron watchdog (`checkWireguard.php`, see [`docs/cron.md`](./cron.md))
  ensures the kernel module and `wg-quick@wg0` stay healthy.

This is the **canonical** way to access your seedbox over VPN. Use it when you:

- Want a secure, PMSS-managed tunnel to your account from laptops/phones.
- Prefer a solution that survives OS-level updates and is covered by platform
  monitoring.
- Do not need per-container VPN routing, just a tunnel into the host.

### 6.2 linuxserver.io WireGuard container (optional)

The default skeleton includes a helper script `docker-install-wireguard.sh` in `~/bin`
and it is mentioned in [`docs/docker-help.md`](./docker-help.md). It launches
the linuxserver.io WireGuard container under your user:

```bash
docker-install-wireguard.sh [PORT]
```

Characteristics:

- Stores configuration under `~/.config/docker-wireguard`.
- Exposes a WireGuard UDP port on the host (random high port by default).
- Runs entirely under your account via Docker, separate from the host-level
  `wg0` service.

Use this only if you specifically want a **user-managed** WireGuard instance,
for example to:

- Build a separate VPN endpoint for a small set of peers you control.
- Experiment with linuxserver.io’s WireGuard docs without touching the system
  service.
- Route traffic for containerized workloads through a user-level VPN.

For most users the host-level WireGuard documented in `docs/wireguard.md` is
easier to operate and better integrated with PMSS monitoring. The container
option is more flexible but also more complex; if you are not sure which you
need, start with the host-level service.

## 7. Multi-tenant safety and good citizenship

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

### 7.1 Securing web UIs

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

## 8. Troubleshooting

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

## 9. FAQ

**Q: Can I use `--network host` with linuxserver.io images on PMSS?**

A: In rootless Docker the usual “host network” mode is not available in the
same way as on a rootful daemon. Stick to the default bridge network with
explicit `-p HOST:CONTAINER` mappings. If you try `--network host` and Docker
complains, that is a limitation of rootless mode, not a PMSS quirk.

**Q: Can I use `--privileged` or pass devices into containers?**

A: On PMSS you should assume privileged containers and direct device mappings
are not available. Rootless Docker deliberately limits low-level host access.
If an image insists on `--privileged` or device flags, it is usually a poor fit
for a shared seedbox environment.

**Q: Where should I store configuration and data for backup?**

A: Under your home directory, typically `~/docker/<app>/config` for settings
and a separate path such as `~/downloads`, `~/movies`, or `~/tv` for data.
Backing up those directories plus your `docker-compose.yml` (if you use
Compose) is enough to recreate the stack later.

**Q: Will containers keep running after I log out or the host reboots?**

A: Containers started with `--restart unless-stopped` are restarted whenever
your user’s systemd instance and Docker daemon come up. PMSS sets up linger and
cron watchdogs so user services keep running between logins. If you notice
containers consistently not restarting after reboots, reach out to support so
they can inspect the host’s configuration.

**Q: Can I run non-LinuxServer.io images with this guide?**

A: Yes. Most patterns here (volumes under `~/`, port mappings, `--restart
unless-stopped`) apply to any image. Only the `PUID`/`PGID` section is specific
to LinuxServer.io images; many other images do not use those variables.

**Q: How can I see how much space Docker is using?**

A: Use:

```bash
docker system df
docker images
docker ps -a
```

to get an overview. Clean up old containers and images as shown in the
troubleshooting section when space becomes tight.

## 10. Further reading

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
