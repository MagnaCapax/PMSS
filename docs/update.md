# Update Workflow

PMSS updates in two deliberate phases. `scripts/update.php` handles snapshot
selection and staging while `scripts/util/update-step2.php` performs the
heavyweight configuration work after the full repository is in place.

## Phase 1 – `scripts/update.php`

Responsibilities:
- Parse the requested source (`git/<branch>`, `release[:tag]`, or a custom repo)
  and optional flags.
- Fetch the snapshot (shallow git clone or release tarball).
- Stage the snapshot by replacing `/scripts` and refreshing `/etc` and `/var`
  trees. `/scripts` and `/etc/skel` are wiped before copying so stale files do
  not survive.
- Record the selected version under `/etc/seedbox/config/version` and
  `version.meta`.
- Re-run itself once if the fetched snapshot updated `update.php`.
- Invoke phase 2 unless explicitly skipped.

Common flags:

```
/scripts/update.php [<spec>] [--repo=<url>] [--branch=<name>] \
    [--dry-run] [--dist-upgrade] [--scripts-only]
```

- `--dry-run` – exercise the staging logic without copying or running phase 2.
- `--dist-upgrade` – run `scripts/util/update-dist-upgrade.php` and exit.
- `--scripts-only` – deploy the new `/scripts` and `/etc/skel` content but skip
  `update-step2.php`; useful for emergency repairs. This mode MUST NOT invoke
  `apt`/`apt-get` or make any other package manager changes; it only stages
  repository files.
- `--repo`/`--branch` – override the default repository when building a `git/*`
  spec on the fly.

### Phase 1 Quick Reference

| Flag / Mode | Behaviour | Verification Steps |
| --- | --- | --- |
| *(default run)* | Stages the selected snapshot and launches phase 2 when the hand-off is not skipped. | Confirm `/var/log/pmss-update.jsonl` contains `update_step2_start` and `update_step2_end`; inspect `/etc/seedbox/config/version` for the expected spec. |
| `--dry-run` | Parses arguments and logs planned staging actions without touching the filesystem or invoking phase 2. | Check the JSON log for `update_step2_skipped` with reason `dry_run`, then run `git status --short` to ensure no tracked files changed; review `/var/log/pmss-update.log` to confirm intended operations. |
| `--scripts-only` | Updates `/scripts` and `/etc/skel` from the snapshot, records the version, and skips `update-step2.php`. Never runs `apt`/`apt-get` or alters package state. | Verify `/var/log/pmss-update.jsonl` shows `update_step2_skipped` with reason `scripts_only`; optionally run `/scripts/util/systemTest.php` to confirm services remain healthy. |
| `--dist-upgrade` | Runs `scripts/util/update-dist-upgrade.php` in place of phase 2, leaving staged files alone. | Check the JSON log for `dist_upgrade_start`/`dist_upgrade_end` entries; review `apt` output in `/var/log/pmss-update.log` and rerun `/scripts/update.php` without the flag to complete orchestration. |
| `--repo` / `--branch` | Overrides the repository or branch when resolving a `git/*` spec before staging. | Confirm the resolved spec under `/etc/seedbox/config/version.meta`; optionally run `/scripts/update.php --dry-run` with the same flags to validate fetch and staging. |

Version specs normalise user input so `main`, `git main`, and `git/main` produce
identical results. If no spec is supplied the previously recorded one is reused,
falling back to `git/main`.

Every run emits structured events to `/var/log/pmss-update.jsonl`, making it easy
to audit which spec was applied, whether the run was dry, and if phase 2 was
invoked.

## Phase 2 – `scripts/util/update-step2.php`

Phase 2 executes with the full repository mounted locally, so it may load shared
helpers from `scripts/lib/update/…`. The orchestrator is intentionally thin and
mostly wires together specialised modules:

```
scripts/lib/update/distro.php          # OS detection and legacy self-heal
scripts/lib/update/environment.php     # dpkg/apt environment guards
scripts/lib/update/repositories.php    # sources.list templates and apt refresh
scripts/lib/update/systemPrep.php      # cgroups, slices, base locale and perms
scripts/lib/update/webStack.php        # lighttpd/nginx lifecycle
scripts/lib/update/services/*          # runtime templates, legacy daemons,
                                       # mediainfo installer, security tweaks
scripts/lib/update/userMaintenance.php # per-user refresh and skeleton/cron sync
scripts/lib/update/networking.php      # network template seeding & rollout
scripts/lib/update/runtime/*           # shared runStep/logging/profile helpers
```

Environment hints captured by `install.sh` are passed via `PMSS_HOSTNAME`,
`PMSS_SKIP_HOSTNAME`, `PMSS_QUOTA_MOUNT`, and `PMSS_SKIP_QUOTA`; phase 2 honors
those flags when reapplying legacy hostname/quota defaults.

### Package Phase Ordering

The package phase is a hard invariant: update-step2 must complete every dpkg task
before any other orchestrator steps run. The sequence is:

1. `pmssConfigureAptNonInteractive()` – force unattended apt behaviour.
2. `pmssCompletePendingDpkg()` – finish any interrupted `dpkg --configure` runs.
3. `pmssApplyDpkgSelections()` – apply the codename-specific baseline snapshot.
4. `pmssFlushPackageQueue()` (once the queue is retired, only the dpkg baseline remains).

Do not insert other modules between these calls and never move them later in the
flow. Future work aims to retire ad-hoc apt queues so the dpkg baseline becomes
the sole source of package state. When in doubt, update the baseline snapshot
instead of injecting additional installs elsewhere in the run.

`pmssEnsureRepositoryPrerequisites()` runs ahead of each `apt update` to make
sure external signing keys (notably MediaArea for `mediainfo`) are ready before
the templates add their sources. ProFTPD remains a notorious dpkg failure mode
when hostnames or TLS assets are missing, so `pmssInstallProftpdStack()` keeps
the unit unmasked and retries `dpkg --configure` to stop the package manager
from wedging mid-run.

### App Installer Matrix

| Module | Installs / Tasks | External Sources & Expectations |
| --- | --- | --- |
| `packages.php` | Queues core package groups (system tooling, media/network stack, Python toolchain, misc apps). | Relies on Debian APT (MediaArea repo shipped via templates) and feeds `pmssFlushPackageQueue()`. |
| `acdcli.php` | Installs/upgrades `acd_cli` inside `/opt/acd_cli` virtualenv and links the CLI. | Pulls from GitHub (`git+https://github.com/yadayada/acd_cli.git`) via the venv’s pip; requires python3/venv tooling. |
| `btsync.php` | Maintains BTSync 1.4/2.2 binaries and Resilio `rslsync` under `/usr/bin`. | Downloads binaries from `http://pulsedmedia.com/remote/pkg/`; needs write access to `/usr/bin`. |
| `deluge.php` | Installs or upgrades Deluge; Debian 10 path builds from source, newer releases lean on apt packages. | Debian 10 run pulls PyPI wheels and `https://ftp.osuosl.org/pub/deluge/source/2.0/deluge-2.0.5.tar.xz`; requires `pip`. |
| `docker.php` | Sets up rootless Docker (docker-ce, buildx, compose) and enables user namespaces. | Adds Docker APT repo (`https://download.docker.com/linux/debian`), fetches Docker GPG key, and downloads `slirp4netns` from GitHub for Debian 10/11. |
| `filebot.php` | Ensures FileBot 4.9.4 is installed via dpkg. | Fetches `FileBot_4.9.4_amd64.deb` from `http://pulsedmedia.com/remote/pkg/`. |
| `firehol.php` | Compiles FireHOL firewall suite when missing. | Downloads `firehol-3.1.6.tar.gz` from `http://pulsedmedia.com/remote/pkg/` and builds under `/root/compile`. |
| `iprange.php` | Builds `iprange` from source after package stage completes. | Requires `PMSS_PACKAGES_READY` flag and toolchain packages; pulls `iprange-1.0.4.tar.gz` from `http://pulsedmedia.com/remote/pkg/`. |
| `mono.php` | Installs Mono runtime and clears legacy Sonarr apt entries on old hosts. | Relies on Debian APT; no external mirrors. |
| `openvpn.php` | Seeds EasyRSA, server/client configs, and writes client bundles to `/etc/skel/www`. | Debian 8 downloads EasyRSA from GitHub (`https://github.com/OpenVPN/easy-rsa/...`); expects templates `template.openvpn.*`. |
| `pyload.php` | Creates `/opt/pyload` venv and installs `pyload-ng`. | Installs deps via apt then uses pip (PyPI) inside the venv; honours `PMSS_DISTRO_VERSION`. |
| `python.php` | Provisions FlexGet + gdrivefs virtualenv and CLI symlink. | Executes pip installs (PyPI) for FlexGet stack; assumes Python 3/venv available. |
| `radarr.php` | Fetches newest Radarr build and deploys to `/opt/Radarr`. | Calls GitHub Releases API (`https://api.github.com/repos/Radarr/Radarr`); downloads tarball via curl. |
| `rclone.php` | Pins or updates rclone binary and man page. | Downloads from `https://downloads.rclone.org/`; optional latest check hits `https://rclone.org/downloads/`; honours `PMSS_RCLONE_FETCH_LATEST`. |
| `rtorrent.php` | Rebuilds rTorrent/libtorrent (plus xmlrpc-c), refreshes templates, restarts daemons. | Fetches tarballs from `http://pulsedmedia.com/remote/pkg/`, checks out xmlrpc-c via SourceForge SVN; needs build toolchain. |
| `sonarr.php` | Installs latest Sonarr under `/opt/Sonarr` and records version metadata. | Uses GitHub Releases API (`https://api.github.com/repos/Sonarr/Sonarr`); removes legacy apt repo artifacts. |
| `syncthing.php` | Ensures syncthing binary matches pinned version. | Downloads binary from `http://pulsedmedia.com/remote/pkg/` into `/usr/bin`. |
| `vnstat.php` | Installs/configures vnStat for the detected uplink. | Uses Debian APT; depends on `scripts/lib/networkInfo.php` for interface info. |
| `watchdog.php` | Disables and removes the distro watchdog daemon. | APT operations only; no external downloads. |
| `wireguard.php` | Generates WireGuard keys/configs, publishes README, distributes to user homes. | Requires `wg` binaries (from package phase), templates `template.wireguard.*`, and queries `https://pulsedmedia.com/remote/myip.php` for endpoint detection. |

Other Python-driven installers (e.g. Deluge’s Debian 10 bootstrap) still rely on the system interpreter; track them for future virtualenv migrations so pip activity stays isolated per app.

### Execution Outline

1. Detect distro name/version/codename and ensure `update.php` is up to date.
2. Enforce non-interactive apt settings and finish any pending dpkg configs.
3. Immediately refresh APT repositories and install every queued package _before_ any
   other orchestration (this ordering is mandatory for all future regressions). Once
   the dpkg baselines include the full package set we can drop the per-app queue entirely.
4. Prepare the host (cgroups, systemd slices, base permissions, MOTD, locales) and
   reapply legacy installer defaults (sysctl tuning, root shell config, `/home`
   permissions, hostname/quota overrides exported by `install.sh`).
5. Apply repository templates, refresh apt indexes, migrate legacy files.
6. Run application installers under `scripts/lib/update/apps/*.php`.
7. Configure the web stack, disable legacy daemons, and install supporting
   packages (e.g., mediainfo, Let’s Encrypt helpers).
8. Update every user environment via `pmssUpdateUserEnvironment` and rescan
   skeletons, crontabs, and logrotate policies.
9. Reapply network templates, apply security hardening, summarise profiling, and
   log completion markers.

Every step flows through the shared `runStep()` helper which logs to
`pmss-update.log`, records JSON events, and collects profiling metadata. When
`PMSS_DRY_RUN=1` the orchestration still logs planned work but skips execution.

### TODO: Profiling coverage
- Move toward a thinner top-level orchestrator that launches each unit of work
  under a profiling wrapper. No bare function calls or shell execs should be
  outside the profiling layer so JSON/profile output reflects every step.

## Usage Examples

Upgrade to the latest release:
```
/scripts/update.php release
```

Deploy the `wireguard` branch but skip phase 2 (useful for hotfixing scripts):
```
/scripts/update.php git/wireguard --scripts-only
```

Dry-run a release update to inspect logging only:
```
/scripts/update.php release --dry-run
```

## Operational Tips

- Always run `php -l scripts/update.php` and
  `php scripts/lib/tests/development/Runner.php` after touching update logic.
- Aim for comprehensive testing: add new unit/integration coverage when you modify services, and run smoke tests (`/scripts/update.php --dry-run`) before shipping.
- Check `/var/log/pmss-update.jsonl` for a structured summary of the last run;
  a missing `update_step2_end` event typically means phase 2 was skipped.
- When developing helpers under `scripts/lib/update`, mirror the existing
  pattern: one focused responsibility per file, concise docblocks, and reuse of
  the runtime helpers for logging.

### Release Strategy
- Rolling updates by default: the fleet intentionally runs a mix of versions across hosts; updates roll forward progressively and can be paused.
- Coexistence across distros: Debian 10 and 11 hosts are operated side‑by‑side; update logic must remain compatible with both baselines.
- Script rollbacks: scripts and configs can be reverted independently of package state; keep pre‑change backups and version metadata for quick rollback.

Keeping the bootstrap minimal and the second phase modular allows PMSS to update
safely even on partially broken systems while keeping the complex logic in files
that are easy to test and reason about.
