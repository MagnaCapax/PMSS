# PMSS User‑Local Media Stack Installer (etc/skel/install-media-stack.sh)

This script installs a self‑contained media stack in each user’s home under `~/.bin` with per‑user configs under `~/.config`. It is designed for shared seedbox environments to be safe by default, easy to operate (aliases + tmux), and largely self‑healing.

It installs:
- Radarr, Sonarr, Prowlarr (Servarr apps; .NET)
- Jellyfin (server; .NET)
- SABnzbd (Python + venv)
- Cloudplow (Python + venv)
- Autobrr (release-tracked single binary)

All apps bind to `127.0.0.1` and are reverse‑proxied by per‑user lighttpd to `https://<host>/public-<user>/<app>/`.

## Key Properties
- Self‑updating (interactive): when run in a TTY, fetches the latest script from GitHub and re‑execs it; skip with `--skip-update`. Non‑interactive runs (e.g. `wget … | bash`) use the fetched script as‑is.
- Monolithic but structured: single file with small helpers for clarity and reuse.
- Idempotent: re‑runs converge to the same state; dry‑run exists for verification.
- Safe defaults: localhost binding; randomized high ports; aliases to launch in `tmux`.
- Memory pre-flight: accounts below 1024 MiB are warned and must use `--force` from SSH.
- Uninstall path: `--uninstall` stops media-stack sessions, removes PMSS-managed app/config paths, and backs up/strips managed shell aliases.
- Servarr update policy: Radarr, Sonarr, and Prowlarr use the external update mechanism and disable automatic in-place updates; rerun this installer to update them safely.
- Logging: colored console output and log tee to `~/.install-media-stack.log`.

## Web Panel Wrapper
- The welcome page can launch the installer without SSH for the first run.
- The wrapper intentionally stops at first-install scope: once `~/.bin` or Jellyfin data already exist, reruns should happen over SSH so operators can review existing state and any Jellyfin data-loss prompt before proceeding.
- The wrapper does not pre-generate Jellyfin credentials; the admin account is created in Jellyfin’s first-run wizard after the install completes.

## Jellyfin Media Library Path

PMSS keeps `/home` non-listable for tenant privacy. Jellyfin can traverse to
`/home`, but its first-run folder picker cannot list the user directories below
it. Clicking `home` can therefore show a blank list, and accepting `/home`
creates a library that scans nothing.

In the Jellyfin first-run wizard, type the complete media path into the folder
field instead of selecting `/home`; for the usual layout, use:

```text
/home/<user>/data
```

For support checks, a `.mblink` containing exactly `/home` indicates this
misconfiguration. Jellyfin logs it as `Library folder "/home" is inaccessible
or empty, skipping`.

## Compatibility Matrix
- Debian 12 (bookworm): uses latest Servarr download/update endpoints + .NET 8.
- Debian 11 (bullseye) and Debian 10 (buster): .NET 8 supported; Radarr GLIBC fallback:
  - If GLIBC < 2.33 on x64, Radarr is pinned to `v5.10.4.9218` linux‑core build to avoid sqlite/GLIBC loader errors. This matches observed errors like `GLIBC_2.33 not found`, `e_sqlite3.so missing` from newer Radarr builds.

Note: Jellyfin requires FFmpeg 4.4+ for startup. The script uses distro ffmpeg when it meets that floor, or an explicit `--jellyfin-ffmpeg` path when provided. On Debian 11 and other hosts with older or missing ffmpeg, Jellyfin is skipped with instructions to install a user-local static build under `~/.bin` and rerun with that flag. Hardware-transcoding tuning remains an explicit, opt-in workflow; see `docs/hardware-transcoding.md`.

## Prerequisites
- `ss` (from iproute2), `curl` or `wget`, `tar`, `tmux`
- Python 3 with `venv` module for SABnzbd/Cloudplow
- .NET 8 ASP.NET runtime is installed to `~/.bin/dotnet` by this script

## Endpoints Used
- Sonarr v4: `https://services.sonarr.tv/v1/download/<branch>/latest?version=4&os=linux&runtime=netcore&arch=<x64|arm64|arm>`
- Radarr: `https://radarr.servarr.com/v1/update/<branch>/updatefile?os=linux&arch=<arch>` (default branch: `master`)
  - If GLIBC < 2.33 and x64: pin `https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz`
- Prowlarr: `https://prowlarr.servarr.com/v1/update/<branch>/updatefile?os=linux&runtime=netcore&arch=<arch>` (default branch: `master`)
- Jellyfin: `https://repo.jellyfin.org/files/server/linux/latest-stable/<arch>/` (scraped for latest tarball), or explicit override
- SABnzbd: latest GitHub release `-src` asset
- Autobrr: latest GitHub release Linux tarball for the detected architecture

Every URL may be overridden via CLI flags (below). The script verifies each URL in dry‑run/verify‑only mode.

## Installing the Installer

- Run locally (self‑updates by default):
  - `bash install-media-stack.sh`
- Or run the latest from GitHub (if the file isn’t present yet):
  - `wget -qO - https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/install-media-stack.sh | bash`
  
To skip self‑update (use whatever version you already have locally):
  - `bash install-media-stack.sh --skip-update`

## CLI
Run `install-media-stack.sh --help` for the latest usage. Full options:

- Common
  - `--skip-update`        Skip self‑update from GitHub
  - `--dry-run`            Verify URLs and show actions; do not change the system
  - `--verify-only`        Only verify URLs (alias to `--dry-run`) and exit early
  - `--force`              Continue below the 1024 MiB account-memory guard
  - `--start-stopped`      Start installed apps whose tmux sessions are absent, then exit
  - `--uninstall`          Remove the PMSS-managed media stack from this account

- Sonarr
  - `--sonarr-url=URL`     Use exact URL
  - `--sonarr-branch=BR`   Override branch (default: `main`)
  - `--sonarr-version=4`   Override major version (default: `4`)

- Radarr
  - `--radarr-url=URL`     Use exact URL
  - `--radarr-branch=BR`   Override branch (default: `master`; stable)
  - `--radarr-version=TAG` Version tag (e.g., `v5.10.4.9218`) – x64 convenience
  - `--radarr-pin=TAG`     Alias for `--radarr-version`

- Prowlarr
  - `--prowlarr-url=URL`   Use exact URL
  - `--prowlarr-branch=BR` Override branch (default: `master`; stable)

- Autobrr
  - `--autobrr-url=URL`    Use exact URL instead of the latest GitHub release asset

- Jellyfin
  - `--jellyfin-url=URL`   Use exact URL for server tarball
  - `--jellyfin-ffmpeg=PATH` Write FFmpegPath to Jellyfin system.xml (e.g., `/home/<user>/.bin/ffmpeg`) and skip automatic fallback selection

- SABnzbd
  - `--sab-url=URL`        Use exact URL of the `-src` archive
  - `--sab-version=TAG`    Advisory override for logging only

### Examples

- Install latest from GitHub without cloning:
  - `wget -qO - https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/install-media-stack.sh | bash`

- Dry‑run (URL checks only):
  - `bash install-media-stack.sh --verify-only`
- Start stopped apps without reinstalling:
  - `bash install-media-stack.sh --start-stopped`

- Pin Radarr on Debian 11 x64:
  - `bash install-media-stack.sh --radarr-pin=v5.10.4.9218`

- Override all URLs explicitly:
  - `bash install-media-stack.sh \
      --sonarr-url=https://services.sonarr.tv/.../Sonarr.main.linux-x64.tar.gz \
      --radarr-url=https://radarr.servarr.com/.../Radarr.linux-x64.tar.gz \
      --prowlarr-url=https://prowlarr.servarr.com/.../Prowlarr.linux-x64.tar.gz \
      --jellyfin-url=https://repo.jellyfin.org/files/server/linux/latest-stable/amd64/jellyfin_10.X.Y-amd64.tar.gz \
      --sab-url=https://github.com/sabnzbd/sabnzbd/releases/download/X.Y.Z/SABnzbd-X.Y.Z-src.tar.gz`

## Behavior by Stage
1) Self‑update
- Downloads the latest script from GitHub and re‑executes it with `--skip-update` by default. Disable with `--skip-update`.

2) Dependency checks
- Verifies `ss` exists; errors out if missing.

3) Architecture detection
- Maps Debian `dpkg --print-architecture` to Servarr/Jellyfin arch triplets.

4) Endpoint resolution
- Resolves URLs for all components. In `--dry-run` or `--verify-only`, performs HEAD checks and prints status, then continues (`--verify-only` may exit early in the future; currently both modes do not modify the system).

5) Install steps
- Cloudplow (venv + pip requirements)
- SABnzbd (venv + pip requirements)
- Autobrr (release-tracked binary and per-user config)
- Radarr, Prowlarr, Sonarr (download and extract into `~/.bin/<Name>`)
- .NET 8 ASP.NET runtime (download to `~/.bin/dotnet`, exports PATH/DOTNET_ROOT in `~/.bashrc.custom` after system paths)
- Jellyfin (download/extract to `~/.bin/jellyfin`) only when system ffmpeg is 4.4+ or `--jellyfin-ffmpeg=PATH` is supplied

6) Configuration
- Writes Servarr XML configs in `~/.config/<app>/config.xml` with randomized ports, localhost bind, URL base `/public-<user>/<app>`, and the external update mechanism. The installer disables automatic in-place updates so the shared `.NET` runtime cannot be removed by an app updater; rerun this script for Servarr updates.
- Jellyfin writes `~/.config/jellyfin/network.xml` likewise.
- SABnzbd writes `~/.config/sabnzbd/sabnzbd.ini` and adjusts url_base/port/whitelist plus `inet_exposure = 4` so the proxied first-run wizard is reachable.
- Autobrr writes `~/.config/autobrr/config.toml`, binds to `127.0.0.1`, and serves its sub-path through the generated per-user proxy fragment.

7) Aliases
- Appends tmux aliases to `~/.bashrc.custom` that export `DOTNET_ROOT` and execute `<app>.dll` via `dotnet` (or Python venv for SABnzbd/Cloudplow).
- Sources `~/.bashrc` with `set +u` so `~/.bashrc.custom` takes effect (and to avoid aborts when nounset is active in a user’s shell configs).

8) Reverse proxy
- Writes the PMSS-managed media-stack proxy fragment to `~/.lighttpd/custom.d/media-stack.conf` with URL rewriting from `/app` to `/public-<user>/<app>`; Autobrr uses the documented strip-to-empty map and an exact bare-path trailing-slash redirect.
- On first rerun after older installer versions, legacy PMSS-managed `~/.lighttpd/custom` content is migrated out of the user-controlled include so custom rules are preserved.

9) Launch
- Starts each app under a tmux session (skipped in `--dry-run`).
- `--start-stopped` is a separate one-shot recovery path: it skips downloads and installation, preserves live sessions, and launches only missing sessions through the installer-managed aliases.

10) Logging
- All output is colored for terminals and appended to `~/.install-media-stack.log`.

## Dry‑Run and Verify‑Only
- `--dry-run` checks each URL (`curl -I` or `wget --spider`), prints the actions that would be taken, and skips any filesystem or process changes.
- `--verify-only` is an alias that implies `--dry-run`. The script remains side‑effect‑free in both modes.

## GLIBC and Radarr
- Newer Radarr releases ship sqlite binaries that require `GLIBC_2.33+`. On Debian 10/11, this causes loader failures. The script detects GLIBC and pins x64 Radarr to `v5.10.4.9218` linux‑core on older systems. Use `--radarr-version=` or `--radarr-pin=` to override.

## Operations & Safety
- The script preserves unrelated `~/.bin` contents on reruns and refreshes only PMSS-managed media-stack paths in place.
- The full stack is not suitable for very small shared-hosting memory budgets. If the detected account cgroup limit is below 1024 MiB, the installer warns and aborts unless run interactively with confirmation or explicitly with `--force`. The welcome-page wrapper surfaces the same warning and does not force installs from the browser.
- The script still prompts before removing existing Jellyfin state because stale config can hang reruns and the deletion is destructive.
- The welcome panel exposes the same one-shot `--start-stopped` recovery. It never enables automatic crash-loop restarts; review the app log before retrying a repeatedly failing app.
- Media-stack ports are still chosen locally from `10000-65000`; they are not yet registered with `scripts/util/portManager.php`.
- All app binds are `127.0.0.1`. Exposure to the Internet is not supported without proper SSL/reverse proxy hardening.
- Conflicts with global `/opt` installs may occur; this is a per‑user stack by design.

## Uninstall
Run from SSH:

- `bash install-media-stack.sh --uninstall`
- Add `--dry-run` to preview the cleanup without removing files.

The uninstall mode stops the media-stack `tmux` sessions, removes only the PMSS-managed app directories under `~/.bin`, removes the matching config directories under `~/.config`, removes `~/.lighttpd/custom.d/media-stack.conf`, and backs up `~/.bashrc.custom` before stripping the installer-managed alias/PATH blocks. It does not remove unrelated files from `~/.bin`, `~/.config`, or user-owned lighttpd custom fragments.

## Troubleshooting
- Check `~/.install-media-stack.log` for a full run transcript.
- Use `--dry-run` to verify endpoint reachability and planned actions.
- If the installer reports a low memory limit, use a larger plan for the full stack. `--force` is available for deliberate SSH-only installs, but throttling can make rtorrent and the panel unresponsive on small accounts.
- Use `--uninstall` to remove a user-local media stack that was installed on an unsuitable account.
- If Servarr apps fail to start on Debian 11 due to sqlite/GLIBC errors, confirm Radarr pinning occurred or pass `--radarr-version=v5.10.4.9218`.
- If Jellyfin is skipped or exits immediately with an FFmpeg validation error, verify `~/.config/jellyfin/config/system.xml` points to a usable FFmpeg 4.4+ binary. On Debian 11, install a user-local static build under `~/.bin/ffmpeg` and rerun with `--jellyfin-ffmpeg=$HOME/.bin/ffmpeg`. Follow `docs/hardware-transcoding.md` for driver and acceleration troubleshooting.

## FFmpeg Options (Userland)

Jellyfin depends on ffmpeg for startup and transcoding. On older distros, the packaged ffmpeg may be too old for Jellyfin 10.9+ or may lack codecs/hardware support you want. The installer does not download ffmpeg for you; install it explicitly in userspace and rerun with `--jellyfin-ffmpeg=PATH` when the distro package is below 4.4:

- Static builds (recommended first):
  - BtbN FFmpeg-Builds: actively maintained, widely used. Download a `linux-64-gpl` archive and place `ffmpeg` under `~/.bin`.
  - John Van Sickle static ffmpeg: long-running static provider; often used on older hosts. Download the `amd64 static` tarball.
  - eugeneware/ffmpeg-static: primarily shipped for Node environments; fine for development but not a typical Jellyfin deployment source.

Steps (example with BtbN):
1) Ensure your user bin exists: `mkdir -p ~/.bin`
2) Download and extract a static ffmpeg build to `~/.bin/ffmpeg` and `chmod +x ~/.bin/ffmpeg`.
3) The installer appends `~/.bin` to your PATH in `~/.bashrc.custom` after system paths and writes Jellyfin's `FFmpegPath` when it receives an explicit user-local ffmpeg path. You can also set it in Jellyfin (Dashboard -> Playback) to `/home/<user>/.bin/ffmpeg`.

Hardware acceleration:
- VAAPI/NVENC need matching user-accessible driver libraries. If you place libs under `~/.local/lib` or `~/.bin/lib`, export `LD_LIBRARY_PATH=$HOME/.local/lib:$HOME/.bin/lib:$LD_LIBRARY_PATH` before launching Jellyfin (the installer already sets DOTNET env; you can extend it in `~/.bashrc.custom`).

Compiling from source (advanced):
- Build libraries to `~/.local`, then configure ffmpeg with:
  - `./configure --prefix=$HOME/.local --extra-cflags="-I$HOME/.local/include" --extra-ldflags="-L$HOME/.local/lib" --pkg-config-flags="--static" --enable-gpl --enable-version3 --enable-libx264 --enable-libx265 --enable-libvpx --enable-libopus --enable-libdav1d`
  - Add `--enable-nonfree --enable-libfdk-aac` only if you accept nonfree licensing.
- This path offers the most flexibility but is complex; static builds are usually sufficient.

Notes:
- *ARR apps don’t require ffmpeg; only Jellyfin uses it.
- The installer does not manage ffmpeg binaries. Codec selection, GPU libraries, hardware acceleration, and static-binary updates remain explicit user choices.
- You can pass `--jellyfin-ffmpeg=/home/<user>/.bin/ffmpeg` to stamp Jellyfin's FFmpeg path automatically in your user config and bypass fallback selection.

## Alternative: Docker Rootless (Not Recommended for Shared Hosting)

Docker rootless mode allows running containers without root privileges. While this sounds appealing for shared hosting, testing has shown significant issues on PMSS infrastructure.

### Why Docker Rootless Doesn't Work Well on PMSS

1. **cgroup Delegation Issues (mitigated for PMSS-managed accounts)**: PMSS production hosts are pinned to cgroup v1, and PMSS seeds the rootless Docker `cgroupfs` driver for new accounts. Existing accounts are reconciled by `userDocker.php` on start/restart when the cgroup-v2 path applies, so the previously reported cgroup-v2 "Permission denied" startup failure is not expected on a converged PMSS host.

2. **Higher Resource Usage**: Docker daemon alone uses ~180 MB. Combined with container isolation overhead, memory usage is 20-30% higher than native installs.

3. **System Configuration Required**: Docker rootless requires root-level setup (subuid/subgid, linger, cgroup delegation) that shared hosting users cannot perform.

4. **Reliability**: Containers often get stuck in "Created" or "Dead" states on PMSS servers.

### Resource Comparison

| Metric | Native Install | Docker Rootless |
|--------|----------------|-----------------|
| Memory (full stack) | ~900 MB | ~1.1+ GB |
| Disk usage | ~500 MB | ~2.5 GB (images) |
| Setup complexity | Low (single script) | High |
| Admin intervention | None | Required |
| Reliability on PMSS | High | Low |

### If You Still Want to Try Docker

For dedicated/baremetal servers where an admin can configure the system, Docker rootless setup requires:

```bash
# Admin steps (as root):
apt install docker-ce docker-ce-rootless-extras
echo "<username>:100000:65536" >> /etc/subuid
echo "<username>:100000:65536" >> /etc/subgid
loginctl enable-linger <username>

# Create cgroup delegation override
mkdir -p /etc/systemd/system/user@.service.d/
cat > /etc/systemd/system/user@.service.d/delegate.conf << EOF
[Service]
Delegate=cpu cpuset io memory pids
EOF
systemctl daemon-reload
```

```bash
# User steps (as the target user):
dockerd-rootless-setuptool.sh install
export DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock

# PMSS-managed accounts receive this setting automatically; retain this for
# self-managed dedicated/baremetal servers where PMSS does not manage the account:
mkdir -p ~/.config/docker
echo '{"exec-opts":["native.cgroupdriver=cgroupfs"]}' > ~/.config/docker/daemon.json
systemctl --user restart docker.service

# Then use docker-compose with LinuxServer.io containers
docker compose up -d
```

**Recommendation**: Use the native `install-media-stack.sh` for PMSS shared hosting. Reserve Docker for environments where you control system configuration.

## Security Model

All media stack applications bind to `127.0.0.1` only and are reverse-proxied through the user's lighttpd instance under `/public-<username>/<app>/`.
Generated lighttpd proxy fragments enable HTTP upgrade forwarding so WebSocket-capable apps can keep live connections through the same per-user proxy path.

The `/public-<username>/` path is **intentionally unauthenticated** at the proxy level — it exists for web hosting, file sharing, and public-facing services. **App-level authentication is the user's responsibility.**

| Application | Auth on Install | User Action Required |
|-------------|----------------|---------------------|
| Jellyfin | First-run wizard forces admin account creation | None — wizard handles it |
| Radarr | Disabled (`AuthenticationMethod: None`) | Enable Forms/Basic auth in Settings → General → Authentication |
| Sonarr | Disabled (`AuthenticationMethod: None`) | Enable Forms/Basic auth in Settings → General → Authentication |
| Prowlarr | Disabled (`AuthenticationMethod: None`) | Enable Forms/Basic auth in Settings → General → Authentication |
| SABnzbd | Setup wizard on first access | Complete wizard to set credentials |
| Autobrr | Built-in login on first access | Complete the setup flow and configure filters/download clients |

**Until users configure authentication, Radarr/Sonarr/Prowlarr are accessible to anyone who knows the URL. SABnzbd's wizard is also reachable so users can set its credentials.**

Additional security measures:
- Randomized ports per user (not security, but obscurity layer)
- HTTPS via lighttpd TLS termination
- No root privileges during install
- Downloaded archives verified with SHA256 checksums
- Security warning printed at install completion

## Rationale (Design Notes)
- Monolithic script to simplify distribution and self‑update while staying easy to audit.
- Small helper functions (`fetch`, `extract_tgz`, logging) to avoid repetition but keep control flow straightforward.
- URL overrides allow quick remediation when upstream endpoints change.
- Dry‑run and verify‑only reduce operational risk by enabling a no‑change validation path.

## Examples
- Default install:
  - `bash install-media-stack.sh`
- Skip self‑update:
  - `bash install-media-stack.sh --skip-update`
- Dry‑run/verify only:
  - `bash install-media-stack.sh --dry-run`
  - `bash install-media-stack.sh --verify-only`
- Pin Radarr version on Debian 11 (x64):
  - `bash install-media-stack.sh --radarr-pin=v5.10.4.9218`
- Fully explicit URLs:
  - `bash install-media-stack.sh --sonarr-url=.../Sonarr.tar.gz --radarr-url=.../Radarr.tar.gz --prowlarr-url=.../Prowlarr.tar.gz --jellyfin-url=.../jellyfin_10.X.Y-amd64.tar.gz --sab-url=.../SABnzbd-x.y.z-src.tar.gz`
