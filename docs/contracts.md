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

- defaultSpec(): string → `git/main`.

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
  - Exports `PMSS_JSON_LOG` path; dry-run or missing file emits `update_step2_skipped`.
  - Else runs `/scripts/util/update-step2.php`, logs start/end + duration; `fatal` on non-zero exit.

- runAutoremove(): void → `apt-get autoremove -y` with non-interactive dpkg opts; `fatal(EXIT_COPY)` on failure.

- maybeRunDistUpgrade(bool|string $distUpgrade): void
  - If enabled, runs `/scripts/util/update-dist-upgrade.php <max>` and logs start/end events.

- bootstrapMain(array $argv): void
  - Orchestrator: ensure root → parse/normalize/parse spec → dist-upgrade (optional) →
    workdir fetch → stage → record version → cleanup → self-update handoff →
    run phase 2 or scripts-only path → log completion with duration.

Environment flags consumed: none directly (phase 2 uses many).

Logs: `/var/log/pmss/update.php.log` (stdout mirror) and JSON `/var/log/pmss-update.jsonl`.

---

## Runtime Execution & Profiling

- runCommand(string $cmd, bool $verbose=false, ?callable $logger=null, bool $inheritTty=false): int
  - Spawns `/bin/bash -lc <cmd>` via `proc_open`, streams stdout/stderr, returns rc.
  - Exposes `$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']` with `stdout`/`stderr`.
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

- pmssJsonLogPath(): string → cached `PMSS_JSON_LOG` or `''`.
- pmssLogJson(array $payload): void → appends JSONL with added `ts` if path configured.
- logMessage(string $message, array $context=[]): void → writes to `PMSS_LOG_FILE` or fallback; also emits a JSON `log` event when JSON logging is configured.
- pmssSelectLogger(?callable $logger=null): callable → returns the given logger or `logMessage`.

---

## APT Repository Management

- pmssAptSourcesPath(): string → `PMSS_APT_SOURCES_PATH` or `/etc/apt/sources.list`.
- pmssLoadRepoTemplate(string $codename, ?callable $logger=null): string
  - Loads `/etc/seedbox/config/template.sources.<codename>` (or `PMSS_CONFIG_DIR`).
  - Returns trimmed content with trailing `\n`, or `''` and logs when missing/empty.

- pmssSafeWriteSources(string $content, string $label, ?callable $logger=null): bool
  - Backs up current sources to `.pmss-backup` (best-effort), writes new content or restores on failure.

- pmssUpdateAptSources(string $distroName, int $distroVersion, string $currentHash, array $repos, ?callable $logger=null): void
  - Dispatches by distro: Debian uses `pmssUpdateAptSourcesDebian`; Ubuntu logs unsupported.

- pmssUpdateAptSourcesDebian(int $version, string $currentHash, array $repos, callable $log): void
  - Applies templates for Jessie/Buster/Bullseye/Bookworm/Trixie; compares hash and logs “Applied ...” or “already correct”. Jessie also writes an apt conf to ignore release dates and cleans cache.

- pmssApplyAptTemplate(string $label, string $template, string $currentHash, callable $log, ?callable $post=null): void
  - Writes template via `pmssSafeWriteSources` when hash differs; runs post-hook if provided.

- pmssEnsureMediaareaRepository(): void → legacy shim; removes old MediaArea `.list/.sources` files from `sources.list.d` to avoid duplicate/invalid entries.
- pmssEnsureSonarrKey(): void → installs `/etc/apt/trusted.gpg.d/sonarr.gpg` so `apt-get update` does not fail for Sonarr sources.
- pmssEnsureDockerRepository(): void → ensures Docker deb822 source + keyring exist under `/etc/apt/sources.list.d/docker.sources` and `/etc/apt/keyrings/docker.gpg`.
- pmssRepositoryUpdatePlan(string $distroName, int $distroVersion, ?callable $logger=null): array
  - `mode=reuse` (unknown version) or `mode=update` with current hash and loaded templates.
- pmssRefreshRepositories(string $distroName, int $distroVersion, ?callable $logger=null): bool
  - Ensures Docker/Sonarr repo prerequisites, computes plan; runs `apt-get update` either way, with template write on update.

---

## Environment & Dpkg Baselines

- pmssConfigureAptNonInteractive(?callable $logger=null): void
  - Ensures `/etc/apt/apt.conf.d/90pmss-noninteractive` matches known content; logs SKIP/Updated; 0644.

- pmssCompletePendingDpkg(): void
  - Runs `dpkg --configure -a`. On error: unmask `proftpd.service` (systemd) and retry proftpd package configure.

- pmssApplyDpkgSelections(?int $distroVersion=null): bool
  - Picks selection file (`selections-debian<version>.txt`, fallback 11, then generic `selections.txt`).
  - `apt-get update`, `apt-cache dumpavail | dpkg --merge-avail`.
  - Sanitizes lines to `pkg<TAB>state`, ignoring malformed entries; uses temp file.
  - `dpkg --set-selections < file` then `apt-get dselect-upgrade -y`; retries with `--fix-broken`.
  - Returns true on successful apply+install; logs warnings.

- pmssMigrateLegacyLocalnet(): void → move `/etc/seedbox/localnet` to `/etc/seedbox/config/localnet` once.

---

## Distro Detection

- pmssDetectDistro(): array
  - Returns `['name'=>string,'version'=>int,'codename'=>string]`.
  - Prefers `VERSION_CODENAME`; maps codename→version; mismatches log “trusting codename”. Falls back to `lsb_release` or defaults.

- pmssVersionFromCodename(string $codename): int → Debian codename→major mapping; unknown→0.

---

## User Environment Orchestration – `scripts/lib/update/users.php` and submodules

- pmssUpdateUserEnvironment(string $user, string $rutorrentIndexSha=''): void
  - Builds context (`pmssBuildUserContext`), returns early when invalid.
  - Runs handlers in order: HTTP, skeleton, ruTorrent themes, ruTorrent refresh, plugins,
    retracker cleanup, permissions. Each handler consumes `['user','home','user_esc','rutorrent_index_sha']`.

Sub-handlers:
- pmssBuildUserContext(string $user, string $rutorrentIndexSha=''): ?array → validates `/home/<user>` with `.rtorrent.rc`, `data`, and no `www-disabled`; returns context.
- pmssUserConfigureHttp(array $ctx): void → configure lighttpd per-user, ensure php.ini `error_log`, create `.tmp` and `.irssi` (from skel), and `www/recycle` with perms/ownership.
- pmssUserApplySkeletonFiles(array $ctx): void → copies fixed list of skel files and quota plugin files into user tree using `updateUserFile()`; deletes `~/www/phpXplorer`.
- pmssUserUpdateThemes(array $ctx): void → ensures named themes exist under `rutorrent/plugins/theme/themes/` (copied from skel), fixes ownership.
- pmssUserUpgradeRutorrent(array $ctx): void → if user’s ruTorrent index.html SHA != skeleton (and no existing backup), backups to `oldRutorrent-3`, copies fresh from skel, restores config/share, updates config via `updateRutorrentConfig()`, fixes ownership and perms.
- pmssUserEnsurePlugins(array $ctx): void → removes deprecated `cpuload`, ensures `unpack` plugin exists and has proper perms.
- pmssUserMaintainRetracker(array $ctx): void → removes legacy `retrackers.dat`, creates torrents and RSS settings dirs.
- pmssUserRefreshPermissions(array $ctx): void → runs `/scripts/util/userPermissions.php <user>`; refreshes `~/.rtorrent.rc.custom` from skel if hash matches legacy list.
- pmssUserSkelPath(string $relative): string → returns `PMSS_SKEL_DIR` (default `/etc/skel`) joined with `$relative`.
- pmssUserSkelCommandArg(string $relative): string → returns raw `/etc/skel/<relative>` when default, otherwise escapes the overridden path for shell commands.

---

## Web Stack & System Prep

- pmssConfigureWebStack(int $distroVersion): void
  - Stops nginx; disables/stops lighttpd based on init system; kills lingering `lighttpd` and `php-cgi`.
  - Enables nginx; refreshes configs (`configureLighttpd.php`, `createNginxConfig.php`), ensures htpasswd, restarts nginx, checks per-user lighttpd instances; hardens `/home` perms.

- pmssPostUpdateWebRefresh(): void → re-runs the same trio (configureLighttpd, createNginxConfig, checkUserHtpasswd) and restarts nginx; checks instances.

- pmssApplyRuntimeTemplates(): void
  - Installs `rc.local`, systemd `system.conf`, and `sshd_config` from templates;
    sets perms and ownership; reexecs systemd and restarts sshd; runs rc.local.

- pmssEnsureAuthorizedKeysDirective(): void → ensures `AuthorizedKeysFile` is not commented in `sshd_config`, backs up previous config, restarts SSH via init.d.

- pmssEnsureCgroupsConfigured(?callable $logger=null): void → appends cgroup mount to `/etc/fstab` if missing, installs `cgroup-bin`, mounts path, attempts to raise root slice PID limit.

- pmssEnsureSystemdSlices(?callable $logger=null): void → writes user slice override template to `/usr/lib/systemd/system/user-.slice.d/15-pmss.conf` and `daemon-reload`.

- pmssResetCorePermissions(): void → `chmod -R 755 /etc/seedbox` and `chmod -R 750 /scripts`.

- pmssEnsureLocaleBaseline(): void → ensures `en_US.UTF-8` base locale, `fi_FI.UTF-8` for time formatting, sets system timezone to `Europe/Helsinki`, and calls `generateMotd()`.

- pmssReapplyLocaleDefinitions(): void → reuses the baseline helper to reassert locale/timezone configuration on legacy installs.

- pmssEnsureLegacySysctlBaseline(?callable $logger=null): void → writes legacy BFQ/sysctl defaults to `/etc/sysctl.d/1-pmss-defaults.conf` and runs `sysctl --system`.

- pmssConfigureRootShellDefaults(?callable $logger=null): void → ensures `/root/.bashrc` contains `alias ls=...` and `PATH=$PATH:/scripts`.

- pmssProtectHomePermissions(): void → `chmod o-rw /home`.

- pmssStopDisableMaskSeedboxSystemServices(): void → stops/disables/masks system-wide daemons that must never run on seedbox hosts (e.g. lighttpd, deluged/deluge-web, transmission-daemon, redis-server, memcached, rpcbind/nfs-kernel-server, smbd, exim4, docker.service). Fail-soft; safe when units are missing.
- pmssEnsureSystemdServicesGuardBootUnit(): void → installs/enables `pmss-systemd-services-guard.service` so the systemd hardening guard runs early at boot (before basic.target).
- pmssStopDisableMaskApacheLegacy(): void → stops/disables/masks `apache2` so it cannot be started during package recovery.

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

## Package Queue (transitional)

- pmssQueuePackages(array $packages, ?string $target=null): void → queue package names under `__default__` or suite (e.g., `buster-backports`), deduped.
- pmssFlushPackageQueue(): void → install each queue; split available vs missing with `apt-cache policy`, run `apt-get install` (with `-t <suite>`), retry with `--fix-broken`; run post-install commands; set env counters `PMSS_PACKAGE_INSTALL_WARNINGS|ERRORS`, log JSON event on errors.
- pmssPackageStatus(string $package): string → dpkg status string or `''`.
- pmssPackageAvailable(string $package): bool → checks cached `apt-cache pkgnames` set (fast path), falls back to `apt-cache policy` (cached).
- pmssInstallBestEffort(array $items, string $label=''): void → from each list item (string or list of fallbacks) picks the first available and queues.
- pmssInstallProftpdStack(int $distroVersion): void → queues proftpd stack (+nftables for >=10), unmask unit pre-install, and enqueues a `dpkg --configure` recovery command.

System/app groups:
- pmssInstallSystemUtilities(int $distroVersion): void → queues standard utility packages (ncurses/python3 family/zip/unzip/irssi/etc.); logs warn when v<10 and returns.
- pmssInstallMediaAndNetworkTools(int $distroVersion): void → queues media/network/backup tooling; kernels/firmware from backports on v=10.
- pmssInstallZncStack(int $distroVersion): void → queues `znc` and related packages; logs warn and returns on v<10.

---

## OS-Release & Skeleton Utilities – `scripts/lib/update.php`

- pmssOsReleasePath(): string → `PMSS_OS_RELEASE_PATH` or `/etc/os-release`.
- pmssSkeletonBase()/pmssSkeletonPath(string $relative): string → `PMSS_SKEL_DIR` or `/etc/skel` and joined path.
- updateUserFile(string $file, string $user): void → copies a skeleton file into `/home/<user>/<file>` when missing or checksum differs; sets mode 755 and `chown user:user`.
- copyToUserSpace(string $sourceFile, string $targetFile, string $user): void → copy + chmod 755 + chown user.
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
  - Behavior: Derives peers and upload slots based on `ramBlock` scaling; substitutes placeholders in template; appends `ipv4_filter.load = /etc/seedbox/config/localnet, preferred` if localnet exists.
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
  - Functions: `pmssResolveRcloneVersion()`, `pmssFetchLatestRcloneVersion()`, `pmssPersistRcloneVersion()`.
  - Logic: Picks pinned or latest version, replaces `/usr/bin/rclone` when version mismatch, installs from official zip.
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
  - Behavior: Regenerates nginx global and per-user config from templates.
  - Side-effects: Writes under `/etc/nginx/` and reloads/restarts nginx via callers.

- scripts/util/checkUserHtpasswd.php
  - Behavior: Synchronizes per-user htpasswd files with legacy global htpasswd; creates missing files.

- scripts/util/setupSkelPermissions.php
  - Behavior: Normalizes perms on `/etc/skel` (non-destructive to content); ensures expected modes/ownership.

- scripts/util/setupRootCron.php
  - Behavior: Installs/updates root cron entries from `/etc/seedbox/config/root.cron`.

- scripts/util/setupNetwork.php
  - Behavior: Renders and applies FireQOS from `template.fireqos` using `networkLoadConfig()` and `networkLoadLocalnets()`; writes config under `/etc/seedbox/config` and applies rules.

- scripts/util/ftpConfig.php
  - Behavior: Applies FTP server configuration from templates and restarts service.

- scripts/util/userPermissions.php <user>
  - Behavior: Fixes ownership/permissions under `/home/<user>` according to policy (chmod/chown); safe to re-run.

- scripts/util/userConfig.php <user> <rtorrentRamMiB> <quotaGiB>
  - Behavior: Applies quota settings and rTorrent/ruTorrent configs; seeds dotfiles; safe to re-run.

- scripts/util/portManager.php assign <user> lighttpd
  - Behavior: Assigns a unique port for the user’s lighttpd; persists reservation.

- scripts/util/systemTest.php
  - Behavior: Read-only probe of system readiness (binary versions, config presence);
    intended post-provision.

- scripts/util/update-step2.php
  - Behavior: Legacy consolidated phase-2 script (superseded by modular `lib/update/*`), retained for compatibility. Do not extend unless migrating behavior into modules.

---

## User Management (CLI)

- scripts/addUser.php USERNAME PASSWORD RAM_MiB QUOTA_GiB [trafficLimitGB]
  - Behavior: Creates Unix user with `/etc/skel`, sets password or generates one,
    sets expiry far future, ensures bash shell, records to runtime users DB (`users` class),
    assigns lighttpd port, applies config (`userConfig.php`), configures per-user
    lighttpd, regenerates nginx, starts rTorrent and lighttpd, refreshes network,
    installs default crontab, queues permission fix; optional traffic limit persists to runtime + user file.

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
