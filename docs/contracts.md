# PMSS Function and Script Contracts

This document captures “contract-style” behavior for key functions and scripts
so future agents can safely reuse or reimplement them. It focuses on inputs,
outputs, side-effects, error behaviors, environment flags, and external
touchpoints (files/commands).

Use it as a quick reference when modifying or calling into PMSS from automation
or tests.

## Conventions
- Paths, commands, and env vars shown are literal unless noted.
- “Output” refers to return value or generated data on disk.
- “Side-effects” list filesystem/network/process changes.
- Errors are fail-soft unless a helper explicitly exits/fatal.

---

## Updater Bootstrap (Phase 1) – `scripts/update.php`

Signature: refer to file for full source; highlights below.

- parseArguments(array $argv): array
  - Inputs: CLI args; supports `<spec>`, `--dry-run`, `--dist-upgrade=<max>`, `--scripts-only`,
    `--repo=<url>`, `--branch=<name>`, and internal `--skip-self-update`.
  - Output: array keys: `dry_run` (bool), `dist_upgrade` (bool), `scripts_only` (bool),
    `skip_self_update` (bool), `spec` (string), `repo` (?string), `branch` (?string).
  - Side-effects: `--help|-h` prints usage and `exit(0)`.

- storedSpec(): string
  - Reads `/etc/seedbox/config/version`, strips `@<timestamp>`, trims. Empty when missing.

- normaliseSpec(string $spec): string
  - Accepts loose input (e.g., `git main`, `release 2025-01-01`, bare `dev` branch,
    URLs) and returns normalized spec (`git/...` or `release:...`) or `''` if invalid.

- parseSpec(string $spec): array
  - Parses normalized spec into: `type` (`git|release`), `repo`, `branch`, `pin` (date).
  - On mismatch: `fatal(..., EXIT_PARSE)`.

- createWorkdir(): string
  - Creates 0700 temp dir; `fatal(EXIT_FETCH)` if mkdir fails.

- resolveLatestRelease(): string
  - Fetches GitHub latest release tag; `fatal(EXIT_FETCH)` on HTTP/parse failure.

- fetchSnapshot(array $spec, string $tmp): void
  - For `release`: `curl` tarball and `tar -xzf` into `$tmp`.
  - For `git`: shallow clone with branch; optional `git checkout <branch>@{<pin>}`.
  - Errors: `runFatal(EXIT_FETCH)` on failure.

- stageSnapshot(string $tmp, bool $dryRun): void
  - Copies `scripts/`, `etc/`, `var/` from `$tmp` into live FS.
  - Wipes `/scripts/*`; clears `/etc/skel/*` when snapshot contains `etc/skel/`.
  - Post-copy (non-dry-run): chmod hardening; `flattenScriptsLayout()`.
  - Errors: `runFatal(EXIT_COPY)` on copy/chmod failures.

- flattenScriptsLayout(): void
  - If `/scripts/scripts` exists, copies its contents up and removes nested folder.

- collectCommitHash(string $tmp): string → `git rev-parse HEAD` or `''`.

- recordVersion(string $spec, array $details, bool $dryRun): void
  - Writes `/etc/seedbox/config/version` and pretty JSON `version.meta` (skipped in dry-run).

- cleanup(string $path): void → rm -rf `$path` best-effort.

- maybeSelfUpdate(array $argv, bool $dryRun, bool $skipSelfUpdate, string $originalHash): bool
  - If `update.php` hash changed after staging, re-invokes itself with `--skip-self-update`.

- currentUpdaterHash(): string → SHA-256 of the current file or `''`.

- runUpdateStep2(bool $dryRun): void
  - Exports `PMSS_JSON_LOG` path and keeps `PMSS_CORRELATION_ID` available for phase 2/child processes; dry-run or missing file emits `update_step2_skipped`.
  - Else runs `/scripts/util/update-step2.php`, logs start/end + duration, and on non-zero exit makes a best-effort `scripts/util/setupPermissions.php` pass before `fatal`.

- maybeRunDistUpgrade(bool|string $distUpgrade): void
  - If enabled, runs `pmssRunDistUpgrade(<max>)` from `scripts/lib/update/distUpgrade.php` and logs start/end events.
  - Restores root cron unless restoration is deferred to update-step2.

- bootstrapMain(array $argv): void
  - Orchestrator: ensure root → parse/normalize/parse spec → workdir fetch → stage →
    record version → cleanup → self-update handoff → dist-upgrade (optional) →
    run phase 2 or scripts-only path → log completion with duration.
- Update lock: uses `PMSS_UPDATE_LOCK_FILE=/var/lib/pmss/update.lock` with a
  bounded non-blocking exclusive flock. It sets `PMSS_UPDATE_LOCK_ENV=1` when
  held so child re-exec skips re-acquiring. Emits JSON events
  `update_lock_wait`, `update_lock_busy`, `update_lock_busy_skip`,
  `update_lock_acquired`, and `update_lock_released`. If the lock remains busy
  after the bounded wait, the updater logs a warning and exits successfully
  without staging so fleet orchestration does not hang on stale inherited FDs.

Environment flags consumed: `PMSS_CORRELATION_ID` (generated early when missing; inherited by phase 2 and child commands).

Logs: `/var/log/pmss/update.php.log` (stdout mirror) and JSON `/var/log/pmss-update.jsonl` (`pmss_correlation_id` included on JSON events).

---

## Runtime Execution & Profiling

- runCommand(string $cmd, bool $verbose=false, ?callable $logger=null, bool $inheritTty=false): int
  - Spawns `/bin/bash -lc <cmd>` via `proc_open`, streams stdout/stderr, returns rc.
  - Exposes `$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']` with `stdout`/`stderr`.
  - Timeout: defaults to 1200s; apt/dpkg commands are floored to 1200s. `PMSS_COMMAND_TIMEOUT` overrides but cannot reduce apt/dpkg below 1200s.
  - Timeout termination: sends SIGTERM first, then SIGKILL after a 5s grace period when the child lingers. Timeout fires append structured `timeout_fired` JSONL to `PMSS_TIMEOUT_FIRE_LOG` or `/var/log/pmss-timeout-fires.jsonl`.
  - When `$inheritTty=true` and stdin/stdout/stderr are TTYs, inherits the terminal for interactive prompts; output capture is disabled (`stdout`/`stderr` set to empty strings).
  - On non-zero rc, logs warning with 300-char stderr excerpt.

- runStep(string $description, string $command): int
  - Honors `PMSS_DRY_RUN=1` (rc=0, status=SKIP); logs `[OK|ERR|SKIP <secs> rc=<n>] ...`.
  - Records profile entry with duration, rc, and 300-char stdout/stderr excerpts.

- runUserStep(string $user, string $description, string $command): int
  - Same as `runStep` but prefixes description with `[user:<name>]`.

- aptCmd(string $args): string
  - Returns apt-get command prefix with non-interactive dpkg options.
- pmssIopingAverageMs(?string $target): ?float
  - Runs the canonical `ioping -c 60 -i 0.1 -D <target>` probe and parses the
    summary average in milliseconds. Missing binaries, empty targets, and
    malformed output return `null`.
- pmssIopingMedianMs(?string $target): ?float
  - Uses the same direct-I/O probe, parses per-request latency samples, and
    returns their median in milliseconds. Falls back to the summary average
    when older/stubbed output lacks individual sample lines.

- pmssRecordProfile(array $entry): void → lazily initializes `$GLOBALS['PMSS_PROFILE']`, appends the entry, and emits JSON `step` event.
- pmssProfileSummary(): void → logs status counts and top 5 durations; writes full JSON to `PMSS_PROFILE_OUTPUT` or `(<PMSS_JSON_LOG>.profile.json)`.

---

## Logging & JSON Events

## Agent Diagnostics – `scripts/util/agentDiagnostics.php`

- pmssAgentDiagnosticsMain(array $argv): int
  - Inputs: `--json`, `--pretty`, `--user USERNAME`, `--help`.
  - Output: text report by default, or JSON envelope with `timestamp`, `hostname`, `version`, `user`, and `sections`.
  - Side-effects: none intended; collects read-only command and file snapshots.
  - Validation: requires root outside `PMSS_TEST_MODE=1`; `--user` is validated through managed-user selection before per-user sections run.

---

- pmssLogDir(): string → `PMSS_LOG_DIR` or `/var/log/pmss`.
- pmssRuntimeDir(): string → `PMSS_RUNTIME_DIR` or `/var/run/pmss`.
- pmssStateDir(): string → `PMSS_STATE_DIR` or `/var/lib/pmss`.
- pmssJsonLogPath(): string → cached `PMSS_JSON_LOG` or `''`.
- pmssCorrelationId(bool $createIfMissing=true): string → resolves `PMSS_CORRELATION_ID`; generates `<UTC timestamp>-<host>-<hex>` when missing.
- pmssLogJson(array $payload): void → appends JSONL with added `ts` and `pmss_correlation_id` if path configured.
- logMessage(string $message, array $context=[]): void → writes to `PMSS_LOG_FILE` (default `pmssLogDir()/update.log`), mirrors to stdout, and emits a JSON `log` event when JSON logging is configured.
- logmsg(string $message): void → legacy updater entry point; once `scripts/lib/update.php` bootstraps structured logging it forwards to `logMessage()` so older modules keep the shared logger contract.
- pmssUserLog(string $user, string $message): void → appends to `/var/log/pmss/users/<user>.log` (migrates legacy `/var/log/pmss/user`), no-ops when not running as root; mirrors to `users.log`/`users.jsonl` when lifecycle helpers are available.

---

## APT Repository Management

- pmssLoadRepoTemplate(string $codename, ?callable $logger=null): string
  - Loads `/etc/seedbox/config/template.sources.<codename>` (or `PMSS_CONFIG_DIR`).
  - Returns trimmed content with trailing `\n`, or `''` and logs when missing/empty.

- pmssSafeWriteSources(string $content, string $label, ?callable $logger=null): bool
  - Uses `PMSS_APT_SOURCES_PATH` (default `/etc/apt/sources.list`), backs up current sources to `.pmss-backup` (best-effort), writes new content or restores on failure.

- pmssUpdateAptSources(string $distroName, int $distroVersion, string $currentHash, array $repos, ?callable $logger=null): void
  - Dispatches by distro: Debian uses `pmssUpdateAptSourcesDebian`; Ubuntu logs unsupported.

- pmssUpdateAptSourcesDebian(int $version, string $currentHash, array $repos, callable $log): void
  - Applies templates for Jessie/Buster/Bullseye/Bookworm/Trixie; compares hash, writes via `pmssSafeWriteSources`, and logs “Applied ...” or “already correct”. Jessie also writes an apt conf to ignore release dates and cleans cache.

- pmssEnsureMediaareaRepository(): void → removes legacy MediaArea `.list/.sources` files and ensures the MediaArea signing key exists at `/etc/apt/trusted.gpg.d/mediaarea.asc` (override: `PMSS_APT_MEDIAAREA_KEY_PATH`); best-effort fetch unless `PMSS_DRY_RUN=1`.
- pmssEnsureSonarrKey(): void → ensures Sonarr keyring material exists at `/etc/apt/keyrings/sonarr.gpg` (overrides: `PMSS_APT_KEYRING_DIR`, `PMSS_APT_SONARR_KEY_PATH`), rewrites legacy Sonarr/NzbDrone `deb` lines with `signed-by=...`, and removes `/etc/apt/trusted.gpg.d/sonarr.gpg` when scoping succeeds (overrides: `PMSS_APT_SONARR_LEGACY_KEY_PATH`, `PMSS_APT_SOURCES_LIST_D_PATH`).
- pmssEnsureDockerRepository(): void → ensures Docker deb822 source + keyring exist under `/etc/apt/sources.list.d/docker.sources` and `/etc/apt/keyrings/docker.gpg`.
- pmssRepositoryUpdatePlan(string $distroName, int $distroVersion, ?callable $logger=null): array
  - `mode=reuse` (unknown version) or `mode=update` with current hash and loaded templates.
- pmssRefreshRepositories(string $distroName, int $distroVersion, ?callable $logger=null): bool
  - Ensures MediaArea/Docker/Sonarr repo prerequisites, computes plan; runs `apt-get update` either way, with template write on update; updates `/var/lib/apt/periodic/update-success-stamp` only on success.

---

## Environment & Dpkg Baselines

- pmssConfigureAptNonInteractive(?callable $logger=null): void
  - Ensures `/etc/apt/apt.conf.d/90pmss-noninteractive` matches known content; logs SKIP/Updated; 0644.

- pmssCompletePendingDpkg(): void
  - Runs `dpkg --configure -a`. On error: unmask `proftpd.service` (systemd) and retry proftpd package configure.

- pmssApplyDpkgSelections(?int $distroVersion=null): bool
  - Picks selection file (`selections-debian<version>.txt`, fallback to newest validated baseline, then generic `selections.txt`). Debian 13 remains on the Debian 12 validated fallback until its own baseline is captured and replay-validated.
  - `apt-get update`, `apt-cache dumpavail | dpkg --merge-avail`.
  - Sanitizes lines to `pkg<TAB>state`, ignoring malformed entries; uses temp file.
  - `dpkg --set-selections < file` then `apt-get dselect-upgrade -y`; retries with `--fix-broken`.
  - Returns true on successful apply+install; logs warnings.

- pmssMigrateLegacyLocalnet(): void → move `/etc/seedbox/localnet` to `/etc/seedbox/config/localnet` once.

---

## System Preparation

- pmssHomeInodeDensityCheck(?callable $logger=null, string $path='/home', int $warnThresholdBytes=262144): void
  - Inputs: mounted filesystem path, optional logger, warning threshold in bytes per inode.
  - Output: logs `[OK]`, `[WARN]`, or `[SKIP]` only; no return value.
  - Side-effects: runs `stat -f -c "%S %b %c" <path>` through `runStep()` and emits `home_inode_density` JSON when JSON logging is enabled.
  - Errors: fail-soft; missing path, stat failure, malformed output, or zero values log warning/skip and never abort update-step2.

- pmssEnsureLegacySysctlBaseline(?callable $logger=null, ?string $targetOverride=null, bool $reload=true, ?string $modulesLoadOverride=null): void
  - Writes `/etc/sysctl.d/99-pmss.conf` (override path) with the PMSS-owned hardware-aware baseline.
  - Reserves the PMSS port-manager service band from kernel ephemeral source-port selection via `net.ipv4.ip_local_reserved_ports`.
  - Ensures `/etc/modules-load.d/pmss-bbr.conf` contains `tcp_bbr` (override path).
  - Respects operator-owned keys from `/etc/sysctl.d/90-pmss-overrides.conf` and records the applied profile under the `sysctl` section in `/etc/seedbox/config/hardware.json`.
  - When `$reload=true`, runs `sysctl --system` to apply the baseline.

---

## Distro Detection

- pmssDetectDistro(): array
  - Returns `['name'=>string,'version'=>int,'codename'=>string]`.
  - Prefers `VERSION_CODENAME`; maps codename→version; mismatches log “trusting codename”. Falls back to `lsb_release` or defaults.

- pmssVersionFromCodename(string $codename): int → Debian codename→major mapping; unknown→0.

---

## User Environment Orchestration – `scripts/lib/update/users.php` and submodules

- User transfer – `scripts/util/userTransfer.php`
  - Main rsync excludes server-specific torrent client configs such as
    `~/.config/qBittorrent/qBittorrent.conf`.
  - Post-transfer qBittorrent category preservation reads only category-bearing
    source metadata into the private scratch directory, prefers a non-empty
    source `categories.json`, and falls back to legacy `Session\Categories` from
    `qBittorrent.conf`.
  - Output: merges missing category entries into
    `~/.config/qBittorrent/categories.json` without overwriting existing local
    category names; `/home/<remoteUser>/...` save paths are rewritten to
    `/home/<localUser>/...` when usernames differ.
  - Side-effects: writes the merged JSON with mode `0600` before the existing
    `userPermissions.php` normalization step fixes final ownership.
  - Errors: fail-soft; invalid/missing category metadata logs a warning or info
    line and does not abort the transfer.

- pmssUpdateAllUsers(string $rutorrentIndexSha): array
  - Enumerates users from `users::listHomeUsers()`, runs per-user maintenance, and returns summary keys: `total`, `processed`, `skipped`.
  - Emits end-of-loop summary log line `Processed N of M users` and JSON event `user_maintenance_summary`.
  - Calls `pmssUpdateUserEnvironment()` first, then applies linger/rootless-Docker wiring and optional post-refresh checks for each valid user.
  - Before marking a user refreshed, repairs stale per-user systemd drop-ins with bare sub-MiB `MemoryMax=<N>` values and matching suffixed `MemoryLimit=<N>M` siblings by appending the PMSS MiB suffix and reloading systemd.
  - Catches per-user throwables (including permission-step timeouts), logs warning, skips that user, and continues remaining users.

- pmssUpdateUserEnvironment(string $user, string $rutorrentIndexSha=''): void
  - Builds context (`pmssBuildUserContext`), returns early when invalid.
  - Runs handlers in order: HTTP, skeleton, ruTorrent themes, ruTorrent refresh,
    plugin maintenance, then permissions.
    Each handler consumes `['user','home','user_esc','rutorrent_index_sha']`.
- pmssEnsureLingerAndDocker(string $user): void
  - Enables linger + rootless Docker wiring for the user.
  - Skips and attempts `userDocker.php stop` when user config `dockerEnabled` is false (default true, but defaults false for Storage Box product payloads) or the effective RAM floor for Docker is below 245 MiB.
  - Rootless Docker `daemon.json` convergence refuses symlinked or non-regular config targets before writing, then installs changes through a temporary file with mode 0600. On Debian 10/11 with `fuse-overlayfs` available, it also disables Docker's containerd image store so the classic rootless graphdriver honours `storage-driver`.
- `scripts/util/userDocker.php USER ACTION`
  - `stop` and `restart` pass `XDG_RUNTIME_DIR` when using a user systemd unit; a disabled `dockerEnabled` policy uses `systemctl --user disable --now` so the unit does not remain enabled.
  - Any non-zero systemd stop result falls back to the user-scoped rootless stop command, then waits for the short restart window and verifies Docker process state before reporting success.
  - A failed or unknown process check, or surviving Docker PIDs, returns non-zero; `restart` does not continue into the start path after an unsuccessful stop.

Sub-handlers:
- pmssBuildUserContext(string $user, string $rutorrentIndexSha=''): ?array → validates `/home/<user>` with `.rtorrent.rc`, `data`, and no `www-disabled`; returns context.
- pmssUserConfigureHttp(array $ctx): void → configure lighttpd per-user, refresh the PMSS-managed qBittorrent safety defaults and Deluge fleet safety limits, ensure php.ini `error_log`, create `.tmp` and `.irssi` (from skel), and `www/recycle` with perms/ownership.
- pmssUserApplySkeletonFiles(array $ctx): void → copies fixed list of skel files and quota plugin files into user tree using `updateUserFile()`; force-refreshes legacy `~/www/index.php` copies missing the PHP 8.2 `frameData` initialization; deletes `~/www/phpXplorer`.
- pmssUserUpdateThemes(array $ctx): void → ensures named themes exist under `rutorrent/plugins/theme/themes/` (copied from skel), fixes ownership.
- pmssUserUpgradeRutorrent(array $ctx): void → if user’s ruTorrent index.html SHA != skeleton (and no existing backup), backups to `oldRutorrent-3`, copies fresh from skel, restores config/share, updates config via `updateRutorrentConfig()`, fixes ownership and perms.
- pmssUserEnsurePlugins(array $ctx): void → removes deprecated `cpuload`, ensures `unpack` plugin exists and has proper perms, removes legacy `retrackers.dat`, and creates torrents/RSS settings dirs.
- pmssUserRefreshPermissions(array $ctx): void
  - Runs `userPermissions.php` with optional `ionice -c3` wrapper when available.
  - Applies per-user timeout via `PMSS_USER_PERMISSIONS_TIMEOUT` (default 900s) by temporarily setting `PMSS_COMMAND_TIMEOUT` for that command only.
  - Throws `RuntimeException` when permission refresh times out so caller can skip that user and continue the queue.
  - Refreshes `~/.rtorrent.rc.custom` from skel if hash matches legacy list.
- pmssUserPatchWritableFile(string $path, callable $patcher): void
  - Reads an existing writable tenant file, applies the content transformer, and
    atomically replaces changed content while preserving existing mode and
    ownership metadata.
  - Unsafe, missing, empty, or unchanged files are ignored; failed replacement
    leaves the previous file in place.

---

## Web Stack & System Prep

- pmssConfigureWebStack(): void
  - Stops nginx; disables/stops lighttpd through the supported systemd path; kills lingering `lighttpd` and `php-cgi`.
  - Enables nginx, regenerates all per-user nginx configs from the freshly staged templates without restarting nginx, and hardens `/home` perms.
  - A final nginx config refresh and restart still runs later in `scripts/util/update-step2.php` after app installers finish.

- pmssApplyRuntimeTemplates(): void
  - Installs `rc.local`, systemd `system.conf`, `sshd_config`, and the
    `ssh.service` starvation-resistance drop-in from templates; converges
    `AuthorizedKeysFile` if the template leaves it commented; sets perms and
    ownership; reexecs/reloads systemd as needed and restarts sshd; runs
    rc.local.

- pmssApplyJournaldLimits(?callable $logger=null): void
  - Renders `/etc/systemd/journald.conf.d/pmss-limits.conf` from template;
    sizes caps based on root filesystem; restarts systemd-journald.

- pmssApplyRemoteLogging(?callable $logger=null): void
  - Deploys `/etc/rsyslog.d/50-pmss-remote.conf` from template if
    `/etc/seedbox/config/logging.conf` exists and enables remote logging;
    uses imjournal for systemd compatibility; restarts rsyslog.
    Best-effort only (never fatal); disabled by default.

- pmssEnsureCgroupsConfigured(?callable $logger=null): void → on cgroup v1, ensures `/sys/fs/cgroup` is present in `/etc/fstab` through the managed fstab reader/writer (regular-file guard + backup) and only mounts after a successful new entry; attempts to raise root slice PID limit. On cgroup v2, leaves fstab untouched.

- pmssEnsureSystemdSlices(?callable $logger=null): void → writes user slice override template to `/etc/systemd/system/user-.slice.d/15-pmss.conf` (never vendor paths), resolves the `/home` backing device for optional `IODeviceLatencyTargetSec` rendering from `cgroup.policy.php` on cgroup v2 hosts, optionally installs user manager `LimitNOFILE` drop-in at `/etc/systemd/system/user@.service.d/20-pmss-limits.conf` from `cgroup.policy.php` (`limitNoFileSoft`/`limitNoFileHard`), installs per-user log namespace drop-in at `/etc/systemd/system/user@.service.d/30-pmss-log-namespace.conf` (`LogNamespace=user-%i`), then runs `daemon-reload`.

- pmssConfigureTempMountNoexec(?callable $logger=null): void → when `PMSS_HARDEN_TMP_NOEXEC` is enabled, ensures `/tmp` and `/dev/shm` fstab entries include `noexec,nosuid,nodev`, and remounts when mounted; warns on missing mounts, unreadable fstab, or unsafe override paths.
- pmssConfigureTempTmpfsMount(?callable $logger=null): void → when `PMSS_HARDEN_TMP_TMPFS` is enabled, ensures `/tmp` has a tmpfs entry in `/etc/fstab` with `noexec,nosuid,nodev,size=<size>`, updates options if already present, and mounts/remounts `/tmp` when needed. Size defaults to `2G` and can be overridden via `PMSS_TMPFS_TMP_SIZE`; unsafe override paths are rejected through the same fail-soft warning path.

- pmssResetCorePermissions(): void → `chmod -R 755 /etc/seedbox` and `chmod -R 750 /scripts`.

- pmssEnsureLocaleBaseline(): void → ensures `en_US.UTF-8` base locale (including `LC_TIME`), sets system timezone to `Europe/Helsinki`, and calls `Motd::motdGenerate()`.

- pmssConfigureTempDiskBackedMount(?callable $logger=null, ?int $distroVersion=null): void → on Debian 13+ masks `tmp.mount` so `/tmp` stays disk-backed by default; earlier releases are left unchanged and explicit PMSS tmpfs hardening remains opt-in.
- pmssNetconsoleConfigure(callable $logger, ?callable $runner=null): void → when `/etc/seedbox/config/netconsole` contains a valid kernel `netconsole=` spec and the target MAC is reachable, writes `/etc/modprobe.d/netconsole.conf`, enables module autoload, and reloads `netconsole`.
- pmssEnsureBootTuning(?callable $logger=null): void → installs `/usr/local/sbin/pmss-boot-tuning.sh` and `/etc/systemd/system/pmss-boot-tuning.service` from templates, replaces `%%PMSS_BOOT_TUNING_SCRIPT%%`, enables/starts the unit, records `/etc/seedbox/config/hardware.json` when the boot script runs, and skips systemd actions in test/dry-run or when systemd is unavailable.
- pmssEnsureBootDefaults(?callable $logger=null, ?string $fstabPath=null, ?string $grubPath=null, ?string $grubOption=null, ?array $extraGrubOptions=null, ?array $extraGrubSettings=null): void → enforces `/proc` `hidepid=2`, ensures required grub cmdline options, and optionally pins explicit grub settings such as serial-console directives.
- pmssVerifyDistUpgradeBootReadiness(?string $mdstatPath=null, ?string $grubConfigPath=null, ?string $mdadmConfigPath=null, ?string $initramfsMdadmPath=null): void → non-fatal post-upgrade boot checks (RAID degradation markers, grub config presence/size, mdadm ARRAY entries, BOOT_DEGRADED flag) with warning logs for operator follow-up before reboot.

- pmssConfigureRootShellDefaults(?callable $logger=null): void → ensures `/root/.bashrc` contains `alias ls=...` and `PATH=$PATH:/scripts`.

- pmssStopDisableMaskSeedboxSystemServices(): void → stops/disables/masks system-wide daemons that must never run on seedbox hosts (e.g. lighttpd, deluged/deluge-web, transmission-daemon, redis-server, memcached, rpcbind/nfs-kernel-server, smbd, exim4, docker.service), then purges exim4 packages and stale exim spool files. Fail-soft; safe when units are missing.
- pmssEnsureSystemdServicesGuardBootUnit(): void → installs/enables `pmss-systemd-services-guard.service` so the systemd hardening guard runs early at boot (before basic.target).

- pmssInstallMediaInfo(string $lsbCodename, ?callable $logger=null): void → installs mediainfo with retry; logs version or warns on failure.

Bootstrap helpers from install-time env (Phase 2):
- pmssEnvFlagEnabled(string $name): bool → considers '', '0', 'false', 'no' as false.
- pmssApplyHostnameConfig(?callable $logger=null): void → honors `PMSS_SKIP_HOSTNAME`; applies `PMSS_HOSTNAME` via hostnamectl or hostname; writes `/etc/hostname`.
- pmssConfigureQuotaMount(?callable $logger=null): void → honors `PMSS_SKIP_QUOTA`; updates fstab quota options for `PMSS_QUOTA_MOUNT` (default `/home`) and remounts.
- pmssEnsureQuotaOptions(string $mountPoint, array $requiredOptions=null, ?callable $logger=null): void → ensures quota options present on the `/etc/fstab` line; writes backup + updated file.

---

## Networking

- pmssEnsureNetworkTemplate(?callable $logger=null): void → writes default PHP array config to `/etc/seedbox/config/network` when missing (eth0, speed=1000, throttle defaults).
- pmssApplyNetworkConfig(): void → runs `/scripts/util/setupNetwork.php` to render/apply FireQOS config.

- detectPrimaryInterface(): string → from config or `ip route` default iface (fallback `eth0`).
- getLinkSpeed(string $iface): int → from config or `ethtool <iface>` (fallback 1000 Mbps).

FireQOS helpers:
- networkLoadConfig()/networkLoadLocalnets(): array → load active net config and localnets, with defaults and env overrides (`PMSS_NETWORK_CONFIG`, `PMSS_LOCALNET_FILE`).
- networkBuildFireqosConfig(array $networkConfig, array $users, array $localnets): string → render FireQOS template with per-user classes and localnets matches; optional per-user cap via `/var/run/pmss/trafficLimits/<user>.enabled`.
- networkApplyFireqos(string $config): void → writes `/etc/seedbox/config/fireqos.conf` and starts FireQOS; logs to `/var/log/pmss/fireqos.log`.

iptables helpers:
- iptablesRun(string $rule): void → run single rule; logs error to `/var/log/pmss/iptables.log` on failure.
- iptablesParseMonitoring(string $raw): array → returns list of rule strings, stripping `/sbin/iptables` prefixes and ignoring flushes.
- iptablesApplyAtomically(array $filterCommands, array $natCommands): bool → builds an `iptables-restore` script and applies in one shot.
- iptablesApplyFallback(array $filterCommands, array $natCommands, array $replacements): void → applies rules one-by-one as a fallback.

---

## Resource Statistics

- resourceStatistics::getData($user, $timePeriod=10080): string
  - Inputs: managed username and requested resource-log tail line count.
  - Safety: validates username/resource path before shelling, clamps line count
    to `1..10080`, resolves `tail` through runtime command helpers, and returns
    `''` on invalid paths, missing commands, non-zero tail exit, or timeout.

- pmssResourceResultsWindowMetrics(array $results, string $window): ?array
  - Extracts one accumulator window for resource snapshot rows.
  - Safety: requires `memory`, `tasks`, and all raw metrics to be present and
    numeric; malformed shapes return `null` so the caller can keep the existing
    fail-soft missing-resource path.
- pmssStatsStatusModelBuild(?string $uid, ?bool $dockerEnabledPolicy, ?callable $runner=null, array $overrides=[]): array
  - Builds the customer-panel VPN/app/Docker status model; WireGuard and
    OpenVPN use customer-readable interface presence (`wg0`, `tun0`) instead of
    privileged systemd queries. `network_interfaces_root` overrides the sysfs
    root for hermetic tests.

---

## Package State Helpers

`update-step2.php` relies on dpkg baseline selections as the sole package
authority. The former per-app package queue has been removed; package helpers are
read-only probes used by baseline sanitization and source-build guards.

- pmssPackageStatus(string $package): string → dpkg status string or `''`.
- pmssPackageAvailable(string $package): bool → checks cached `apt-cache pkgnames` set (fast path), falls back to `apt-cache policy` (cached).

---

## Version Helper - `scripts/lib/version.php`

- getPmssVersion(string $versionFile='/etc/seedbox/config/version'): string -> trimmed file contents or `'unknown'`.

---

## OS-Release & Skeleton Utilities - `scripts/lib/update.php`

- pmssSkeletonBase(): string → `PMSS_SKEL_DIR` or `/etc/skel`.
- updateUserFile(string $file, string $user): void → copies a skeleton file into `PMSS_HOME_DIR` (default `/home`) under `/<user>/<file>` when missing or checksum differs; ensures parent directories exist, writes via temp-file + rename, sets mode 755 and `chown user:user`.
- copyToUserSpace(string $sourceFile, string $targetFile, string $user): void → atomic copy via temp + rename, chmod 755, chown/chgrp user.
- updateRutorrentConfig(string $username, int $scgiPort): void → renders ruTorrent templates with user paths and writes `conf/{config.php,access.ini}`.
- getOsReleaseData(): array → cached `parse_ini_file` of `PMSS_OS_RELEASE_PATH` or `/etc/os-release`.
- getDistroName(): string, getDistroVersion(): string, getDistroCodename(): string → wrappers around `getOsReleaseData()`.
- pmssResetOsReleaseCache(): void → clears cached os-release data for current path.

---

## rTorrent Configuration – `scripts/lib/rtorrentConfig.php`

Class `rtorrentConfig`
- __construct(array $resourceConfig=[], ?string $template=null)
  - Loads default resource JSON (`/etc/seedbox/config/rtorrent.resources.json`) and template (`/etc/seedbox/config/template.rtorrent.rc`) when not provided; validates and fills defaults via `_checkResourceConfig()`.

- createConfig(array $config): array
  - Inputs: requires `'ram'` MiB. Optional: `'scgiPort'`, `'dhtPort'`, `'listenPort'`, `'dht'` ('no|yes|auto'), `'pex'` ('no|yes|auto').
  - Behavior: Derives peers and upload slots based on `ramBlock` scaling; `pieces.memory.max` uses headroom formula `max(170, ram - clamp(0.25*ram, 250, 1000))`; substitutes placeholders in template; appends `ipv4_filter.load = /etc/seedbox/config/localnet, preferred` if localnet exists.
  - Output: `['configFile' => string, 'config' => array]` ready to write.
  - Errors: throws on missing `'ram'` or invalid input.

- writeConfig(string $user, string $config): bool
  - Writes `/home/<user>/.rtorrent.rc` (touches 0644 when missing); returns true on success.

- idempotentConfig(string $user, string $config): ?bool
  - Reads current file and compares; writes only when content differs; returns write result or null when identical.

- readUserConfig(string $user): array|false → wrapper for readConfig on `/home/<user>/.rtorrent.rc`.
- readConfig(string $file): array|false → parses key=value pairs from `~/.rtorrent.rc` file (skipping comments and blanks).
- _configPortPrivate(string $type, int $rangeStart=2000, int $rangeEnd=65000): int
  - Reserves a random port using files under `/var/lib/pmss/ports/<type>/<port>`; idempotent by presence.
- loadDefaultResourceConfig(): array → reads and JSON-decodes resources file; throws on failure.
- loadDefaultTemplate(): string → reads template; throws on failure.
- _checkResourceConfig(): void → fills defaults for missing fields (ramBlock, peers, uploadSlots).

---

## CLI Option Parser – `scripts/lib/cli/optionParser.php`

- pmssParseCliTokens(array $argv): array
  - Output: `['options' => array, 'arguments' => array]` supporting GNU long options and short flags with or without values.
- pmssCliOption(array $parsed, string $long, ?string $short=null, $default=null) and typed accessors (`pmssCliOptionPresent`, `pmssCliOptionString`, `pmssCliOptionInt`)
  - Behavior: return option presence/value by long or short alias when present, else default.

---

## WireGuard Provisioning – `scripts/lib/update/apps/wireguard.php`

Functions documented inline above. Entrypoint (guarded by `PMSS_WIREGUARD_NO_ENTRYPOINT`) creates config dir, ensures keys, renders config and README, bootstraps one-device client profiles in `~/wireguard.txt` when users have no registered public key yet, repairs missing public-key registrations for existing PMSS-managed single-device profiles, seeds placeholder client configs for the remaining users, and enables `wg-quick@wg0` unless disabled.

Environment overrides: `PMSS_WG_CONFIG_DIR`, `PMSS_WG_HOME_BASE`, `PMSS_WG_USER_LIST`, `PMSS_WG_PRIVATE_KEY`, `PMSS_WG_PUBLIC_KEY`, `PMSS_WG_CLIENT_PRIVATE_KEY`, `PMSS_WG_CLIENT_PUBLIC_KEY`, `PMSS_WG_EXTERNAL_IP`, `PMSS_WG_INTERFACE_IP`, `PMSS_WG_DNS_IP`, `PMSS_WG_SKIP_SERVICE`.

Safety: per-user public-key reads revalidate the username and ignore missing,
non-regular, symlinked, unreadable, or invalid-key registry files.

---

## Application Installers (Contracts)

These scripts are primarily imperative; treat them as idempotent installers guarded by presence/version checks.

- btsync.php
  - Ensures BTSync 1.4/2.2 binaries in `/usr/bin/`; symlinks `/usr/bin/btsync`→2.2; installs/updates Resilio Sync to pinned version.

- servarr.php
  - Provides the shared ARR updater for Lidarr, Prowlarr, Radarr, Readarr, and Sonarr using one canonical app list in `arr.php`; update-step2 excludes this entrypoint from the default app autoloader so system updates do not block on account-scoped media-stack maintenance.
  - Contract (ADR 0034): install is not execution. `arr.php` downloads, extracts and activates releases and persists `install_path/version.txt`; it MUST NOT execute an installed binary, and unknown-version simply reinstalls. Symlinked version markers are ignored so a foreign-owned install tree cannot redirect root.

- arrRootConfigHardening.php (`scripts/lib/update/`)
  - `pmssEnsureArrRootConfigHardening()` converges `/root/.config/<App>/config.xml` to `BindAddress=127.0.0.1`, `AuthenticationMethod=Forms`, `AuthenticationRequired=Enabled` and a random `ApiKey`. Seeds where an ARR app is installed, repairs where a past accidental root launch left first-run defaults, never rewrites an unrecognised payload, and refuses symlinked paths. Root has no legitimate ARR instance; this only limits the blast radius of an accidental launch.

- deluge.php
  - Debian 10: installs dependencies via pip and builds Deluge 2.0.5 from source.
  - Newer: `apt-get install -y deluged deluge-web`, disables service.

- docker.php
  - Installs rootless Docker prerequisites; adds Docker APT repo/key; installs Docker packages and disables the system service/socket; enables unprivileged user namespace; fetches newer `slirp4netns` on Debian < 12.

- filebot.php
  - Ensures `/usr/bin/filebot` at pinned version; downloads and installs deb when missing.

- openvpn.php
  - Seeds EasyRSA into `/etc/openvpn/easy-rsa`, writes vars, builds server certs/DH, renews an expired or soon-expiring server leaf under the existing CA after a PKI backup, renders server config from template, restarts service; writes client `.ovpn` and `ca.crt` to `/home`, packs `openvpn-config.tgz` into skeleton and updates user homes.

- rclone.php
  - Logic: Picks the pinned version by default, optionally fetches the latest release when requested, replaces `/usr/bin/rclone` when version mismatch, installs from the official zip.
  - Env: `PMSS_RCLONE_FETCH_LATEST=1` to request latest.

- wireguard.php
  - See WireGuard section above.

Other app installers (mono.php, syncthing.php, vnstat.php, iprange.php, pyload.php) follow the same pattern: install/refresh packages or binaries as needed and avoid breaking existing setups. Consult the scripts when extending.

---

## Utilities (Script Contracts)

Automation often invokes these utilities; below are expected inputs and effects.

- scripts/util/configureLighttpd.php [<user>]
  - Behavior: Renders per-user lighttpd vhost/fastcgi config from templates. With a username, targets only that user; otherwise (no args) refreshes all.
  - 503 pages: rendered lighttpd configs use the customer-tree `~/www/error-503.html` static page so app proxies that are enabled but still starting return a styled retry page instead of lighttpd's raw default.
  - PMSS-managed proxy fragments: always refreshes the qBittorrent and rclone proxies; when `/home/<user>/.invidiousPort` contains a valid port, also publishes `/public-<user>/invidious/` and `/user-<user>/apps/invidious/` through the user's lighttpd.
  - PMSS-managed lighttpd proxy fragments enable `proxy.header` upgrade forwarding so WebSocket-capable apps can complete HTTP upgrade handshakes through the per-user proxy.
  - Side-effects: Writes files under `/home/<user>/.lighttpd/` and lighttpd config directories.

- scripts/cron/checkLighttpdInstances.php [<user>]
  - Behavior: Keeps per-user `lighttpd` and `php-cgi` healthy, regenerates missing configs, and refreshes per-user 502 pages while the web stack is unhealthy.
  - Behavior: Restarts the per-user lighttpd/php-cgi stack when the rendered config, `~/.lighttpd/custom`, or `~/.lighttpd/custom.d/*.conf` fragments are newer than the running lighttpd process.
  - Quota diagnosis: when quota is exceeded, compares the charged-over-soft amount with deleted blocks held by account-owned processes on the home device; an uncertain `/proc` or `stat()` scan keeps the conservative quota reason. The `quota_descriptors` page never exposes descriptor paths and states that further deletion will not release held blocks.
  - Per-user toggle: when user config `lighttpdEnabled` is false, kills any running `lighttpd`/`php-cgi` for that user, removes the watchdog error page, and skips restart. Default remains true.

- scripts/cron/checkRtorrent.php
  - Behavior: Keeps per-user rTorrent/executor processes healthy and recovers missing `.rtorrent.rc` files from canonical templates when enough user config data exists.
  - Side-effects: Publishes `/root/changedConfigs` when a user's `.rtorrent.rc` is not owned by root; clears the stale report when no drift remains; write/remove failures are logged instead of hidden.

- scripts/util/createNginxConfig.php
  - Behavior: Regenerates nginx global and per-user config from templates; adds per-user subdomain vhosts under `/etc/nginx/conf.d/pmss-user-*.conf` when `/etc/hostname` is a valid FQDN.
  - Public proxy contract: `/public-<user>/` forwards the original scheme via `X-Forwarded-Proto` and only restores generic lighttpd redirects back under `/public-<user>/`; per-app media-stack Location rewriting stays in the user's `~/.lighttpd/custom.d/media-stack.conf` fragment, while Set-Cookie Path rewriting stays in nginx `proxy_cookie_path` rules because lighttpd `map-urlpath` does not rewrite `Set-Cookie`.
  - Subdomains: `USERNAME.<host>` proxies to `/public-<user>/`; SHA256 host (`sha256(username.billingServiceId.hostname).<host>`) proxies to `/user-<user>/` with HTTP→HTTPS redirect; hash vhost reads `.billingServiceId` with `.billingId` legacy fallback and is skipped when neither file has a valid value.
  - 502 pages: private user proxies route upstream failures to `/error-502-<user>.html`, which falls back to the shared `/error-502.html`; the lighttpd watchdog refreshes those per-user files under `/var/www` while the stack is unhealthy.
  - WebDAV: the external URL format is `https://<server-fqdn>/webdav-<user>/`; the path is never bare `/webdav`.
  - WebDAV: `/webdav-<user>/` is protected by per-user htpasswd at `/home/<user>/.lighttpd/.htpasswd`.
  - WebDAV: nginx WebDAV locations include `/etc/nginx/webdav_proxy_params`, which preserves forwarded auth/origin headers, disables request buffering, and uses 600s body/proxy timeouts for large uploads.
  - WebDAV: make `~/www` writable by creating `/home/<user>/.lighttpd/webdav.www-writable` (default is read-only except `~/www/public`).
  - Side-effects: Writes under `/etc/nginx/` and reloads/restarts nginx via callers.

- scripts/util/checkUserHtpasswd.php
  - Behavior: Synchronizes per-user htpasswd files with legacy global htpasswd; creates missing files.

- scripts/util/setupPermissions.php
  - Behavior: Normalizes perms on `/etc/skel` (non-destructive to content); ensures expected modes/ownership.

- scripts/util/setupRootCron.php
  - Behavior: Installs/updates root cron entries from `/etc/seedbox/config/root.cron`.
  - Behavior: Maintains the PMSS-owned `cron.service` drop-in with `Restart=always` and an aggregate `TasksMax=8192` cap so cron-spawned user jobs cannot consume host-wide pid capacity.

- scripts/cron/checkGui.php
  - Behavior: Runs outside the panel request path and repairs missing, empty, or
    undersized `www/index.php` and `www/scriptsInc.php` files from the skeleton.
  - Safety: Revalidates user paths and skeleton sources, and replaces repaired
    files atomically so a failed write does not truncate the prior copy.

- scripts/util/setupNetwork.php
  - Behavior: Renders and applies FireQOS from `template.fireqos` using `networkLoadConfig()` and `networkLoadLocalnets()`; writes config under `/etc/seedbox/config` and applies rules.

- scripts/util/netconsoleConfigure.php
  - Behavior: Applies optional kernel netconsole logging from `/etc/seedbox/config/netconsole` after verifying the target MAC is reachable on the configured link.

- scripts/util/ftpConfig.php
  - Behavior: Applies FTP server configuration from templates and restarts service.

- scripts/util/userPermissions.php <user>
  - Behavior: Fixes ownership/permissions under `/home/<user>` according to policy (chmod/chown); safe to re-run.

- scripts/util/userConfig.php <user> <ramMiB> <quotaGiB>
  - Behavior: Applies quota settings and rTorrent/ruTorrent configs; seeds dotfiles; safe to re-run.
  - qBittorrent bootstrap: seeds `~/.config/qBittorrent/qBittorrent.conf` from `/etc/seedbox/config/template.qbittorrent.conf`, pinning shared-host defaults such as POSIX disk I/O, 128 MiB disk cache, 4 async I/O threads, and moderate connection/upload caps for new accounts; later maintenance refreshes that PMSS-managed subset without replacing user-owned settings.
  - Optional flags: `--upload-throttle-kib=<KiB>` updates torrent upload throttle; `--welcome-message=<HTML>` sets/clears the per-user welcome banner override file at `~/.config/welcome-message.html` (empty value clears).
  - IOPS downgrade semantics: explicit `IOReadIOPS` / `IOWriteIOPS` values of `0` are forwarded to `userConfigCgroup.php` as `/home:max`, which resolves `/home` to a safe block device and applies `IOReadIOPSMax=<device> infinity` / `IOWriteIOPSMax=<device> infinity`.
  - Help: `-h` / `--help` prints structured usage and exits successfully.
  - Welcome-only mode: `scripts/util/userConfig.php <user> --welcome-message=<HTML>` updates only the welcome banner override file and exits without running service/quota orchestration.
  - Docker floor: when `ramMiB < 245`, persists `dockerEnabled=false` for the user. Storage Box product payloads also default `dockerEnabled=false` unless explicitly overridden.
  - rTorrent restart guard: when `~/session/rtorrent.lock` exists, parses only a positive lock-file PID greater than 1 and sends `kill -9` only when `/proc/<pid>` still belongs to the target UID and has an `rtorrent*` command name.

- scripts/util/userConfigCgroup.php USERNAME [options]
  - Behavior: Plans and optionally applies systemd/cgroup slice properties for one existing user.
  - Apply errors: when `--apply` runs a planned `systemctl` or `io.cost`
    write and any step returns non-zero, the script attempts the remaining
    planned writes for visibility, then exits non-zero.
  - Safety: `--wipe` must be isolated from resource/IO/default modifiers, and
    explicit io.cost major:minor tokens must match the resolved target device.

- scripts/productConfig.php <product> --welcome-message=<HTML>
  - Behavior: Sets/clears product-level welcome banner templates in `/etc/seedbox/config/welcomeMessages.json`.

- scripts/util/portManager.php assign <user> lighttpd
  - Behavior: Assigns a unique port for the user’s lighttpd; persists reservation.
  - Safety: rejects invalid usernames/service names before building reservation paths; `PMSS_PORT_MANAGER_DIR` may override the reservation directory for hermetic tests.

- scripts/util/systemTest.php
  - Behavior: Read-only probe of system readiness (binary versions, config presence);
    intended post-provision.

- scripts/util/supportCommand.php <message>
  - Behavior: Saves a read-only support snapshot under `/home/<user>/.support/requests/` and submits the same snapshot to the configured support inbox.
  - Inputs: Reads `/etc/seedbox/config/support.php`, `/etc/seedbox/config/version`, optional `/home/<user>/.billingServiceId` with `.billingId` legacy fallback, and optional `/home/<user>/.billingClientId`.
  - Flags and overrides: `-h`/`--help` prints usage and exits successfully; `PMSS_SUPPORT_CONFIG_PATH` overrides the support config file path before the `PMSS_CONFIG_DIR` default is consulted.
  - Delivery: Prefers a local `sendmail` binary when present, otherwise attempts direct MX SMTP delivery to the configured support inbox.

- scripts/util/performanceBaselineCollect.sh [--output <file>]
  - Behavior: Collects a lightweight JSON baseline (timestamp/kernel, selected sysctl keys, TCP retransmit counter, optional vmstat/iostat samples) for before/after tuning comparisons.

- scripts/util/update-step2.php
  - Behavior: Legacy consolidated phase-2 script (superseded by modular `lib/update/*`), retained for compatibility. Do not extend unless migrating behavior into modules.
  - Preflight: checks disk space on `/` and `/home` (fatal if <3 GiB), dpkg lock availability, APT cache writability, and basic network reachability; logs `preflight_ok` or `preflight_error` JSON events.
  - Respects `PMSS_UPDATE_LOCK_ENV`; when absent, acquires the global update lock (`PMSS_UPDATE_LOCK_FILE`) with the same bounded non-blocking busy-skip behaviour as `scripts/update.php`.
  - Step classes: post-package orchestration can be classified as `must_succeed`, `soft_fail`, or `skip_if_missing`; `must_succeed` failures after package phase completion emit `step_failed` (`severity=error`) and abort phase 2. Unknown class strings fail closed as error-severity failures instead of silently downgrading policy.
  - Logrotate convergence: calls `pmssLogrotatePoliciesInstall()` to install and verify PMSS-managed policies for `/etc/logrotate.d/pmss-update` and `/etc/logrotate.d/rsyslog`; the rsyslog policy keeps OS logs daily with `maxsize 500M`.

## Customer Bonus Display – `etc/skel/www/scriptsInc.php`

- `pmssCustomerBonusDisplayStateRead(string $userBonusPath='../.userBonus', string $bonusQuotaPath='../.bonusQuota'): array`
  - Output: `['unit'=>'percent|gib', 'value'=>int]` for the customer-facing bonus banner.
  - Read order: an unsigned integer `.userBonus` value is authoritative and represents a percentage; otherwise a positive `.bonusQuota` value represents GiB.
  - Missing, zero, malformed, or unsafe values fall back to `['unit'=>'percent', 'value'=>0]`; the helper never derives a percentage from quota bytes.
- `pmssCustomerBonusDisplayTextBuild(array $state): string`
  - Formats percent states as `BONUS: N%` and current `.bonusQuota` fallback states as `BONUS: +N GiB` so the two units cannot be confused.

---

## User Management (CLI)

- scripts/addUser.php USERNAME PASSWORD RAM_MiB QUOTA_GiB [trafficLimitGB]
  - Alternate form: `scripts/addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=QUOTA_GiB [resource options]`
  - Behavior: Creates Unix user with `/etc/skel`, sets password or generates one,
    sets expiry far future, ensures bash shell, records to per-user config store (`/etc/seedbox/config/users/<user>.json`),
    assigns lighttpd port, applies config (`userConfig.php`), configures per-user
    lighttpd, regenerates nginx, starts rTorrent and lighttpd, refreshes network,
    queues permission fix; optional traffic limit persists to runtime traffic files (user config store always writes `trafficLimit=0`).
  - Resource passthrough: Supports optional `--traffic-limit-gb`, `--traffic-cap-mbit`, `--upload-throttle-kib`, `--cpu-weight`, `--io-weight`, `--io-read-bw`, `--io-write-bw`, `--io-read-iops`, `--io-write-iops`, and `--cpu-quota-percent` flags while preserving the legacy positional form.
  - Help: `-h` / `--help` prints structured usage and exits successfully.
  - Parse failures: missing or invalid CLI arguments print usage/error text to stderr and exit non-zero.
  - Guardrails: Per-user lock file prevents concurrent addUser runs for the same username.
  - Guardrails: Rejects reserved system/service usernames to avoid future account collisions.
  - Recovery gate: When `/etc/passwd` already contains the username, addUser may self-heal only if the latest `###ADDUSER_JSON` summary for that user is a recent internal `FAIL` and both per-user `rtorrent` and `lighttpd` are inactive; the stale account is cleaned before retry. All other existing-user cases still abort.
  - Recovery gate: When the current run fails after `useradd` but before `userConfig.php` completes, addUser reuses the stale-account cleanup path on shutdown so `/etc/passwd`, `/home/<user>`, ports, lock files, and the per-user config store do not linger half-created.
  - Fail-fast: Aborts on existing user, orphaned home directory, failed cleanup of stale failed-provision state, failed `useradd`, failed `changePw.php`, or failed `userConfig.php` to avoid unsafe overwrites.
  - Logs: `/var/log/pmss/addUser.log`, shared user logs (`/var/log/pmss/users.log`,
    `/var/log/pmss/users.jsonl`), and per-user logs under `/var/log/pmss/users/<user>.log`.
    Emits `###ADDUSER:SUCCESS|FAIL|ERROR` summary markers for grep plus
    `###ADDUSER_JSON:{...}` with explicit `success`/`exit_code` fields for automation.

- scripts/changePw.php [--jsonl] USERNAME [PASSWORD]
  - Behavior: Sets Unix password (generated if omitted) and per-user htpasswd; prints the password.
    With `--jsonl`, suppresses human status lines and emits one JSON object with the new credential, sync return codes, and `qbittorrent_updated` (`true` when the existing qBittorrent config was updated, `false` when no qBittorrent config was present, `null` before that sync step is reached).

- scripts/terminateUser.php [--dry-run] [--confirm] USERNAME
  - Behavior: Validates the managed user and exact `/home/<user>` path, removes account-owned runtime/config state, then removes `/home/<user>` and any `/home/backup-<user>` left by `recreateUser.php`. It never sweeps `backup-*` prefixes.
  - Removal: Removes ordinary contents first, clears immutable attributes only from the remaining residue, and retries removal — so the recursive attribute walk never traverses a full account.
  - Dry-run: Logs planned removal work without deleting those paths.
  - Safety: Direct cleanup helpers reject NUL-containing paths and skip malformed/out-of-range rTorrent port values before unlinking reservation files.

- scripts/recreateUser.php USERNAME RAM_MiB QUOTA_GiB
  - Behavior: Kills user processes; if `/home/<user>` exists, moves to `/home/backup-<user>`;
    recreates from `/etc/skel`, ensures dirs (`data`, `session`, `.lighttpd`);
    re-applies configs (`userConfig.php`, lighttpd/nginx, permissions);
    restores `data`, `session`, and `.htpasswd` when available; validates ownership.

---

## Notes for Agentic Coding
- Prefer high-level helpers (`runStep`, `pmssRefreshRepositories`, `pmssApplyDpkgSelections`, `pmssUpdateUserEnvironment`) to keep logs/profile consistent.
- Honor environment overrides in tests (`PMSS_*` flags) to avoid mutating the real system.
- Treat vendor/third-party code as read-only unless a narrower rule explicitly allows the change. First-party `etc/skel/www` files are editable normally.
- Keep destructive actions guarded and idempotent; reuse existing conventions.
