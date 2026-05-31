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
- Keep `/etc/cron.d/pmss` active through phase-1 staging; disable it only for
  the immediate phase-2 handoff and restore it from a shutdown/signal guard.
- Self-heal `cron.service` when systemd reports it as masked, because PMSS
  watchdogs, quotas, and traffic jobs depend on root cron.
- If phase 2 exits non-zero after staging, make a best-effort
  `setupPermissions.php` pass before surfacing the failure so
  `/etc/seedbox` remains traversable.

Common flags:

```
/scripts/update.php [<spec>] [--repo=<url>] [--branch=<name>] \
    [--dry-run] [--dist-upgrade=<max>] [--scripts-only]
```

- `--dry-run` – exercise the staging logic without copying or running phase 2.
- `--dist-upgrade=<max>` – perform a Debian dist-upgrade (one major step per run) but never beyond the specified maximum; then continue with phase 2 to rebuild compiled components.
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
| `--scripts-only` | Updates `/scripts` and `/etc/skel` from the snapshot, records the version, and skips `update-step2.php`. Never runs `apt`/`apt-get` or alters package state. Also refreshes skeleton permissions and FTP config when helpers are available. | Verify `/var/log/pmss-update.jsonl` shows `update_step2_skipped` with reason `scripts_only`; optionally run `/scripts/util/systemTest.php` to confirm services remain healthy. |
| `--dist-upgrade=<max>` | Runs the dist-upgrade helper (from `scripts/lib/update/distUpgrade.php`) to perform a one-step Debian release upgrade, capped at the requested maximum, then continues with phase 2. | Check the JSON log for `dist_upgrade_start`/`dist_upgrade_end` and `update_step2_start`/`update_step2_end` entries; review `apt` output in `/var/log/pmss-update.log`. |
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
mostly wires together specialised modules, while keeping a few one-caller steps
inline:

```
scripts/lib/update/distro.php          # OS detection and legacy self-heal
scripts/lib/update/environment.php     # dpkg/apt environment guards
scripts/lib/update/filesystem.php      # warning-only filesystem preflights
scripts/lib/update/repositories.php    # sources.list templates and apt refresh
scripts/lib/update/systemPrep.php      # cgroups, slices, base locale and perms
scripts/lib/update/services/*          # runtime templates, legacy daemons,
                                       # mediainfo installer, security tweaks
scripts/lib/update/userMaintenance.php # per-user refresh and skeleton/cron sync
scripts/lib/update/networking.php      # network template seeding & rollout
scripts/lib/update/runtime/*           # shared runStep/logging/profile helpers
```

The lighttpd/nginx lifecycle step stays inline in `scripts/util/update-step2.php`
because phase 2 is its only caller.

Environment hints captured by `install.sh` are passed via `PMSS_HOSTNAME`,
`PMSS_SKIP_HOSTNAME`, `PMSS_QUOTA_MOUNT`, and `PMSS_SKIP_QUOTA`; phase 2 honors
those flags when reapplying legacy hostname/quota defaults. Optional hardening
can be enabled with `PMSS_HARDEN_TMP_NOEXEC=1` to add `noexec,nosuid,nodev` to
`/tmp` and `/dev/shm` mounts (opt-in; may impact workflows that execute from
`/tmp`). To provision a dedicated tmpfs-backed `/tmp`, set
`PMSS_HARDEN_TMP_TMPFS=1`; the default size is `2G` and can be overridden via
`PMSS_TMPFS_TMP_SIZE` (e.g. `512M`). Enabling tmpfs overlays any existing `/tmp`
contents, so plan for services that may have open handles.

### Package Phase Ordering

Package recovery and package installation are hard invariants: after lock
acquisition and fatal preflight checks, update-step2 must repair interrupted
dpkg configuration before any warning-only probes or other orchestrator steps
run. The sequence is:

1. `pmssCompletePendingDpkg()` – finish any interrupted `dpkg --configure` runs
   before `/home` inode-density checks or other PHP/apt-adjacent work. The
   direct `dpkg --configure -a` call uses `--force-confdef --force-confold`
   because dpkg conffile prompts do not honor `DEBIAN_FRONTEND`.
2. Warning-only probes and distro detection run against the recovered package
   state.
3. `pmssConfigureAptNonInteractive()` – force unattended apt behaviour; runtime
   command wrappers also export `DEBIAN_FRONTEND=noninteractive`,
   `APT_LISTCHANGES_FRONTEND=none`, `UCF_FORCE_CONFDEF=1`,
   `UCF_FORCE_CONFOLD=1`, and `NEEDRESTART_MODE=a` for apt/dpkg recovery
   commands.
4. `pmssApplyDpkgSelections()` – apply the codename-specific baseline snapshot.
5. `pmssApplyDpkgSelections()` recovery + post-phase fix-broken/autoremove checks.

Do not move the dpkg recovery below probes, and do not move apt configuration or
baseline application later in the flow. The dpkg baseline is now the sole source
of package state in this phase; when in doubt, update the baseline snapshot
instead of injecting ad-hoc installs elsewhere in the run.

### Step Error Classification

Post-package orchestration uses explicit step classes:

1. `must_succeed` — after package phase completion, logs `step_failed`
   (`severity=error`) and aborts update-step2.
2. `soft_fail` — logs `step_failed` (`severity=warn`) and continues.
3. `skip_if_missing` — reserved for optional dependencies that may be absent;
   log and continue when missing.

Current `must_succeed` annotations cover runtime template deployment (including
the sshd AuthorizedKeysFile convergence), and web stack configuration.

`pmssRefreshRepositories()` ensures external repo prerequisites (currently the
Docker deb822+keyring and Sonarr scoped keyring) exist before it runs `apt update`.
ProFTPD remains a notorious dpkg failure mode when hostnames or TLS assets are
missing, so `pmssCompletePendingDpkg()` keeps the unit unmasked and retries
`dpkg --configure` to stop the package manager from wedging mid-run.

### External Repository Trust Inventory (Phase A)

This inventory documents all non-Debian repositories that PMSS currently manages.
It captures where each source is defined, where its signing key is stored, and
whether trust is scoped or global.

| Repository | Source template/path | Key install path | `signed-by` usage | Trust scope | Notes |
| --- | --- | --- | --- | --- | --- |
| Docker | `/etc/apt/sources.list.d/docker.sources` (written by `pmssEnsureDockerRepository()` in `scripts/lib/update/repositories.php`) | `/etc/apt/keyrings/docker.gpg` (override root: `PMSS_APT_KEYRING_DIR`) | Yes (`Signed-By` field in deb822 source) | Scoped to Docker source entry | Matches ADR 0008 guidance for key scoping without migrating base Debian templates. |
| MediaArea | `etc/seedbox/config/template.sources.buster`, `etc/seedbox/config/template.sources.bullseye`, `etc/seedbox/config/template.sources.bookworm`, `etc/seedbox/config/template.sources.trixie` | `/etc/apt/trusted.gpg.d/mediaarea.asc` (override: `PMSS_APT_MEDIAAREA_KEY_PATH`) | No | Global (`trusted.gpg.d`) | Repository handling is intentionally frozen; audit only in this phase. |
| Sonarr (legacy key support) | No active PMSS-managed source template; legacy host entries may still exist outside templates | `/etc/apt/keyrings/sonarr.gpg` (overrides: `PMSS_APT_KEYRING_DIR`, `PMSS_APT_SONARR_KEY_PATH`) | `pmssEnsureSonarrKey()` rewrites legacy Sonarr/NzbDrone `deb` lines with `signed-by=` | Scoped after rewrite; legacy global key removed when migration succeeds | App install still uses GitHub release tarballs; this path only keeps mixed/legacy hosts compatible during apt refresh. |

Base Debian repos remain in `sources.list` templates per ADR 0008, so this
table only tracks external/non-Debian sources.

### App Installer Matrix

| Module | Installs / Tasks | External Sources & Expectations |
| --- | --- | --- |
| `aiToolsInstall.php` | Installs system-wide Gemini CLI, Claude Code, and pinned Codex CLI for all users. | Downloads pinned Node.js/Codex artifacts over HTTPS and installs npm packages into `/opt/pmss/ai-tools`. |
| `btsync.php` | Maintains BTSync 1.4/2.2 binaries and Resilio `rslsync` under `/usr/bin`. | Downloads binaries from `http://pulsedmedia.com/remote/pkg/`; needs write access to `/usr/bin`. |
| `deluge.php` | Installs or upgrades Deluge; Debian 10 path builds from source, newer releases lean on apt packages. | Debian 10 run pulls PyPI wheels and `https://ftp.osuosl.org/pub/deluge/source/2.0/deluge-2.0.5.tar.xz`; requires `pip`. |
| `docker.php` | Sets up rootless Docker (docker-ce, buildx, compose) and enables user namespaces. | Adds Docker APT repo (`https://download.docker.com/linux/debian`), fetches Docker GPG key, and downloads `slirp4netns` from GitHub for Debian 10/11. |
| `filebot.php` | Ensures FileBot 4.9.4 is installed via dpkg. | Fetches `FileBot_4.9.4_amd64.deb` from `http://pulsedmedia.com/remote/pkg/`. |
| `firehol.php` | Compiles FireHOL firewall suite when missing. | Fetches the pinned `firehol-3.1.8.tar.gz` release from GitHub over HTTPS, verifies SHA256, and builds under `/root/compile`. |
| `iprange.php` | Builds `iprange` from source after package stage completes. | Requires `PMSS_PACKAGES_READY` flag and toolchain packages; fetches the pinned `iprange-1.0.4.tar.xz` release from GitHub over HTTPS with SHA256 verification. |
| `mono.php` | Installs Mono runtime and clears legacy Sonarr apt entries on old hosts. | Relies on Debian APT; no external mirrors. |
| `openvpn.php` | Seeds EasyRSA, server/client configs, and writes client bundles to `/etc/skel/www`. | Debian 8 downloads EasyRSA from GitHub (`https://github.com/OpenVPN/easy-rsa/...`); expects templates `template.openvpn.*`. |
| `pyload.php` | Creates `/opt/pyload` venv and installs `pyload-ng`. | Installs deps via apt then uses pip (PyPI) inside the venv; honours `PMSS_DISTRO_VERSION`. |
| `python.php` | Provisions FlexGet + gdrivefs virtualenv and CLI symlink. | Executes pip installs (PyPI) for FlexGet stack; assumes Python 3/venv available. |
| `servarr.php` | Retained as the shared ARR updater entrypoint, but excluded from the default update-step2 app autoloader. | Per-account media-stack installs are handled from the skeleton/user tooling; system update must not block on ARR release checks or binary probes. |
| `rclone.php` | Pins or updates rclone binary and man page. | Downloads from `https://downloads.rclone.org/`; optional latest check hits `https://rclone.org/downloads/`; honours `PMSS_RCLONE_FETCH_LATEST`. |
| `rtorrent.php` | Rebuilds rTorrent/libtorrent (plus xmlrpc-c), refreshes templates, restarts daemons. | Fetches pinned tarballs from `https://pulsedmedia.com/remote/pkg/` with SHA256 verification, checks out xmlrpc-c via SourceForge SVN; needs build toolchain. |
| `syncthing.php` | Ensures syncthing binary matches the pinned amd64 release. | Fetches the pinned upstream tarball from GitHub over HTTPS, verifies SHA256, and installs `syncthing` into `/usr/bin`. |
| `vnstat.php` | Installs/configures vnStat for the detected uplink. | Uses Debian APT; depends on `scripts/lib/networkInfo.php` for interface info. |
| `watchdog.php` | Disables and removes the distro watchdog daemon. | APT operations only; no external downloads. |
| `wireguard.php` | Generates WireGuard keys/configs, publishes README, distributes to user homes. | Requires `wg` binaries (from package phase), templates `template.wireguard.*`, and queries `https://pulsedmedia.com/remote/myip.php` for endpoint detection. |

Other Python-driven installers (e.g. Deluge’s Debian 10 bootstrap) still rely on the system interpreter; track them for future virtualenv migrations so pip activity stays isolated per app.

### Execution Outline

1. Acquire the phase-2 lock, run fatal preflight checks, finish pending dpkg
   configs, then run warning-only probes including `/home` inode density
   detection for media-stack workloads.
2. Detect distro name/version/codename and ensure `update.php` is up to date.
3. Enforce non-interactive apt settings for subsequent apt work.
4. Immediately refresh APT repositories and apply the codename-selected dpkg baseline
   _before_ any other orchestration (this ordering is mandatory for all regressions).
5. Prepare the host (cgroups, systemd slices, base permissions, MOTD, locales) and
   reapply installer defaults (hardware-aware late-order sysctl tuning, Debian 13+
   `/tmp` disk-backed baseline, root shell config, `/home`
   permissions, hostname/quota overrides exported by `install.sh`).
6. Apply repository templates, refresh apt indexes, migrate legacy files.
7. Run system-level application installers under `scripts/lib/update/apps/*.php`,
   excluding helper modules and account-scoped media-stack maintenance.
8. Configure the web stack, regenerate per-user nginx configs from staged
   templates, disable legacy daemons, and install supporting packages
   (e.g., mediainfo, Let’s Encrypt helpers).
9. Update every user environment via `pmssUpdateAllUsers()`, which also owns
   linger/rootless-Docker wiring and the optional post-refresh checks
   (user crontabs are user-owned and not rewritten).
10. Reapply network templates, apply security hardening, summarise profiling, and
   log completion markers. Per-user traffic monitoring rules rely on the
   iptables owner match; when unavailable `setupNetwork.php` skips those rules
   and logs to `/var/log/pmss/iptables.log`.

Every shell command flows through `runStep()`, and non-shell module calls are
wrapped by `pmssRunProfiledStep()`/`pmssRunProfiledCallable()` (plus classified
wrapper helpers where strict handling is required) in
`scripts/util/update-step2.php` so profile JSON captures each orchestration
step with stable labels. `PMSS_DRY_RUN=1` still logs planned work while command
execution is skipped.

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
- Treat inode exhaustion on `/home` as a valid migration trigger for
  `/scripts/util/userTransfer.php`, alongside hardware failure and capacity
  rebalancing. The phase-2 inode-density warning is diagnostic only; reformat or
  evacuation decisions remain operator-planned maintenance.
- Aim for comprehensive testing: add new unit/integration coverage when you modify services, and run smoke tests (`/scripts/update.php --dry-run`) before shipping.
- Check `/var/log/pmss-update.jsonl` for a structured summary of the last run;
  a missing `update_step2_end` event typically means phase 2 was skipped.
- When developing helpers under `scripts/lib/update`, mirror the existing
  pattern: one focused responsibility per file, concise docblocks, and reuse of
  the runtime helpers for logging.

### Interactive capture (development)

To capture the full interactive run while keeping output visible, use `script`:

```
script -q -c "/scripts/update.php release" /tmp/pmss-update.typescript
```

For remote runs, force a TTY:

```
ssh -t root@HOST "script -q -c '/scripts/update.php release' /tmp/pmss-update.typescript"
```

Phase 1 logs to `/var/log/pmss/update.log` and emits JSON to `/var/log/pmss-update.jsonl`. Phase 2 logs to `/var/log/pmss-update.log` and uses `PMSS_JSON_LOG` (set by `update.php`) for JSON events. When running `update-step2.php` standalone, set `PMSS_JSON_LOG=/var/log/pmss-update.jsonl` to keep JSON output consistent.

### Release Strategy
- Rolling updates by default: the fleet intentionally runs a mix of versions across hosts; updates roll forward progressively and can be paused.
- Coexistence across distros: Debian 10 and 11 hosts are operated side‑by‑side; update logic must remain compatible with both baselines.
- Debian 13 (trixie) is experimental; do not assume full support until the dpkg baseline is captured and validated.
- Script rollbacks: scripts and configs can be reverted independently of package state; keep pre‑change backups and version metadata for quick rollback.

Keeping the bootstrap minimal and the second phase modular allows PMSS to update
safely even on partially broken systems while keeping the complex logic in files
that are easy to test and reason about.
