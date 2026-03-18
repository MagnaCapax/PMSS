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
  - Else runs `/scripts/util/update-step2.php`, logs start/end + duration, and on non-zero exit makes a best-effort `scripts/util/setupSkelPermissions.php` pass before `fatal`.

- runAutoremove(): void → `apt-get autoremove -y` with non-interactive dpkg opts; `fatal(EXIT_COPY)` on failure.

- maybeRunDistUpgrade(bool|string $distUpgrade): void
  - If enabled, runs `pmssRunDistUpgrade(<max>)` from `scripts/lib/update/distUpgrade.php` and logs start/end events.
  - Restores root cron unless restoration is deferred to update-step2.

- bootstrapMain(array $argv): void
  - Orchestrator: ensure root → parse/normalize/parse spec → workdir fetch → stage →
    record version → cleanup → self-update handoff → dist-upgrade (optional) →
    run phase 2 or scripts-only path → log completion with duration.
- Update lock: uses `PMSS_UPDATE_LOCK_FILE=/var/run/pmss/update.lock` with an
  exclusive flock; sets `PMSS_UPDATE_LOCK_ENV=1` when held so child re-exec
  skips re-acquiring. Emits JSON events `update_lock_wait`, `update_lock_acquired`,
  and `update_lock_released`. Events include `pmss_correlation_id` once initialized.

Environment flags consumed: `PMSS_CORRELATION_ID` (generated early when missing; inherited by phase 2 and child commands).

Logs: `/var/log/pmss/update.php.log` (stdout mirror) and JSON `/var/log/pmss-update.jsonl` (`pmss_correlation_id` included on JSON events).

---

## Runtime Execution & Profiling

- runCommand(string $cmd, bool $verbose=false, ?callable $logger=null, bool $inheritTty=false): int
  - Spawns `/bin/bash -lc <cmd>` via `proc_open`, streams stdout/stderr, returns rc.
  - Exposes `$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']` with `stdout`/`stderr`.
  - Timeout: defaults to 1200s; apt/dpkg commands are floored to 1200s. `PMSS_COMMAND_TIMEOUT` overrides but cannot reduce apt/dpkg below 1200s.
  - When `$inheritTty=true` and stdin/stdout/stderr are TTYs, inherits the terminal for interactive prompts; output capture is disabled (`stdout`/`stderr` set to empty strings).
  - On non-zero rc, logs warning with 300-char stderr excerpt.

- runStep(string $description, string $command): int
  - Honors `PMSS_DRY_RUN=1` (rc=0, status=SKIP); logs `[OK|ERR|SKIP <secs> rc=<n>] ...`.
  - Records profile entry with duration, rc, and 300-char stdout/stderr excerpts.

- runUserStep(string $user, string $description, string $command): int
  - Same as `runStep` but prefixes description with `[user:<name>]`.

- aptCmd(string $args): string
  - Returns apt-get command prefix with non-interactive dpkg options.

- pmssInitProfileStore(): void → ensures `$GLOBALS['PMSS_PROFILE']` exists.
- pmssRecordProfile(array $entry): void → appends entry and emits JSON `step` event.
- pmssProfileSummary(): void → logs status counts and top 5 durations; writes full JSON to `PMSS_PROFILE_OUTPUT` or `(<PMSS_JSON_LOG>.profile.json)`.

---

## Logging & JSON Events

- pmssLogDir(): string → `PMSS_LOG_DIR` or `/var/log/pmss`.
- pmssRuntimeDir(): string → `PMSS_RUNTIME_DIR` or `/var/run/pmss`.
- pmssStateDir(): string → `PMSS_STATE_DIR` or `/var/lib/pmss`.
- pmssJsonLogPath(): string → cached `PMSS_JSON_LOG` or `''`.
- pmssCorrelationId(bool $createIfMissing=true): string → resolves `PMSS_CORRELATION_ID`; generates `<UTC timestamp>-<host>-<hex>` when missing.
- pmssLogJson(array $payload): void → appends JSONL with added `ts` and `pmss_correlation_id` if path configured.
- logMessage(string $message, array $context=[]): void → writes to `PMSS_LOG_FILE` (default `pmssLogDir()/update.log`), mirrors to stdout, and emits a JSON `log` event when JSON logging is configured.
- logmsg(string $message): void → legacy updater entry point; once `scripts/lib/update.php` bootstraps structured logging it forwards to `logMessage()` so older modules keep the shared logger contract.
- pmssSelectLogger(?callable $logger=null): callable → returns the given logger or `logMessage`.
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
  - Picks selection file (`selections-debian<version>.txt`, fallback to newest available baseline, then generic `selections.txt`).
  - `apt-get update`, `apt-cache dumpavail | dpkg --merge-avail`.
  - Sanitizes lines to `pkg<TAB>state`, ignoring malformed entries; uses temp file.
  - `dpkg --set-selections < file` then `apt-get dselect-upgrade -y`; retries with `--fix-broken`.
  - Returns true on successful apply+install; logs warnings.

- pmssMigrateLegacyLocalnet(): void → move `/etc/seedbox/localnet` to `/etc/seedbox/config/localnet` once.

---

## System Preparation

- pmssEnsureLegacySysctlBaseline(?callable $logger=null, ?string $targetOverride=null, bool $reload=true, ?string $modulesLoadOverride=null): void
  - Writes `/etc/sysctl.d/1-pmss-defaults.conf` (override path) with the legacy baseline.
  - Ensures `/etc/modules-load.d/pmss-bbr.conf` contains `tcp_bbr` (override path).
  - When `$reload=true`, runs `sysctl --system` to apply the baseline.

---

## Distro Detection

- pmssDetectDistro(): array
  - Returns `['name'=>string,'version'=>int,'codename'=>string]`.
  - Prefers `VERSION_CODENAME`; maps codename→version; mismatches log “trusting codename”. Falls back to `lsb_release` or defaults.

- pmssVersionFromCodename(string $codename): int → Debian codename→major mapping; unknown→0.

---

## User Environment Orchestration – `scripts/lib/update/users.php` and submodules

- pmssUpdateAllUsers(string $rutorrentIndexSha): array
  - Enumerates users from `users::listHomeUsers()`, runs per-user maintenance, and returns summary keys: `total`, `processed`, `skipped`.
  - Emits end-of-loop summary log line `Processed N of M users` and JSON event `user_maintenance_summary`.
  - Catches per-user throwables (including permission-step timeouts), logs warning, skips that user, and continues remaining users.

- pmssUpdateUserEnvironment(string $user, string $rutorrentIndexSha=''): void
  - Builds context (`pmssBuildUserContext`), returns early when invalid.
  - Runs handlers in order: HTTP, skeleton, ruTorrent themes, ruTorrent refresh, plugins,
    retracker cleanup, permissions, then linger/systemd/rootless Docker wiring.
    Each handler consumes `['user','home','user_esc','rutorrent_index_sha']`.
- pmssEnsureLingerAndDocker(string $user): void
  - Enables linger + rootless Docker wiring for the user.
  - Skips and attempts `userDocker.php stop` when user config `dockerEnabled` is false (default true, but defaults false for Storage Box product payloads) or the effective RAM floor for Docker is below 245 MiB.

Sub-handlers:
- pmssBuildUserContext(string $user, string $rutorrentIndexSha=''): ?array → validates `/home/<user>` with `.rtorrent.rc`, `data`, and no `www-disabled`; returns context.
- pmssUserConfigureHttp(array $ctx): void → configure lighttpd per-user, ensure php.ini `error_log`, create `.tmp` and `.irssi` (from skel), and `www/recycle` with perms/ownership.
- pmssUserApplySkeletonFiles(array $ctx): void → copies fixed list of skel files and quota plugin files into user tree using `updateUserFile()`; deletes `~/www/phpXplorer`.
- pmssUserUpdateThemes(array $ctx): void → ensures named themes exist under `rutorrent/plugins/theme/themes/` (copied from skel), fixes ownership.
- pmssUserUpgradeRutorrent(array $ctx): void → if user’s ruTorrent index.html SHA != skeleton (and no existing backup), backups to `oldRutorrent-3`, copies fresh from skel, restores config/share, updates config via `updateRutorrentConfig()`, fixes ownership and perms.
- pmssUserEnsurePlugins(array $ctx): void → removes deprecated `cpuload`, ensures `unpack` plugin exists and has proper perms.
- pmssUserMaintainRetracker(array $ctx): void → removes legacy `retrackers.dat`, creates torrents and RSS settings dirs.
- pmssUserRefreshPermissions(array $ctx): void
  - Runs `userPermissions.php` with optional `ionice -c3` wrapper when available.
  - Applies per-user timeout via `PMSS_USER_PERMISSIONS_TIMEOUT` (default 900s) by temporarily setting `PMSS_COMMAND_TIMEOUT` for that command only.
  - Throws `RuntimeException` when permission refresh times out so caller can skip that user and continue the queue.
  - Refreshes `~/.rtorrent.rc.custom` from skel if hash matches legacy list.

---

## Web Stack & System Prep

- pmssConfigureWebStack(int $distroVersion): void
  - Stops nginx; disables/stops lighttpd based on init system; kills lingering `lighttpd` and `php-cgi`.
  - Enables nginx and hardens `/home` perms; final nginx config refresh runs later in `scripts/util/update-step2.php` after app installers finish.

- pmssApplyRuntimeTemplates(): void
  - Installs `rc.local`, systemd `system.conf`, and `sshd_config` from templates;
    sets perms and ownership; reexecs systemd and restarts sshd; runs rc.local.

- pmssApplyJournaldLimits(?callable $logger=null): void
  - Renders `/etc/systemd/journald.conf.d/pmss-limits.conf` from template;
    sizes caps based on root filesystem; restarts systemd-journald.

- pmssApplyRemoteLogging(?callable $logger=null): void
  - Deploys `/etc/rsyslog.d/50-pmss-remote.conf` from template if
    `/etc/seedbox/config/logging.conf` exists and enables remote logging;
    uses imjournal for systemd compatibility; restarts rsyslog.
    Best-effort only (never fatal); disabled by default.

- pmssEnsureAuthorizedKeysDirective(): void → ensures `AuthorizedKeysFile` is not commented in `sshd_config`, backs up previous config, restarts SSH via init.d.

- pmssEnsureCgroupsConfigured(?callable $logger=null): void → appends cgroup mount to `/etc/fstab` if missing, installs `cgroup-bin`, mounts path, attempts to raise root slice PID limit.

- pmssEnsureSystemdSlices(?callable $logger=null): void → writes user slice override template to `/etc/systemd/system/user-.slice.d/15-pmss.conf` (never vendor paths), optionally installs user manager `LimitNOFILE` drop-in at `/etc/systemd/system/user@.service.d/20-pmss-limits.conf` from `cgroup.policy.php` (`limitNoFileSoft`/`limitNoFileHard`), installs per-user log namespace drop-in at `/etc/systemd/system/user@.service.d/30-pmss-log-namespace.conf` (`LogNamespace=user-%i`), then runs `daemon-reload`.

- pmssConfigureTempMountNoexec(?callable $logger=null): void → when `PMSS_HARDEN_TMP_NOEXEC` is enabled, ensures `/tmp` and `/dev/shm` fstab entries include `noexec,nosuid,nodev`, and remounts when mounted; warns on missing mounts or unreadable fstab.
- pmssConfigureTempTmpfsMount(?callable $logger=null): void → when `PMSS_HARDEN_TMP_TMPFS` is enabled, ensures `/tmp` has a tmpfs entry in `/etc/fstab` with `noexec,nosuid,nodev,size=<size>`, updates options if already present, and mounts/remounts `/tmp` when needed. Size defaults to `2G` and can be overridden via `PMSS_TMPFS_TMP_SIZE`.

- pmssResetCorePermissions(): void → `chmod -R 755 /etc/seedbox` and `chmod -R 750 /scripts`.

- pmssEnsureLocaleBaseline(): void → ensures `en_US.UTF-8` base locale (including `LC_TIME`), sets system timezone to `Europe/Helsinki`, and calls `Motd::motdGenerate()`.

- pmssEnsureLegacySysctlBaseline(?callable $logger=null, ?string $targetOverride=null, bool $reload=true): void → writes legacy BFQ/sysctl defaults (default_qdisc, tcp_congestion_control, ip_forward, fs.protected_*, ptrace_scope, kptr_restrict) to `/etc/sysctl.d/1-pmss-defaults.conf` and runs `sysctl --system` unless reload is disabled.
- pmssNetconsoleConfigure(callable $logger, ?callable $runner=null): void → when `/etc/seedbox/config/netconsole` contains a valid kernel `netconsole=` spec and the target MAC is reachable, writes `/etc/modprobe.d/netconsole.conf`, enables module autoload, and reloads `netconsole`.
- pmssEnsureBootTuning(?callable $logger=null): void → installs `/usr/local/sbin/pmss-boot-tuning.sh` and `/etc/systemd/system/pmss-boot-tuning.service` from templates, replaces `%%PMSS_BOOT_TUNING_SCRIPT%%`, enables/starts the unit, records `/etc/seedbox/config/hardware.json` when the boot script runs, and skips systemd actions in test/dry-run or when systemd is unavailable.
- pmssEnsureBootDefaults(?callable $logger=null, ?string $fstabPath=null, ?string $grubPath=null, ?string $grubOption=null, ?array $extraGrubOptions=null, ?array $extraGrubSettings=null): void → enforces `/proc` `hidepid=2`, ensures required grub cmdline options, and optionally pins explicit grub settings such as serial-console directives.
- pmssVerifyDistUpgradeBootReadiness(?string $mdstatPath=null, ?string $grubConfigPath=null, ?string $mdadmConfigPath=null, ?string $initramfsMdadmPath=null): void → non-fatal post-upgrade boot checks (RAID degradation markers, grub config presence/size, mdadm ARRAY entries, BOOT_DEGRADED flag) with warning logs for operator follow-up before reboot.

- pmssConfigureRootShellDefaults(?callable $logger=null): void → ensures `/root/.bashrc` contains `alias ls=...` and `PATH=$PATH:/scripts`.

- pmssStopDisableMaskSeedboxSystemServices(): void → stops/disables/masks system-wide daemons that must never run on seedbox hosts (e.g. lighttpd, deluged/deluge-web, transmission-daemon, redis-server, memcached, rpcbind/nfs-kernel-server, smbd, exim4, docker.service), then purges exim4 packages and stale exim spool files. Fail-soft; safe when units are missing.
- pmssEnsureSystemdServicesGuardBootUnit(): void → installs/enables `pmss-systemd-services-guard.service` so the systemd hardening guard runs early at boot (before basic.target).

- pmssDisableLegacyServices(array $services, int $distroVersion): void → stops/disables global daemons (sysvinit vs systemd handling).

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

## Legacy Package Queue Helpers (not in update-step2 main flow)

`update-step2.php` now relies on dpkg baseline selections as the sole package
authority. The helpers below remain in-tree for compatibility and targeted
maintenance tooling, but are no longer part of the default package phase path.

- pmssQueuePackages(array $packages, ?string $target=null): void → queue package names under `__default__` or suite (e.g., `buster-backports`), deduped.
- pmssPackageQueueBaselineInstallSet(string $baselinePath): array → parse install-state package names from a selections baseline (supports `pkg install` and short-form `pkg` rows).
- pmssReportPackageQueueBaselineDiff(?string $baselinePath=null): array → compares queued packages against the selected baseline; logs queued/installed packages missing from baseline and returns summary buckets.
- pmssFlushPackageQueue(): void → install each queue; split available vs missing with `apt-cache policy`, run `apt-get install` (with `-t <suite>`), retry with `--fix-broken`; run post-install commands; set env counters `PMSS_PACKAGE_INSTALL_WARNINGS|ERRORS`, log JSON event on errors.
- pmssPackageStatus(string $package): string → dpkg status string or `''`.
- pmssPackageAvailable(string $package): bool → checks cached `apt-cache pkgnames` set (fast path), falls back to `apt-cache policy` (cached).
- pmssInstallBestEffort(array $items, string $label=''): void → from each list item (string or list of fallbacks) picks the first available and queues.
- pmssInstallProftpdStack(int $distroVersion): void → queues proftpd stack (+nftables for >=10), unmask unit pre-install, and enqueues a `dpkg --configure` recovery command.

System/app groups:
- `scripts/lib/update/apps/packages.php` → queues the standard utility set (ncurses/python3 family/zip/unzip/irssi/etc.), media/network/backup tooling, and the ZNC package group inline; each group logs the historical v<10 warning and skips only that group, and Debian 10 still queues kernels/firmware from `buster-backports`.

---

## OS-Release & Skeleton Utilities – `scripts/lib/update.php`

- pmssOsReleasePath(): string → `PMSS_OS_RELEASE_PATH` or `/etc/os-release`.
- pmssSkeletonBase()/pmssSkeletonPath(string $relative): string → `PMSS_SKEL_DIR` or `/etc/skel` and joined path.
- updateUserFile(string $file, string $user): void → copies a skeleton file into `PMSS_HOME_DIR` (default `/home`) under `/<user>/<file>` when missing or checksum differs; ensures parent directories exist, writes via temp-file + rename, sets mode 755 and `chown user:user`.
- copyToUserSpace(string $sourceFile, string $targetFile, string $user): void → atomic copy via temp + rename, chmod 755, chown/chgrp user.
- updateRutorrentConfig(string $username, int $scgiPort): void → renders ruTorrent templates with user paths and writes `conf/{config.php,access.ini}`.
- getOsReleaseData(): array → cached `parse_ini_file` of `pmssOsReleasePath()`.
- getDistroName(): string, getDistroVersion(): string, getDistroCodename(): string → wrappers around `getOsReleaseData()`.
- pmssResetOsReleaseCache(): void → clears cached os-release data for current path.
- getPmssVersion(string $versionFile='/etc/seedbox/config/version'): string → trimmed file contents or `'unknown'`.
- generateMotd(): void → renders `/etc/seedbox/config/template.motd` tokens (hostname, IP, CPU/RAM/storage, PMSS version, apt last update, uptime, kernel, net speed, WireGuard/OpenVPN status) to `/etc/motd`.

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
- pmssCliOption(array $parsed, string $long, ?string $short=null, $default=null)
  - Behavior: returns option by long or short alias when present, else default.

---

## WireGuard Provisioning – `scripts/lib/update/apps/wireguard.php`

Functions documented inline above. Entrypoint (guarded by `PMSS_WIREGUARD_NO_ENTRYPOINT`) creates config dir, ensures keys, renders config and README, distributes to user homes, and enables `wg-quick@wg0` unless disabled.

Environment overrides: `PMSS_WG_CONFIG_DIR`, `PMSS_WG_HOME_BASE`, `PMSS_WG_USER_LIST`, `PMSS_WG_PRIVATE_KEY`, `PMSS_WG_PUBLIC_KEY`, `PMSS_WG_EXTERNAL_IP`, `PMSS_WG_INTERFACE_IP`, `PMSS_WG_DNS_IP`, `PMSS_WG_SKIP_SERVICE`.

---

## Application Installers (Contracts)

These scripts are primarily imperative; treat them as idempotent installers guarded by presence/version checks.

- acdcli.php
  - Ensures python3/venv/pip, creates venv at `/opt/acd_cli`, installs `acd_cli` from Git, links CLI into `/usr/local/bin/acd_cli`.
  - Env: honors `PMSS_DRY_RUN` to avoid acting when venv is missing unexpectedly.

- btsync.php
  - Ensures BTSync 1.4/2.2 binaries in `/usr/bin/`; symlinks `/usr/bin/btsync`→2.2; installs/updates Resilio Sync to pinned version.

- deluge.php
  - Debian 10: installs dependencies via pip and builds Deluge 2.0.5 from source.
  - Newer: `apt-get install -y deluged deluge-web`, disables service.

- docker.php
  - Installs rootless Docker prerequisites; adds Docker APT repo/key; installs Docker packages and disables the system service/socket; enables unprivileged user namespace; fetches newer `slirp4netns` on Debian < 12.

- filebot.php
  - Ensures `/usr/bin/filebot` at pinned version; downloads and installs deb when missing.

- openvpn.php
  - Seeds EasyRSA into `/etc/openvpn/easy-rsa`, writes vars, builds server certs/DH, renders server config from template, restarts service; writes client `.ovpn` and `ca.crt` to `/home`, packs `openvpn-config.tgz` into skeleton and updates user homes.

- rclone.php
  - Logic: Picks the pinned version by default, optionally fetches the latest release when requested, replaces `/usr/bin/rclone` when version mismatch, installs from the official zip.
  - Env: `PMSS_RCLONE_FETCH_LATEST=1` to request latest.

- wireguard.php
  - See WireGuard section above.

Other app installers (mono.php, radarr.php, sonarr.php, syncthing.php, vnstat.php, iprange.php, pyload.php) follow the same pattern: install/refresh packages or binaries as needed and avoid breaking existing setups. Consult the scripts when extending.

---

## Utilities (Script Contracts)

Automation often invokes these utilities; below are expected inputs and effects.

- scripts/util/configureLighttpd.php [<user>]
  - Behavior: Renders per-user lighttpd vhost/fastcgi config from templates. With a username, targets only that user; otherwise (no args) refreshes all.
  - Side-effects: Writes files under `/home/<user>/.lighttpd/` and lighttpd config directories.

- scripts/util/createNginxConfig.php
  - Behavior: Regenerates nginx global and per-user config from templates; adds per-user subdomain vhosts under `/etc/nginx/conf.d/pmss-user-*.conf` when `/etc/hostname` is a valid FQDN.
  - Subdomains: `USERNAME.<host>` proxies to `/public-<user>/`; SHA256 host (`sha256(username.billingId.hostname).<host>`) proxies to `/user-<user>/` with HTTP→HTTPS redirect; hash vhost skipped if `.billingId` missing/invalid.
  - WebDAV: `/webdav-<user>/` is protected by per-user htpasswd at `/home/<user>/.lighttpd/.htpasswd`.
  - WebDAV: make `~/www` writable by creating `/home/<user>/.lighttpd/webdav.www-writable` (default is read-only except `~/www/public`).
  - Side-effects: Writes under `/etc/nginx/` and reloads/restarts nginx via callers.

- scripts/util/checkUserHtpasswd.php
  - Behavior: Synchronizes per-user htpasswd files with legacy global htpasswd; creates missing files.

- scripts/util/setupSkelPermissions.php
  - Behavior: Normalizes perms on `/etc/skel` (non-destructive to content); ensures expected modes/ownership.

- scripts/util/setupRootCron.php
  - Behavior: Installs/updates root cron entries from `/etc/seedbox/config/root.cron`.

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
  - Optional flags: `--upload-throttle-kib=<KiB>` updates torrent upload throttle; `--welcome-message=<HTML>` sets/clears per-user welcome banner override (empty value clears).
  - Welcome-only mode: `scripts/util/userConfig.php <user> --welcome-message=<HTML>` updates only the welcome banner field and exits without running service/quota orchestration.
  - Docker floor: when `ramMiB < 245`, persists `dockerEnabled=false` for the user. Storage Box product payloads also default `dockerEnabled=false` unless explicitly overridden.

- scripts/productConfig.php <product> --welcome-message=<HTML>
  - Behavior: Sets/clears product-level welcome banner templates in `/etc/seedbox/config/welcomeMessages.json`.

- scripts/util/portManager.php assign <user> lighttpd
  - Behavior: Assigns a unique port for the user’s lighttpd; persists reservation.

- scripts/util/systemTest.php
  - Behavior: Read-only probe of system readiness (binary versions, config presence);
    intended post-provision.

- scripts/util/performanceBaselineCollect.sh [--output <file>]
  - Behavior: Collects a lightweight JSON baseline (timestamp/kernel, selected sysctl keys, TCP retransmit counter, optional vmstat/iostat samples) for before/after tuning comparisons.

- scripts/util/update-step2.php
  - Behavior: Legacy consolidated phase-2 script (superseded by modular `lib/update/*`), retained for compatibility. Do not extend unless migrating behavior into modules.
  - Preflight: checks disk space on `/` and `/home` (fatal if <3 GiB), dpkg lock availability, APT cache writability, and basic network reachability; logs `preflight_ok` or `preflight_error` JSON events.
  - Respects `PMSS_UPDATE_LOCK_ENV`; when absent, acquires the global update lock (`PMSS_UPDATE_LOCK_FILE`).
  - Step classes: post-package orchestration can be classified as `must_succeed`, `soft_fail`, or `skip_if_missing`; `must_succeed` failures after package phase completion emit `step_failed` (`severity=error`) and abort phase 2.

---

## User Management (CLI)

- scripts/addUser.php USERNAME PASSWORD RAM_MiB QUOTA_GiB [trafficLimitGB]
  - Behavior: Creates Unix user with `/etc/skel`, sets password or generates one,
    sets expiry far future, ensures bash shell, records to per-user config store (`/etc/seedbox/config/users/<user>.json`),
    assigns lighttpd port, applies config (`userConfig.php`), configures per-user
    lighttpd, regenerates nginx, starts rTorrent and lighttpd, refreshes network,
    queues permission fix; optional traffic limit persists to runtime traffic files (user config store always writes `trafficLimit=0`).
  - Guardrails: Per-user lock file prevents concurrent addUser runs for the same username.
  - Guardrails: Rejects reserved system/service usernames to avoid future account collisions.
  - Fail-fast: Aborts on existing user, orphaned home directory, failed `useradd`,
    failed `changePw.php`, or failed `userConfig.php` to avoid partial provisioning.
  - Logs: `/var/log/pmss/addUser.log`, shared user logs (`/var/log/pmss/users.log`,
    `/var/log/pmss/users.jsonl`), and per-user logs under `/var/log/pmss/users/<user>.log`.
    Emits `###ADDUSER:SUCCESS|FAIL|ERROR` summary markers for grep plus
    `###ADDUSER_JSON:{...}` with explicit `success`/`exit_code` fields for automation.

- scripts/changePw.php USERNAME [PASSWORD]
  - Behavior: Sets Unix password (generated if omitted) and per-user htpasswd; prints the password.

- scripts/recreateUser.php USERNAME RAM_MiB QUOTA_GiB
  - Behavior: Kills user processes; if `/home/<user>` exists, moves to `/home/backup-<user>`;
    recreates from `/etc/skel`, ensures dirs (`data`, `session`, `.lighttpd`);
    re-applies configs (`userConfig.php`, lighttpd/nginx, permissions);
    restores `data`, `session`, and `.htpasswd` when available; validates ownership.

---

## Notes for Agentic Coding
- Prefer high-level helpers (`runStep`, `pmssRefreshRepositories`, `pmssApplyDpkgSelections`, `pmssUpdateUserEnvironment`) to keep logs/profile consistent.
- Honor environment overrides in tests (`PMSS_*` flags) to avoid mutating the real system.
- Treat `etc/skel/www` and vendor code as read-only.
- Keep destructive actions guarded and idempotent; reuse existing conventions.
