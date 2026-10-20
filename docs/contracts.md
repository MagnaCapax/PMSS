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

- fetchSnapshot(array $spec, string $tmp): string
  - For `release`: `curl` tarball and `tar -xzf` into `$tmp`.
  - For `git`: shallow clone with branch; dated pins fetch complete history and
    detach at the first commit selected by `rev-list --before` from the cloned HEAD.
    Incomplete history or no matching commit is fatal before staging.
  - Output: fetched version label for guard/log visibility (e.g., resolved `release:<tag>`).
  - Errors: `runFatal(EXIT_FETCH)` on failure.

- pmssBuildVersionSpec(array $spec): string
  - Builds the canonical recorded spec (`release[:tag]`, `git/<branch>[:pin]`, or custom repo form).

- pmssFetchedVersionLine(array $spec, string $fetchedVersion, string $tmp): string
  - Adds the cloned git commit timestamp to unpinned git fetched-version labels when `.git` metadata is present.
  - Leaves release labels and pinned git labels unchanged.

- pmssVersionMoveDecision(string $installedVersion, string $fetchedVersion, bool $explicitTarget): array
  - Parses orderable dates from release tags, git pins, or recorded unpinned git timestamps.
  - Output: `allowed`, `ordering` (`backward|forward|same|indeterminate`), and parsed order dates.
  - Fail-open contract: indeterminate ordering returns `allowed=true`.

- pmssGuardSnapshotVersionMove(string $fetchedVersion, bool $explicitTarget): void
  - Reads `/etc/seedbox/config/version`, logs the installed-version → fetched-version transition, and emits `snapshot_version_transition`.
  - Fatal `EXIT_FETCH` only when an unpinned target is proven older than the installed marker.

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

- `pmssFileWriteComplete()` returns `false` for NUL-containing paths before invoking a writer; valid paths retain complete-byte write semantics.
- `pmssReadRegularFileContentsVerified()` returns `null` for a `PHP_INT_MAX` byte limit, whose one-byte overread would overflow the stream length; valid bounded reads retain their content and metadata contract.

- `scripts/util/lighttpdSymlinkRepair.php USERNAME` is an explicit root-only
  recovery command for `.lighttpd/upload`, `access.log`, and `error.log` links.
  It requires an account-owned real home and a successful, non-exhausted live
  quota probe before changing links. Repairs run as the account and never touch
  link targets. A failed repair stops before service startup; a successful run
  calls `startLighttpd`. `PMSS_DRY_RUN=1` reports the link names without changes.

- `killProcess()` rejects NUL bytes in the original process name before trimming,
  so malformed names cannot select a process for `pkill -x`. Ordinary surrounding
  whitespace still normalizes to the same exact binary name.

- `pmssCheckDirectoriesEnsureDirectory()` requires the managed path itself to
  be a real directory before changing ownership or mode. A symlink or other
  occupied path logs a warning and returns `false` without changing its target.
  Both runtime and public directory checks reject NUL-containing paths before
  PHP filesystem calls, leaving later directories in the cron list reachable.

- `pmssSystemStatsAppendLogLine(string $path, string $line): bool` appends one
  newline-terminated system stats record. It returns `true` only when the full
  record was written; failed and short writes return `false` for the cron warning.

- `pmssUnsignedDecimalIntParse(string $raw): ?int` accepts only untrimmed unsigned
  decimal strings whose value fits `PHP_INT_MAX`. Zero and leading zeroes are
  valid; malformed or oversized values return `null`. Callers own their
  default, minimum, and error behavior.

- `pmssEnvReadDigits(string $envKey): ?int` accepts only raw unsigned decimal environment values, retaining zero, leading zeroes, and PHP integer-cast saturation. Unset, empty, signed, fractional, or whitespace-containing values return `null`; callers retain their existing defaults and zero policy.

- `pmssDirectoryEntriesRead(string $path): array|false` removes only `.` and `..`
  from a suppressed `scandir()`, preserving keys, order, and `false` on failure.
  Callers own path/symlink validation and whether scan failures may be ignored.

- `pmssLockFileHandleMatchesPath($handle, string $path, ?array $pathStat=null, ?array &$handleStat=null): bool`
  confirms matching device/inode metadata; supplied path snapshots can reuse
  returned handle metadata. Invalid/closed handles and unreadable metadata return false.

- `pmssStreamHandleIsOpen($handle): bool` accepts only live stream resources.
  Lock, snapshot, TTY, and command-pipe callers keep their existing failure results
  for closed handles and resources of other types.

- `pmssWriteManagedPathFileWithBackup()` leaves an existing managed file intact
  when its timestamped backup cannot be created. The backup helper accepts a
  copy only when it contains the full source file; incomplete copies are removed.

- Atomic user-file replacements publish only after the requested mode and any
  applicable owner/group changes succeed on the temporary file. Invalid modes
  or failed metadata operations return `false`, leave the existing destination
  intact, and remove the temporary file. Valid writes keep their prior bytes,
  ownership, and mode.

- Lock lifecycle helpers close streams when handle validation, lock acquisition,
  or explicit unlocking throws. The original throwable propagates; successful
  acquisition still transfers ownership to the caller, including the legacy
  open-but-busy result when `closeOnBusy=false`. `RuntimeLockSafetyTest` covers
  exception cleanup and ordinary creation, contention, and release behavior.

- `pmssRuntimeLockDir()` rejects empty normalized overrides and symlinked lock
  roots. An explicit override must be a directory after creation. The default
  path retains its legacy return behavior if an unprivileged caller cannot
  create it; ordinary existing directories retain their path and mode behavior.

- `pmssLockHandleWritePid($handle): bool`
  - Rewinds before truncating, preserving the previous PID if seeking fails.
  - Stops on failed seek, truncation, incomplete write, or flush and returns
    `false`; successful writes retain the decimal PID without a newline.
  - The caller retains ownership of the stream and its lock on every result.
    Writes are in-place: a write/flush failure after truncation cannot restore
    the previous contents. `RuntimeLockSafetyTest` injects each I/O failure.

- `pmssProcessCloseExitCode($process, $lastStatus=null): int`
  - Closes only process resources; invalid, closed, and other resource types
    use the existing `-1` fallback without consuming or closing unrelated streams.
  - A non-negative integer `exitcode` from the last observed status replaces
    only a `-1` close result. Native process exit codes retain precedence.

- `pmssNetworkPortParseDigits($value, int $min=1, int $max=65535): ?int`
  - Accepts integers or whitespace-trimmed decimal strings within the requested
    port range; malformed inputs return `null` without output.
  - Rejects NUL bytes before trimming. `pmssReadRegularFileNetworkPort()` passes
    raw file contents through this same guard, retaining regular-file and
    symlink checks. Valid ports, leading zeroes, whitespace, and bounds retain
    their existing behavior.

- runCommand(string $cmd, bool $verbose=false, ?callable $logger=null, bool $inheritTty=false, array $skipExitCodes=[]): int
  - Spawns `/bin/bash -lc <cmd>` via `proc_open`, streams stdout/stderr, returns rc.
  - Exposes `$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']` with `stdout`/`stderr`.
  - Timeout: defaults to 1200s; apt/dpkg commands are floored to 1200s. `PMSS_COMMAND_TIMEOUT` overrides but cannot reduce apt/dpkg below 1200s.
  - Timeout termination: sends SIGTERM first, then SIGKILL after a 5s grace period when the child lingers. Timeout fires append structured `timeout_fired` JSONL to `PMSS_TIMEOUT_FIRE_LOG` or `/var/log/pmss-timeout-fires.jsonl`.
  - When `$inheritTty=true` and stdin/stdout/stderr are TTYs, inherits the terminal for interactive prompts; output capture is disabled (`stdout`/`stderr` set to empty strings).
  - On non-zero rc, logs warning with 300-char stderr excerpt unless the caller declared that exact rc in `$skipExitCodes`. Timeout warnings remain visible.

- runStep(string $description, string $command, array $skipExitCodes=[]): int
  - Honors `PMSS_DRY_RUN=1` (rc=0, status=SKIP); logs `[OK|ERR|SKIP <secs> rc=<n>] ...`.
  - Records profile entry with duration, rc, and 300-char stdout/stderr excerpts.
  - A declared skip rc records `SKIP` and returns the raw rc; undeclared non-zero codes still record `ERR`.

- runUserStep(string $user, string $description, string $command, array $skipExitCodes=[]): int
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
- pmssSystemStatsHostPressureSnapshotWrite(string $path, array $stats, ?int $timestamp=null): bool
  - Atomically writes a mode-0644 customer-readable JSON snapshot containing
    only timestamp, five-minute full I/O PSI, and `/home` ioping latency.
  - Rejects unsafe/symlinked targets through the shared managed-file writer.

- pmssRecordProfile(array $entry): void → lazily initializes `$GLOBALS['PMSS_PROFILE']`, appends the entry, and emits JSON `step` event.
- pmssProfileSummary(): void → logs status counts and top 5 durations; writes full JSON to `PMSS_PROFILE_OUTPUT` or `(<PMSS_JSON_LOG>.profile.json)`.
  - Rejects malformed paths and symlinked targets or parents before preparing report directories or writing the report.
  - If JSON encoding fails, leaves any existing report untouched and creates no
    report directories. Directory preparation failures skip the write. These
    failures remain best-effort; valid report bytes and summary logs are unchanged.
  - Failed or short report writes emit a warning while the summary remains
    best-effort.

---

## Logging & JSON Events

- `pmssMediaStackWatchdogStatusWrite()` encodes before allocating a temporary
  file and publishes only a complete write. Encoding, write, mode, or rename
  failures leave the previous status untouched; unpublished files are removed
  even when filesystem operations throw. Successful JSON bytes and mode 0644
  remain unchanged, and exceptions continue to propagate.

- Iostat input boundaries (`scripts/lib/diskIostat.php`):
  - `/home` leaf selection uses the shared block-device walk. An incomplete
    topology falls back to the discovered-device list; BFQ policy may still
    report active when a readable leaf proves BFQ despite another failed branch.
  - Device-name validation matches the entire input, rejecting final newlines.
    Empty or NUL-containing discovery paths return the existing empty list.
  - NUL-containing executable paths raise `RuntimeException` before shell
    quoting, using the cron's existing catch-and-report path. Valid device
    names, executable quoting, discovery ordering, and empty-list fallback
    remain unchanged.
  - PSI `full avg300` accepts a complete unsigned decimal value only. Malformed
    or unrepresentable values return `null` (no signal); valid samples retain
    their float result.

- pmssRunSnapshotLogTask(string $scriptName, string $envKey, string $defaultLogPath, callable $callback): int
  - Empty or NUL-containing resolved log paths return 1 before creating parent
    directories or opening a file; the callback is skipped and the prior umask
    is restored. Valid defaults and environment overrides retain append behavior.
  - Opens the snapshot log for append and runs the callback under an exclusive
    lock when `flock` is available. Failed mode enforcement or a failed lock
    returns 1 without collecting or writing a snapshot, matching directory/open
    failures.
  - Successful runs retain the callback return code and log format; the stream
    closes and the previous umask is restored even when the callback throws.
    Environments without `flock` retain the legacy unlocked fallback.

## Agent Diagnostics – `scripts/util/agentDiagnostics.php`

- pmssAgentDiagnosticsMain(array $argv): int
  - Inputs: `--json`, `--pretty`, `--user USERNAME`, `--help`.
  - Output: text report by default, or JSON envelope with `timestamp`, `hostname`, `version`, `user`, and `sections`.
  - Side-effects: none intended; collects read-only command and file snapshots.
  - Validation: requires root outside `PMSS_TEST_MODE=1`; `--user` is validated through managed-user selection before per-user sections run.
  - Per-user metrics: `user_metrics_latest` reads the current JSONL tail; `user_metrics_24h_ago` reads the 289th line from the end of the newest uncompressed rotation plus the live log. With less history it returns the oldest available line; callers use its `ts` to determine the actual interval.

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
- pmssListManagedUsersResult(string $command): array → returns `exitCode` and sanitized `users`; NUL-containing command paths return exit code 1 with an empty user list before command execution.
- pmssApplyRsyslogKernelInputRateLimit(?callable $logger=null, ?callable $runner=null): void
  - Converges the stock Debian `module(load="imklog")` declaration in `/etc/rsyslog.conf` to a 10-second/2000-message input rate limit, preserving every other distro/operator-owned line (ADR 0047).
  - Validates a temporary full-config candidate with `rsyslogd -N1`, keeps a timestamped backup, atomically replaces the original while preserving metadata, and restarts rsyslog only after a successful change.
  - Nonstandard/custom imklog declarations, unreadable or unsafe paths, and validation failures are preserved with a warning. `PMSS_RSYSLOG_CONFIG_PATH` and the runner callback support hermetic tests.

---

## APT Repository Management

- pmssLoadRepoTemplate(string $codename, ?callable $logger=null): string
  - Loads `/etc/seedbox/config/template.sources.<codename>` (or `PMSS_CONFIG_DIR`).
  - Rejects names that are not a single alphanumeric-led filename component before reading a template.
  - Returns trimmed content with trailing `\n`, or `''` and logs when missing/empty.

- pmssSafeWriteSources(string $content, string $label, ?callable $logger=null): bool
  - Uses `PMSS_APT_SOURCES_PATH` (default `/etc/apt/sources.list`), backs up current sources to `.pmss-backup` (best-effort), writes new content or restores on failure.
  - A write counts as complete only when its byte count matches the requested content; short target writes use the existing restore path.

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

- Deluge compatibility patch publication (`scripts/lib/update/apps/delugePatches.php`)
  rechecks that the target is a regular non-symlink file before writing, in case
  it changed after the read. Missing or replaced targets use the existing warning
  and `false` result without calling the writer. `file_put_contents()` must report
  the complete payload length; failed, zero, or short writes use the same failure
  path, while complete writes retain the existing bytes and success result.

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
  - Sets `fs.inotify.max_queued_events` to 65536 for bursty media library monitoring.
  - Reserves the PMSS port-manager service band from kernel ephemeral source-port selection via `net.ipv4.ip_local_reserved_ports`.
  - Ensures `/etc/modules-load.d/pmss-bbr.conf` contains `tcp_bbr` (override path).
  - Respects operator-owned keys from `/etc/sysctl.d/90-pmss-overrides.conf` and records the applied profile under the `sysctl` section in `/etc/seedbox/config/hardware.json`.
  - When `$reload=true`, runs `sysctl --system` to apply the baseline.

- pmssEnsureSystemdDbusDisclosureHardening(?callable $logger=null): void
  - Converges four ordered PMSS-owned artifacts from one registry: systemd1 and login1 D-Bus restrictions plus tmpfiles policies for `/run/systemd/users` and `/run/systemd/sessions`.
  - Preserves the method-level systemd1 deny-list, destination-wide login1 deny with self-PID exceptions, and mode `0750` runtime-directory policies.
  - Each artifact remains independently fail-soft; changed D-Bus policy reloads `dbus`, while changed tmpfiles policy is applied immediately outside test mode.

---

## Distro Detection

- pmssDetectDistro(): array
  - Returns `['name'=>string,'version'=>int,'codename'=>string]`.
  - Prefers `VERSION_CODENAME`; maps codename→version; mismatches log “trusting codename”. Falls back to `lsb_release` or defaults.

- pmssVersionFromCodename(string $codename): int → Debian codename→major mapping; unknown→0.

---

## User Environment Orchestration – `scripts/lib/update/users.php` and submodules

- User transfer – `scripts/util/userTransfer.php`
  - Receiver runs as the target account inside a user namespace and a private
    mount view with filesystem paths outside the target home read-only. Source
    subordinate IDs map to the target allocation; unmappable ownership fails
    the transfer. Both
    rsync passes exclude `.billingId`, `.billingServiceId`, and
    `.billingClientId`; target provisioning and the canonical fallback reader
    retain service identity, while client ID returns after fleet backfill.
  - Main rsync excludes server-specific torrent client configs such as
    `~/.config/qBittorrent/qBittorrent.conf`.
  - Final rsync copies session data but excludes the source host's
    `session/rtorrent.lock`; the target keeps its own runtime lock.
  - Both rsync passes use one `--one-file-system`: recursion leaves separate
    filesystems as empty mount directories; same-device bind mounts still copy.
    Explicit source roots retain rsync's normal per-source filesystem boundary.
    Source and target home statistics use `du -sbx` and `find -xdev`; the existing
    completeness threshold remains advisory. Nested different-device mount
    counts and filesystem types are logged without customer paths; unavailable
    mount information does not fail the probe (ADR 0127).
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
  - Cross-username transfers hard-fail after normalization unless the `data`
    payload is target-owned and readable/traversable by the local account.
  - Errors: fail-soft; invalid/missing category metadata logs a warning or info
    line and does not abort the transfer.

- pmssUpdateAllUsers(string $rutorrentIndexSha): array
  - Enumerates users from `users::listHomeUsers()`, runs per-user maintenance, and returns summary keys: `total`, `processed`, `skipped`.
  - Emits end-of-loop summary log line `Processed N of M users` and JSON event `user_maintenance_summary`.
  - Calls `pmssUpdateUserEnvironment()` first, then applies linger/rootless-Docker wiring and optional post-refresh checks for each valid user.
  - Before marking a user refreshed, repairs stale per-user systemd drop-ins with bare sub-MiB `MemoryMax=<N>` values and matching suffixed `MemoryLimit=<N>M` siblings by appending the PMSS MiB suffix and reloading systemd.
  - Catches per-user throwables (including permission-step timeouts), logs warning, skips that user, and continues remaining users.

- pmssUpdateUserEnvironment(string $user, string $rutorrentIndexSha='', ?string &$reason=null): bool
  - Builds the minimal web-root context and reconciles the web root before
    active-tenant context gating; suspended users remain excluded.
  - Returns false when the web-root context, reconciliation, or active-tenant
    context is invalid, and true only after reconciliation and all handlers
    complete successfully. Callers must not mark refresh success on false.
  - When provided, `$reason` receives a stable predicate slug for false results such as `invalid-username`, `home-missing`, `home-symlink`, `home-outside-root`, `suspended`, `rtorrent-rc-missing`, `data-dir-missing`, or `web-root-reconcile-failed`.
  - Runs handlers in order: HTTP, skeleton, ruTorrent themes, ruTorrent refresh,
    plugin maintenance, then permissions.
    Each handler consumes `['user','home','user_esc','rutorrent_index_sha']`.
- pmssUserWatchdogLockAcquire(string $path)
  - Acquires one non-blocking watchdog lock and exports its descriptor list so
    the shared service launcher closes those descriptors before child exec.
- pmssUserWatchdogEnsureServices(string $username, array $serviceSpecs, array $runningStates=[]): array
  - Starts missing service specs after applying the exported lock-fd close prefix;
    the watchdog parent retains its lock while long-lived daemons cannot inherit it.
- qBittorrent toggle and watchdog diagnostics
  - A failed enable-marker write returns HTTP 500 before attempting a start.
    Password-sync prerequisite and failure branches write reason-only PHP error-log entries.
  - The qBittorrent watchdog reports a count of accounts skipped for a missing
    enable marker on each run; other service watchdogs retain their prior output.
- pmssUserSystemdWatchdogRunUser(string $username, string $homeRoot='/home', string $lingerRoot='/var/lib/systemd/linger', ?callable $probe=null, ?callable $accountLookup=null): ?array
  - Runs from the existing two-minute all-users cron and observes only accounts with
    linger, an account-authored `~/.config/systemd/user/*.service`, and cgroup v1.
    The PMSS-managed `docker.service` does not establish customer-unit intent.
  - Publishes `~/.systemd-user-status.json` atomically with mode 0644. A second
    consecutive inactive manager probe changes `pending` to `degraded`; probe
    failures publish `unknown`. It never starts, restarts, enables, or disables a unit.
  - Publication rejects failed encoding before allocating a temporary file and
    requires a complete write before rename. Failed writes, chmod, or rename
    preserve the previous snapshot and clean up the unpublished temporary file,
    including when those operations throw; the original throwable propagates.
  - New homes contain `~/.config/systemd/user/` from the skeleton as a safe default;
    this does not make parent-directory unit misplacement impossible.
- pmssUserReconcileWebRoot(array $ctx, ?callable $logger=null): bool
  - Converges one user's managed web root under a per-user lock, preserving
    customer-owned paths and refusing unsafe symlinks or conflicting path types.
  - A failed directory scan stops validation, ownership traversal, or skeleton
    copying; an unreadable existing root cannot be classified as empty.
  - Emits one structured summary log line per locked run with `reason`, `mode`,
    `files_restored`, `duration_ms`, and `preserved_conflict` fields.
- pmssEnsureLingerAndDocker(string $user): void
  - Enables linger + rootless Docker wiring for the user.
  - `lingerEnabled` is an independent, default-off opt-in: when true, maintenance enables linger even if rootless Docker is disabled.
  - Skips and attempts `userDocker.php stop` when user config `dockerEnabled` is false (default true, but defaults false for Storage Box product payloads) or the effective RAM floor for Docker is below 245 MiB; an active linger opt-in is retained.
  - Rootless Docker `daemon.json` convergence refuses symlinked or non-regular config targets before writing. An existing config that cannot be read returns `read_failed` and is left intact, including when invalid JSON replacement is enabled. Changes are installed through a temporary file with mode 0600. For a populated Docker data-root, or one whose contents cannot be scanned, maintenance preserves the storage driver and containerd snapshotter settings. For an empty store on Debian 10/11 with `fuse-overlayfs` available, it disables Docker's containerd image store so the classic rootless graphdriver honours `storage-driver`.
- `scripts/util/userDocker.php USER ACTION`
  - `stop` and `restart` pass `XDG_RUNTIME_DIR` when using a user systemd unit; a disabled `dockerEnabled` policy uses `systemctl --user disable --now` so the unit does not remain enabled.
  - Root-context `start` and `restart` launch the non-systemd rootless daemon through the slice-aware service launcher; same-UID invocations remain direct.
  - `stop` signals the account's dockerd first and polls for up to 20 seconds before stopping its user unit, wrapper, and rootlesskit. A non-zero user-unit stop result is logged and the signal path continues. Remaining account-owned Docker PIDs receive SIGKILL only after the 30-second deadline, then process state is verified.
  - A failed or unknown process check, or surviving Docker PIDs, returns non-zero; `restart` does not continue into the start path after an unsuccessful stop.

Sub-handlers:
- pmssBuildUserContext(string $user, string $rutorrentIndexSha='', ?string &$reason=null): ?array → validates `/home/<user>` with `.rtorrent.rc`, `data`, and no `www-disabled`; returns context or null with an optional reject-reason slug.
- pmssUserConfigureHttp(array $ctx): void → configure lighttpd per-user (including php.ini `error_log` through the account file writer, preserving unrelated directives and comments; an existing root-owned regular entry retains its managed-writer fallback), reserve/adopt native media-stack ports through the shared port manager, refresh the PMSS-managed qBittorrent safety defaults and Deluge fleet safety limits, create `.tmp` and `.irssi` (config copied from skel as the account only after directory creation succeeds), and `www/recycle` with perms/ownership. Does not repair previously flattened php.ini files.

- pmssReadTorrentThrottle(string $username): ?int → reads a bounded, singly linked regular entry and accepts only a non-negative decimal within the PHP integer range; production also requires the opened entry to have the managed owner and an expected group.
- pmssIntegerSettingFileRead(string $path, callable $parser, int $default=0): int → reads a bounded, singly linked regular entry before passing its text to the setting parser; missing or invalid entries use the caller's default.
- pmssUserApplySkeletonFiles(array $ctx): void → copies fixed list of skel files and quota plugin files into user tree using `updateUserFile()`; force-refreshes legacy `~/www/index.php` copies missing the PHP 8.2 `frameData` initialization; deletes `~/www/phpXplorer`.
- pmssUserUpdateThemes(array $ctx): void → ensures named themes exist under `rutorrent/plugins/theme/themes/` (copied from skel), fixes ownership.
- pmssUserUpgradeRutorrent(array $ctx): void → if user’s ruTorrent index.html SHA != skeleton (and no existing backup), backups to `oldRutorrent-3`, copies fresh from skel, restores config, and either re-links the ADR-0041 durable share store or copies an unmigrated real share directory forward. Preservation failures abort that user’s maintenance before it can be marked refreshed; successful runs regenerate config and normalize ownership/modes.
- pmssUserMaintainRutorrentPhpCompatibility(array $ctx): void → applies idempotent compatibility patches to writable per-user ruTorrent PHP files, including neutralizing the throttle plugin’s global `MAX_SPEED` re-pin while preserving rTorrent’s unlimited `0` rate.
- pmssUserEnsurePlugins(array $ctx): void → removes deprecated `cpuload`, ensures `unpack` plugin exists and has proper perms, removes legacy `retrackers.dat`, and creates torrents/RSS settings dirs.
- pmssUserRefreshPermissions(array $ctx): void
  - Runs `userPermissions.php` with optional `ionice -c3` wrapper when available.
  - Applies per-user timeout via `PMSS_USER_PERMISSIONS_TIMEOUT` (default 900s) by temporarily setting `PMSS_COMMAND_TIMEOUT` for that command only.
  - Throws `RuntimeException` when permission refresh times out so caller can skip that user and continue the queue.
  - Seeds absent `~/.rtorrent.rc.custom` files and refreshes known legacy hashes from skel through the atomic user-file writer (customer ownership, mode `0640`). Preserves customized files, symlinks and non-regular targets; dry runs leave files untouched.
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

- pmssEnsureSystemdSlices(?callable $logger=null): void → writes user slice override template to `/etc/systemd/system/user-.slice.d/15-pmss.conf` (never vendor paths), resolves the `/home` backing device for optional `IODeviceLatencyTargetSec` rendering from `cgroup.policy.php` on cgroup v2 hosts, optionally installs user manager `LimitNOFILE` drop-in at `/etc/systemd/system/user@.service.d/20-pmss-limits.conf` from `cgroup.policy.php` (`limitNoFileSoft`/`limitNoFileHard`), installs per-user log namespace drop-in at `/etc/systemd/system/user@.service.d/30-pmss-log-namespace.conf` (`LogNamespace=user-%i`), then runs `daemon-reload`. A failed template read warns and leaves the existing slice drop-in untouched.

- pmssConfigureTempMountNoexec(?callable $logger=null): void → when `PMSS_HARDEN_TMP_NOEXEC` is enabled, ensures `/tmp` and `/dev/shm` fstab entries include `noexec,nosuid,nodev`, and remounts when mounted; warns on missing mounts, unreadable fstab, or unsafe override paths.
- pmssConfigureTempTmpfsMount(?callable $logger=null): void → when `PMSS_HARDEN_TMP_TMPFS` is enabled, ensures `/tmp` has a tmpfs entry in `/etc/fstab` with `noexec,nosuid,nodev,size=<size>`, updates options if already present, and mounts/remounts `/tmp` when needed. Size defaults to `2G` and can be overridden via `PMSS_TMPFS_TMP_SIZE`; unsafe override paths are rejected through the same fail-soft warning path.

- pmssResetCorePermissions(): void → `chmod -R 755 /etc/seedbox` and `chmod -R 750 /scripts`.

- pmssEnsureLocaleBaseline(): void → ensures `en_US.UTF-8` base locale (including `LC_TIME`), sets system timezone to `Europe/Helsinki`, and calls `Motd::motdGenerate()`.

- pmssConfigureTempDiskBackedMount(?callable $logger=null, ?int $distroVersion=null): void → on Debian 13+ masks `tmp.mount` so `/tmp` stays disk-backed by default; earlier releases are left unchanged and explicit PMSS tmpfs hardening remains opt-in.
- pmssNetconsoleConfigure(callable $logger, ?callable $runner=null): void → when `/etc/seedbox/config/netconsole` contains a valid kernel `netconsole=` spec and the target MAC is reachable, writes `/etc/modprobe.d/netconsole.conf`, enables module autoload, and reloads `netconsole`.
- pmssEnsureBootTuning(?callable $logger=null): void → installs `/usr/local/sbin/pmss-boot-tuning.sh` and `/etc/systemd/system/pmss-boot-tuning.service` from templates, replaces `%%PMSS_BOOT_TUNING_SCRIPT%%`, enables/starts the unit, records `/etc/seedbox/config/hardware.json` when the boot script runs, and skips systemd actions in test/dry-run or when systemd is unavailable.
- pmssEnsureBootDefaults(?callable $logger=null, ?string $fstabPath=null, ?string $grubPath=null, ?string $grubOption=null, ?array $extraGrubOptions=null, ?array $extraGrubSettings=null): void → enforces `/proc` `hidepid=2`, ensures required grub cmdline options, and optionally pins explicit grub settings such as serial-console directives.
- pmssVerifyDistUpgradeBootReadiness(?string $mdstatPath=null, ?string $grubConfigPath=null, ?string $mdadmConfigPath=null, ?string $initramfsMdadmPath=null): void → non-fatal post-upgrade boot checks (RAID degradation markers, grub config presence/size, mdadm ARRAY entries, BOOT_DEGRADED flag) with warning logs for operator follow-up before reboot.

- pmssConfigureRootShellDefaults(?callable $logger=null, ?string $bashrc=null): void → ensures `/root/.bashrc` (or an explicit test target) contains `alias ls=...` and `PATH=$PATH:/scripts`; rejects symlink/non-regular targets, preserves existing metadata, and warns without reporting success when reading or atomic replacement fails.

- pmssStopDisableMaskSeedboxSystemServices(): void → stops/disables/masks system-wide daemons that must never run on seedbox hosts (e.g. lighttpd, deluged/deluge-web, transmission-daemon, redis-server, memcached, rpcbind/nfs-kernel-server, smbd, exim4, docker.service), then purges exim4 packages and stale exim spool files. Fail-soft; safe when units are missing.
- pmssEnsureSystemdServicesGuardBootUnit(): void → installs/enables `pmss-systemd-services-guard.service` so the systemd hardening guard runs early at boot (before basic.target).

- pmssInstallMediaInfo(string $lsbCodename, ?callable $logger=null): void → installs mediainfo with retry; logs version or warns on failure.

Bootstrap helpers from install-time env (Phase 2):
- pmssEnvFlagEnabled(string $name): bool → considers '', '0', 'false', 'no' as false.
- pmssApplyHostnameConfig(?callable $logger=null): void → honors `PMSS_SKIP_HOSTNAME`; applies `PMSS_HOSTNAME` via hostnamectl or hostname; writes `/etc/hostname`.
- pmssConfigureQuotaMount(?callable $logger=null): void → honors `PMSS_SKIP_QUOTA`; updates fstab quota options for `PMSS_QUOTA_MOUNT` (default `/home`) and remounts unless journaled quota is already active on the live mount. This lets first installs mount with quota options before `quotacheck` creates `aquota.*`.
- pmssEnsureQuotaOptions(string $mountPoint, array $requiredOptions=null, ?callable $logger=null): void → ensures quota options present on the `/etc/fstab` line; writes backup + updated file.
- quotaFix's final `/home` check compares declared user/group quota types in fstab with `quotaon -p /home`'s enabled-type count. A shortfall logs a specific warning without changing quotaFix's exit status; no declared quota types produce no query or warning.
- The daily quota snapshot retains `repquota_failed` in its private data log. When the target fstab entry declares journaled quotas, the same failure also emits `###PMSS_QUOTA_ALERT` to the cron log and exits non-zero. Other snapshot warnings retain their previous exit status.

---

## Networking

- pmssEnsureNetworkTemplate(?callable $logger=null, ?callable $routeReader=null): void → writes default PHP array config to `/etc/seedbox/config/network` when missing (first safe non-tunnel default-route interface, falling back to eth0; speed=1000 and throttle defaults). Existing config is not rewritten. A template missing `##INTERFACE##` warns and is not installed.
- pmssApplyNetworkConfig(): void → runs `/scripts/util/setupNetwork.php` to render/apply FireQOS config.

- detectPrimaryInterface(): string → from config or `ip route` default iface (fallback `eth0`).
- getLinkSpeed(string $iface): int → from config or `ethtool <iface>` (fallback 1000 Mbps).

FireQOS helpers:
- networkLoadConfig()/networkLoadLocalnets(): array → load active net config and localnets, with defaults and env overrides (`PMSS_NETWORK_CONFIG`, `PMSS_LOCALNET_FILE`).
- networkBuildFireqosConfig(array $networkConfig, array $users, array $localnets): string → render FireQOS template with per-user classes and localnets matches; optional per-user cap via `/var/run/pmss/trafficLimits/<user>.enabled`.
- FireQOS UID output and root-owned `.throttle` values must fit a PHP integer before rendering. Invalid UIDs are skipped; invalid throttle values retain the configured fallback cap. An unrepresentable cap in the installed config returns an unknown rendered state so the existing convergence check can retry.
- networkApplyFireqos(string $config): bool → writes `/etc/seedbox/config/fireqos.conf` and starts FireQOS; logs to `/var/log/pmss/fireqos.log`. On a failed start it moves the file to `fireqos.conf.failed` and returns false, so the hourly converge in `trafficLimits.php` retries; `setupNetwork.php` exits with the result.

iptables helpers:
- iptablesRun(string $rule): void → run single rule; logs error to `/var/log/pmss/iptables.log` on failure.
- iptablesParseMonitoring(string $raw): array → returns list of rule strings, stripping `/sbin/iptables` prefixes and ignoring flushes.
- iptablesApplyAtomically(array $filterCommands, array $natCommands): bool → builds an `iptables-restore` script and applies in one shot.
  - Its canonical `networkApplyIptablesAtomically()` helper requires a complete
    temporary-file write before invoking restore; failed or short writes return
    `false` through the existing write-error log path. Best-effort removal of the
    private temporary file runs in `finally`, including when writing, logging, or command execution
    throws. Exceptions propagate unchanged; successful rule bytes and command
    results retain their existing contract.
- iptablesApplyFallback(array $filterCommands, array $natCommands, array $replacements): void → applies rules one-by-one as a fallback.

---

## Resource Statistics

- Passive I/O ceiling samples, persisted ceiling reads, and derived read-IOPS oversell settings use the shared finite, non-negative numeric parser.
- `pmssResourceLogManagedUserUids()` revalidates list output before UID lookup
  and omits nonpositive resolver results. `pmssResourceLogLookupManagedUid()`
  accepts only representable, positive decimal output from its `id -u` fallback.
- `pmssDiskIostatWriteSnapshotFiles()` validates all three output paths before
  writing and returns `false` on a failed or short write. Successful snapshot
  and history bytes retain their existing order and format.
- Customer cgroup readers share ordered counter paths, unsigned/limit parsing,
  and memory fields in `etc/skel/www/scriptsIncCgroup.php`, loaded by
  `scriptsInc.php` (ADRs 0059 and 0099). Page-specific
  directory precedence and missing-data behavior stay unchanged; live pressure
  thresholds remain separate from the OOM recency signal.
- The five-minute metrics collector projects the maximum parent/child cgroup-v1
  `oom_kill` count into the owning account's private `.oomKillStatus` file (root:user
  0640). Its baseline and last-increase state are locked in root-owned
  `/var/run/pmss/oomStatus`; the collector never reads the home projection.
  Its first sample is an undated baseline; subsequent increases record the
  observation time, while a zero reset clears it. The welcome panel treats an
  observed increase as current for 24 hours when the sample is at most 15 minutes
  old. A higher live counter is immediately current; missing or stale projection
  data cannot turn a lifetime count into a permanent HIGH warning.
- `pmssCustomerNonnegativeFloat($value): ?float` is the customer-tree metric
  normalizer; it rejects non-numeric, non-finite, and negative values.

- pmssCustomerCgroupDirOwnsMemoryController($cgroupDir): bool
  - Accepts cgroup v1 directories only when `memory.stat` is readable; when
    `cgroup.controllers` exists, it must list the `memory` controller.
- pmssStatsCgroupDirDetect(string $uid, array $overrides=[]): string
  - Returns the first memory-controller-backed user slice, rejecting
    controllerless hybrid `unified` directories before falling through to the
    production cgroup v1 memory hierarchy. Tests may inject candidates through
    `cgroup_dir_candidates`.

- resourceStatistics::getData($user, $timePeriod=10080): string
  - Inputs: managed username and requested resource-log tail line count.
  - Safety: validates username/resource path before shelling, clamps line count
    to `1..10080`, resolves `tail` through runtime command helpers, and returns
    `''` on invalid paths, missing commands, non-zero tail exit, or timeout.

- Resource aggregation uses the persisted payload throughout: `ResourceStatsAccumulator::results()`
  and `resourceStatistics::collectWindowResultsFromData()` return metric-keyed
  `raw` windows, current values, and `daily` rows. `resources/payload.php` projects
  both stored and freshly accumulated data for reports and snapshots; missing
  legacy operation counters default to zero, other malformed metrics return `null`.
  Window values must remain finite after numeric conversion: infinity, NaN,
  and overflowing numeric strings use that same `null` failure path. Finite
  values and legacy missing-operation defaults retain their existing conversion.
- Usage reporting keeps scalar conversion and formatting inside their consumers.
  Terminal stats retain their help exit status and text layouts, including raw
  percentages beside clamped bars. Traffic columns retain unitless default rates
  and the extended display's 1000 MiB/s scaling threshold.
- Terminal rTorrent stats accept finite, non-negative numeric counter responses.
  Malformed counters retain the existing unavailable-probe result rather than
  reporting a successful zero; valid numeric strings and zero retain their values.
  An RPC exception leaves the optional probe unavailable or partial, while disk
  and traffic stats remain available.
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

## OS-Release & Skeleton Utilities

- pmssSkeletonBase(): string → `PMSS_SKEL_DIR` or `/etc/skel`; defined in
  `scripts/lib/pathSafety.php` so standalone skeleton consumers inherit it with
  the shared path-resolution helpers.
- updateUserFile(string $file, string $user): void → copies a skeleton file into `PMSS_HOME_DIR` (default `/home`) under `/<user>/<file>` when missing or checksum differs; ensures parent directories exist, writes via temp-file + rename, sets mode 755 and `chown user:user`.
- copyToUserSpace(string $sourceFile, string $targetFile, string $user): void → atomic copy via temp + rename, chmod 755, chown/chgrp user.
- updateRutorrentConfig(string $username, int $scgiPort): void → renders ruTorrent templates with user paths and writes `conf/{config.php,access.ini}`.
- getOsReleaseData(): array → cached `parse_ini_file` of `PMSS_OS_RELEASE_PATH` or `/etc/os-release`.
- getDistroName(): string, getDistroVersion(): string, getDistroCodename(): string → wrappers around `getOsReleaseData()`.
- pmssResetOsReleaseCache(): void → clears cached os-release data for current path.

---

## Per-user Notification Recipient - `scripts/lib/user/notificationEmail.php`

- `pmssUserNotificationEmailRead()` reads `.notifyEmail` only from a root-owned `0600`/`0640` regular file in the expected account group, rejects symlinks, and returns a single syntactically valid email address or `null`.
- `UserConfigStore::readNotificationEmail()` accepts only a valid managed username and requires the artifact to be root-owned and non-writable by group/other. `PMSS_HOME_DIR` overrides `/home` for hermetic callers.
- `scripts/util/userPermissions.php` converges the authoritative artifact to `root:<user> 0640`; `scripts/recreateUser.php` preserves it across account reconstruction.

---

## Per-user Usage Alerts - `scripts/cron/usageAlertsNotify.php`

- Alerts are off by default. The customer panel writes the customer-owned
  `~/.usageAlertsEnabled` marker as mode `0600`; root rejects symlinks, wrong
  ownership, and any other mode before treating the account as opted in.
- The hourly root cron reads the existing root-produced traffic, quota, and
  media-stack status artifacts. It alerts at 80% traffic, 90% disk, and the
  watchdog's `failed` service state, using the verified `.notifyEmail` recipient.
- Root-owned markers under `/var/lib/pmss/usageAlerts` suppress repeat mail while
  a condition remains active. A marker is written only after delivery succeeds
  and is removed when the condition clears or the customer opts out.
- Delivery reuses `pmssSupportMailSend()` and never logs the recipient address.

---

## rTorrent Configuration – `scripts/lib/rtorrentConfig.php`

- `scripts/util/rtorrentThrottleGet.php USERNAME` reads the live global upload
  and download limits from the account's rTorrent SCGI socket. It prints two
  `*_bytes_per_second` lines (zero means unlimited). Invalid usernames fail
  with usage on stderr; missing sockets and invalid SCGI responses print `n/a`
  on stderr and exit non-zero without partial stdout.
- The per-user `.rtorrentExecute.php` supervisor launches the PMSS-built
  `/usr/local/bin/rtorrent` directly, regardless of its inherited `PATH`.
- `etc/skel/.rtorrentThrottleInit.php`
  - Runs once in the background from the skeleton `.rtorrent.rc.custom` five seconds after rTorrent starts, so rTorrent remains free to serve the helper's SCGI request.
  - Derives the customer name from the executing UID, loads the customer's existing ruTorrent throttle plugin, and re-applies its cached named channel rates through `rThrottle::obtain()`.
  - Direct customer-defined `throttle.up`, `throttle.down`, `throttle_up`, or `throttle_down` entries in `.rtorrent.rc.custom` suppress the cached plugin restore so supported customer overrides remain authoritative.
  - Missing plugin files, invalid account names, and plugin failures exit non-zero without loading operator-only `/scripts` code or preventing rTorrent startup.
  - Ongoing user maintenance distributes the helper, seeds absent `.rtorrent.rc.custom` files and refreshes only known unmodified legacy defaults; customer-edited overrides remain untouched.

Class `rtorrentConfig`
- __construct(array $resourceConfig=[], ?string $template=null)
  - Loads default resource JSON (`/etc/seedbox/config/rtorrent.resources.json`) and template (`/etc/seedbox/config/template.rtorrent.rc`) when not provided; validates and fills defaults via `_checkResourceConfig()`.

- createConfig(array $config): array
  - Inputs: requires `'ram'` MiB. Optional: `'scgiPort'`, `'dht'` ('no|yes|auto'), `'pex'` ('no|yes|auto'). Only scgi is reserved; `dhtPort` and `listenPort` are neither reserved nor rendered.
  - Behavior: Derives peers and upload slots based on `ramBlock` scaling; `pieces.memory.max` uses headroom formula `max(170, ram - clamp(0.25*ram, 250, 1000))`; substitutes placeholders in template; appends `ipv4_filter.load = /etc/seedbox/config/localnet, preferred` if localnet exists.
  - Output: `['configFile' => string, 'config' => array]` ready to write.
  - Errors: throws on missing `'ram'` or invalid input.

- writeConfig(string $user, string $config): bool
  - Writes `/home/<user>/.rtorrent.rc` (touches 0644 when missing); returns true only when the complete configuration payload is written.

- idempotentConfig(string $user, string $config): ?bool
  - Reads current file and compares; writes only when content differs; returns write result or null when identical.

- readUserConfig(string $user): array|false → wrapper for readConfig on `/home/<user>/.rtorrent.rc`.
- readConfig(string $file): array|false → parses key=value pairs from `~/.rtorrent.rc` file (skipping comments and blanks).
- _configPortPrivate(string $type, int $rangeStart=2000, int $rangeEnd=65000): int
  - Reserves a random port using files under `/var/lib/pmss/ports/<type>/<port>`; idempotent by presence.
  - Rejects relative, dot-segment, NUL-containing, symlinked, or non-directory reservation paths before creating directories; valid reservations retain the existing range and exception behavior.
  - Default scgi/dht/listen acquisition is transactional: if a later type cannot be reserved, markers created earlier in the same call are removed before the exception is re-thrown.

- `scripts/cron/rtorrentPortReservationsReconcile.php`
  - Runs hourly under a single shared reservation lock and removes only old marker files absent from the union of every live user's stored rTorrent ports and readable `.rtorrent.rc` references.
  - Keeps recent markers for a one-hour publication grace and skips the whole pass whenever a live user's ownership cannot be determined from the available sources.
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

## WireGuard Provisioning – `scripts/lib/wireguard.php`

The facade delegates state parsing, endpoint discovery, peer assignment, and guide provisioning to `scripts/lib/wireguard/`. It creates the config dir, ensures keys, renders config and README, bootstraps one-device client profiles in `~/wireguard.txt` when users have no registered public key yet, repairs missing public-key registrations for existing PMSS-managed single-device profiles, seeds placeholder client configs for the remaining users, and enables `wg-quick@wg0` unless disabled.

Environment overrides: `PMSS_WG_CONFIG_DIR`, `PMSS_WG_HOME_BASE`, `PMSS_WG_USER_LIST`, `PMSS_WG_PRIVATE_KEY`, `PMSS_WG_PUBLIC_KEY`, `PMSS_WG_CLIENT_PRIVATE_KEY`, `PMSS_WG_CLIENT_PUBLIC_KEY`, `PMSS_WG_EXTERNAL_IP`, `PMSS_WG_INTERFACE_IP`, `PMSS_WG_DNS_IP`, `PMSS_WG_SKIP_SERVICE`.

Safety: per-user public-key reads revalidate the username and ignore missing,
non-regular, symlinked, unreadable, or invalid-key registry files.
Per-user guide address reconciliation derives the embedded private key's public
key and updates only the matching assigned peer; unmatched guides remain unchanged.
Service hostnames in guides require a root-owned billing ID entry.

---

## Application Installers (Contracts)

These scripts are primarily imperative; treat them as idempotent installers guarded by presence/version checks.

- btsync.php
  - Ensures BTSync 1.4/2.2 binaries in `/usr/bin/`; symlinks `/usr/bin/btsync`→2.2; installs/updates Resilio Sync to pinned version.

- servarr.php
  - Provides the shared ARR updater for Lidarr, Prowlarr, Radarr, Readarr, and Sonarr using one canonical app list in `arr.php`; update-step2 excludes this entrypoint from the default app autoloader so system updates do not block on account-scoped media-stack maintenance.
  - Contract (ADR 0034): install is not execution. `arr.php` downloads, extracts and activates releases and persists `install_path/version.txt`; it MUST NOT execute an installed binary, and unknown-version simply reinstalls. Symlinked version markers are ignored so a foreign-owned install tree cannot redirect root.

- arrRootExecutionBlock.php (`scripts/lib/update/`)
  - `pmssEnsureArrRootExecutionBlocked()` occupies `/root/.config/<App>` with a mode-0444 regular FILE, which makes the app's data directory un-creatable so a root launch aborts during logger init, before it binds. A leftover config DIRECTORY is moved to `<App>.pmss-disabled-<UTC>` and never deleted (an absent config is the first-run condition that regenerates `AuthenticationMethod=None` / `BindAddress=*`, so deleting it is security-negative). Refuses symlinked paths and never clobbers an existing backup. `/opt` is untouched: customers keep full use of the shared install. Root has no legitimate ARR instance, so this PREVENTS the launch rather than limiting its blast radius.

- arrRootGuard.php (`scripts/lib/`)
  - `PMSS_ROOT_GUARD_APP_INSTALL_ROOTS` is the one catalog for known consumer executable roots (the ARR family, qBittorrent, Deluge, Transmission, Jellyfin, SABnzbd, rTorrent, and Syncthing); `PMSS_ROOT_GUARD_ARR_DEFAULT_PORTS` is the one ARR listener catalog. `pmssRootGuardAuditAndKill()` selects known executable paths by real uid 0 or, for ARR paths, a default listener proven through `/proc/<pid>/fd` plus `/proc/net/tcp{,6}` inode matching. It kills only catalogued applications and signals the process group when the pid leads it (ADR 0035). A uid-0 executable outside standard system paths is reported as alert-only; customer-home copies and look-alike paths are not selected. Selection is exe-path plus uid/default-port, never a cmdline match. Called from `scripts/cron/mediaStackInstancesCheck.php` (root, every 2 minutes); findings emit `###PMSS_ROOT_GUARD_ALERT` and make the cron exit non-zero, while a clean run is silent.

- deluge.php
  - Debian 10: installs dependencies via pip and builds Deluge 2.0.5 from source.
  - Newer: `apt-get install -y deluged deluge-web`, disables service.

- docker.php
  - Installs rootless Docker prerequisites; adds Docker APT repo/key; installs Docker packages and disables the system service/socket; enables unprivileged user namespace; fetches newer `slirp4netns` on Debian < 12.

- filebot.php
  - Ensures `/usr/bin/filebot` at pinned version; downloads and installs deb when missing.
  - Refuses unsafe stale binary paths and stops before package installation if removal fails.

- openvpn.php
  - Seeds EasyRSA into `/etc/openvpn/easy-rsa`, writes vars, builds server certs/DH, renews an expired or soon-expiring server leaf under the existing CA after a PKI backup, renders server config from template, restarts service; writes client `.ovpn` and `ca.crt` to `/home`, packs `openvpn-config.tgz` into skeleton and updates user homes.
  - Client profiles and artifact names use the hostname as given when it contains a dot; bare hostnames gain `.pulsedmedia.com`. The system status artifact probe uses the same rule.

- rclone.php
  - Logic: Picks the pinned version by default, optionally fetches the latest release when requested, replaces `/usr/bin/rclone` when version mismatch, installs from the official zip.
  - Env: `PMSS_RCLONE_FETCH_LATEST=1` to request latest.

- wireguard.php
  - See WireGuard section above.

Other app installers (mono.php, syncthing.php, vnstat.php, iprange.php, pyload.php) follow the same pattern: install/refresh packages or binaries as needed and avoid breaking existing setups. Consult the scripts when extending.

The vnStat config refresh preserves the existing rendered settings and file metadata.
It rejects unreadable or redirected config paths and replaces the file atomically;
failed replacement leaves the previous config intact and skips the restart.

---

## Utilities (Script Contracts)

Automation often invokes these utilities; below are expected inputs and effects.

- scripts/util/configureLighttpd.php [<user>]
- Behavior: Renders per-user lighttpd vhost/fastcgi config from templates. With a username, targets only that user; otherwise (no args) refreshes all.
  - Logging: the managed template pipes access and daemon errors to their existing files in `~/.lighttpd/`. The logger appends as the account, so both files remain quota-bound and the hourly access-log trimmer remains active.
  - Process tracking: the managed config writes no PID file in the quota-bound home; the starter and watchdog detect account processes directly.
  - 503 pages: rendered lighttpd configs use the customer-tree `~/www/error-503.html` static page so app proxies that are enabled but still starting return a styled retry page instead of lighttpd's raw default.
  - PMSS-managed proxy fragments: always refreshes the qBittorrent and rclone proxies; when `/home/<user>/.invidiousPort` contains a valid port, also publishes `/public-<user>/invidious/` and `/user-<user>/apps/invidious/` through the user's lighttpd.
  - Proxy-fragment collision handling: when another readable `custom.d/*.conf` fragment defines the same URL conditional, the PMSS-owned fragment yields and is removed if already present; unsafe or unreadable sibling fragments block managed replacement without modifying either side.
  - PMSS-managed lighttpd proxy fragments enable `proxy.header` upgrade forwarding so WebSocket-capable apps can complete HTTP upgrade handshakes through the per-user proxy.
  - The qBittorrent fragment retains the panel Basic-Auth gate but removes its `Authorization` request header before proxying. qBittorrent then uses its own WebUI login and session cookie; an existing native install may prompt once for that login instead of signing in through forwarded panel credentials (ADR 0111).
  - Side-effects: Writes files under `/home/<user>/.lighttpd/` and lighttpd config directories.
  - Directory preparation seeds `.lighttpd/custom` at `0640` and reapplies that mode to existing regular files without rewriting their contents or modification time; symlinks and non-file targets remain rejected.

- scripts/cron/checkLighttpdInstances.php [<user>]
  - Behavior: Keeps per-user `lighttpd` and `php-cgi` healthy, regenerates missing configs, and refreshes per-user 502 pages while the web stack is unhealthy. A non-blocking runtime lock makes overlapping invocations print the existing skip line. A busy single-account invocation exits 75; the no-argument cron invocation still exits 0. The updater records the single-account result as `SKIP` and continues marking the account refreshed. The 502 page file helpers reject invalid account names before writing or treating a missing file as a completed deletion.
  - Socket failure markers: validates both the runtime directory and marker path before recording or clearing consecutive failures. Symlinks and non-regular markers remain untouched; an unsafe path or incomplete counter write retains the existing `wait` action with count zero and cannot authorize a socket-failure restart.
  - Socket health: An `ECONNREFUSED` result for a configured `php.socket-N` path is treated as stale-index drift when strict `ss -xln` parsing finds at least the configured number of live listeners under the same account's `.lighttpd` directory. Missing, malformed, or incomplete listener evidence retains the consecutive-failure restart gate.
  - Restart verification: after issuing a restart, retries the bounded `ss -xln` listener-coverage read through the shared command runner. Missing listeners emit `restart_attempted_still_down`; a failed probe emits `restart_attempted_unverified`. Both outcomes are log-only and never trigger another restart.
  - Behavior: Restarts the per-user lighttpd/php-cgi stack when the rendered config, `~/.lighttpd/custom`, or `~/.lighttpd/custom.d/*.conf` fragments are newer than the running lighttpd process.
  - Nginx evidence: the global run incrementally reads the PMSS suffix on `/var/log/nginx/access.log`; three watchdog cycles ending in a 502 without an upstream response header trigger one restart. If three more failing cycles follow, it regenerates the managed lighttpd config before one final restart. Any upstream response header resets the ladder, including a 502 generated by an application behind healthy lighttpd. A single-user invocation leaves the shared log cursor untouched.
  - Nginx cursor safety: failed reads, failed partial-line rewinds, and failed position queries leave the prior state unchanged; complete lines before a partial final line still advance normally.
  - Quota diagnosis: when quota is exceeded, compares the charged-over-soft amount with deleted blocks held by account-owned processes on the home device; an uncertain `/proc` or `stat()` scan or an overflowing block sum keeps the conservative quota reason. The `quota_descriptors` page never exposes descriptor paths and states that further deletion will not release held blocks.
  - Per-user toggle: when user config `lighttpdEnabled` is false, kills any running `lighttpd`/`php-cgi` for that user, removes the watchdog error page, and skips restart. Default remains true.

- scripts/cron/checkRtorrent.php
  - Behavior: Keeps per-user rTorrent/executor processes healthy and recovers missing `.rtorrent.rc` files from canonical templates when enough user config data exists.
  - Process facade: `rtorrent/process.php` loads inspection and lifecycle helpers. Snapshot headings and rows keep their callback order, and the SCGI decision retains the same queue and process-state gates.
  - Session recovery reports success only after the recreated `~/session` directory has customer ownership and, when POSIX account lookup is available, the customer's primary group. Ownership failures are logged and leave the directory for a later repair attempt without writing the session-reset marker.
  - Side-effects: Publishes `/root/changedConfigs` when a user's `.rtorrent.rc` is not owned by root; clears the stale report when no drift remains; write/remove failures are logged instead of hidden.

- scripts/util/createNginxConfig.php
  - Behavior: Regenerates nginx global and per-user config from templates; adds per-user subdomain vhosts under `/etc/nginx/conf.d/pmss-user-*.conf` when `/etc/hostname` is a valid FQDN.
  - Global template copy and default-site write failures emit stderr warnings; setup still reaches the existing nginx config test and restart decision.
  - Reconciliation: Replaces current per-user routes atomically without deleting them first. A full run prunes genuine orphan routes only after every selected user completes without a write failure; single-user runs never prune other accounts. Orphan pruning accepts only validated generated filenames and reports incomplete scans through the existing warning path.
  - Write failures: Keeps the previous route where it is still a safe regular file, emits a prominent warning summary, and returns non-zero only when the affected user has no serviceable primary `/etc/nginx/users/<user>` route. Intentional prerequisite skips retain their historical full-run versus single-user cleanup behavior.
  - Public proxy contract: `/public-<user>/` forwards the original scheme via `X-Forwarded-Proto` and only restores generic lighttpd redirects back under `/public-<user>/`; per-app media-stack Location rewriting stays in the user's `~/.lighttpd/custom.d/media-stack.conf` fragment, while Set-Cookie Path rewriting stays in nginx `proxy_cookie_path` rules because lighttpd `map-urlpath` does not rewrite `Set-Cookie`.
  - Subdomains: `USERNAME.<host>` proxies to `/public-<user>/` and also serves the published mcx.fi service and cluster names. Both labels use the first 16 hex characters of SHA256 over `mcx.fi:<kind>:<canonicalId>`, where kind is `service` or `customer` and canonicalId is the digit-only billing ID without leading zeroes (`0` if all zeroes). Hostname and certificate decisions accept positive IDs within the PHP integer range from root-owned, regular, singly linked files; `.billingServiceId` retains the `.billingId` legacy fallback. The old 64-hex private hash vhost never resolved and generated `pmss-user-<user>-hash.conf` files are removed during reconciliation.
  - The subdomain renderer retains only active and suspended public templates. Reconciliation still removes generated files from the retired private hash route.
  - Legacy `##delugeWebPort` templates use bounded, root-owned regular port files and accept only ports from 1024 through 65535; `.delugePort` retains its one-port offset.
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

- scripts/cron/systemdControlChannelCheck.php
  - Behavior: Every 15 minutes, root cron probes the manager Version property and logind session listing with 15-second deadlines. On failure it reexecutes systemd, restarts D-Bus then logind, and probes again.
  - Safety: Each recovery command has a 45-second deadline; recovery stops on command failure. Overlapping runs skip under a cron lock, and every recovery attempt is logged.

- scripts/util/ftpConfig.php
  - Behavior: Installs a PMSS-owned `proftpd.service` restart-only drop-in when the ProFTPD configuration directory exists and systemd is available. A changed drop-in triggers a systemd reload before the existing configuration test and service restart (ADR 0093).

- scripts/cron/checkGui.php
  - Behavior: Runs outside the panel request path and checks non-empty
    `www/index.php` and `www/rutorrent/index.html` sentinels. When either
    sentinel fails, it invokes the shared per-user web-root reconciler before
    retaining the existing core-file and directory repairs. Managed panel
    files with content differing from the skeleton are restored, even when
    the existing copy is larger; a missing skeleton leaves a non-empty copy intact.
  - Safety: Revalidates user paths and skeleton sources, and replaces repaired
    files atomically so a failed write does not truncate the prior copy; the
    shared reconciler keeps its per-user lock and symlink/path-safety guards.

- scripts/util/setupNetwork.php
  - Behavior: Renders and applies FireQOS from `template.fireqos` using `networkLoadConfig()` and `networkLoadLocalnets()`; writes config under `/etc/seedbox/config` and applies rules.

- scripts/util/netconsoleConfigure.php
  - Behavior: Applies optional kernel netconsole logging from `/etc/seedbox/config/netconsole` after verifying the target MAC is reachable on the configured link.

- scripts/util/ftpConfig.php
  - Behavior: Applies FTP server configuration from templates and restarts service.
  - TLS module: with a certificate and installed mod_tls, backs up Debian's modules.conf and activates only a missing mod_tls load line. An already-active line is unchanged; failed reads, backups, or writes leave the module file untouched. The existing config test still gates restart.

- scripts/util/userPermissions.php <user>
  - Behavior: Fixes ownership/permissions under `/home/<user>` according to policy (chmod/chown); safe to re-run.
  - Billing identity: `.billingServiceId`, legacy `.billingId`, and `.billingClientId`
    have `root:<user> 0640` as their managed state. `writeHomeMarker.php` creates
    registered billing markers as root (currently `.billingId`); `recreateUser`
    restores billing files with identity UID 0, and management-host backfill
    writes them as root. `userTransfer` excludes these markers and retains the
    target's provisioned `.billingId`. Readers require a root-owned regular file.
    This permission pass
    normalizes ownership and mode only for a root-owned regular file; it logs and
    leaves non-root-owned, symlinked, or other non-regular entries unchanged.
  - Provisioned recipient: Converges `.notifyEmail` to `root:<user> 0640`, preserving customer read access while preventing local redirection of future notifications.

- scripts/util/userConfig.php <user> <ramMiB> <quotaGiB>
  - Behavior: Applies quota settings and rTorrent/ruTorrent configs; seeds dotfiles; safe to re-run.
  - rTorrent ports: a reconfigure keeps the stored `rtorrentPort` when its scgi reservation marker under `/var/lib/pmss/ports/scgi/<port>` still exists (`pmssRtorrentPortReservationReusable()`); only a missing, invalid or unreserved scgi port is reserved anew. Existing `rtorrentDhtPort` and `rtorrentListenPort` payload keys and markers are left untouched; the reconciler covers only scgi.
  - Named mode: `scripts/util/userConfig.php <user> [RESOURCE_OPTIONS]` reuses the stored RAM/quota baseline for configuration but does not apply the stored disk quota to the kernel; only the full positional form invokes `setquota`.
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

- scripts/cron/cgroupPolicyRefresh.php
  - Existing user-policy applies also refresh a passive host estimate in
    `/var/run/pmss/io-ceiling.json`; the estimate never applies user limits.
  - `cgroup.policy.php`'s `ioCeiling` defaults: percentile 95, windowDays 7,
    minSamplesPerDay 144, minDays 3. Only completed UTC days qualify.
  - JSON includes settings, computed_at, excluded_current_day, aggregation_frame,
    qualifying days and published per-dimension maxima with a from_day map.
    Missing/thin history removes stale publication; consumers check freshness.
  - Reads the last 16 MiB of the active iostat history and `.1`; older compressed
    rotations are excluded. See ADR 0063 for the publication and retention contract.

- scripts/productConfig.php <product> --welcome-message=<HTML>
  - Behavior: Sets/clears product-level welcome banner templates in `/etc/seedbox/config/welcomeMessages.json`.

- scripts/util/portManager.php assign <user> [service]
  - Behavior: Assigns a unique port from the shared managed-service namespace and persists the reservation; fresh candidates must be bindable on both loopback and the IPv4 wildcard address.
  - Safety: rejects invalid usernames/service names before building reservation paths; `PMSS_PORT_MANAGER_DIR` may override the reservation directory for hermetic tests.
  - Lock failure: assignment and release return an error without changing reservations when the shared port lock cannot be acquired.
  - Assignment and release recheck the reservation path after acquiring the lock; a concurrent change is handled using the current assignment state.

- etc/skel/install-media-stack.sh
  - File-count quota preflight uses the account's soft file limit and reserves about 20,000 free files before downloading; unavailable or unlimited quota data does not block installation. A later command failure reports quota exhaustion when the account has reached that limit, and the panel shows the specific failure from the install log.
  - Port allocation: consumes root-provisioned `~/.media-stack-port-APP` markers for SABnzbd, Radarr, Prowlarr, Sonarr, Autobrr, and Jellyfin. A legacy configured port is preserved only when no marker exists; a fresh install without a valid marker fails closed and requests a full PMSS update.
  - App control: `--start-app=APP` launches only one installed, absent tmux session; `--stop-app=APP` kills only a one-pane session whose launch command contains the installer's app directory. A same-named customer session is left untouched. The closed app list includes Jellyfin, Sonarr, Radarr, Prowlarr, SABnzbd, Autobrr, and Cloudplow. `--start-stopped` skips `~/.<app>Disable` markers.

- etc/skel/bin/install-openclaw
  - Port allocation: consumes the root-provisioned `~/.media-stack-port-openclaw` marker, writes `~/.openclaw/gateway.port`, and refuses install or start without a valid marker.
  - Browser access: the customer-owned `~/.lighttpd/custom.d/openclaw.conf` preserves `/user-<user>/openclaw` and websocket upgrades behind the account Basic realm plus gateway token auth. Canonical origin uses the panel's hostname source; trustedProxies is loopback only. Failed panel verification removes the fragment and restarts the account web server again. Uninstall removes it with the same verification.
  - Dashboard handoff: `open-url` requires a live gateway, validates dashboard JSON and prints only a canonical HTTPS URL with the single-use bootstrap fragment and WSS gateway URL. No handoff logging or disk writes. The existing `apps.php?managed=openclaw&action=open` sends a no-store 302; mutations require the shared AJAX POST guard. The top-frame tab is `configure.php#apps` and appears only with a proxy fragment.
  - Quota preflight: before downloading, install checks for 60,000 available file slots below the account's soft file-count limit when quota output is usable; unavailable or unlimited quota output does not block installation.
  - Startup: install and manual start wait up to 120 seconds for the loopback listener; the cron check waits up to 5 seconds. A live process at the deadline is reported as still starting, and status shows listener state.
  - Lifecycle: install or successful manual start writes two marked jobs using `$HOME/bin/install-openclaw` in the account's own crontab; check stops retrying after five consecutive failed starts until manual start. Private `~/.openclaw/pmss-stopped` makes stop persistent; install/start clear it. Actions and checks serialize through `~/.openclaw-action.lock`. Repeated installs preserve onboarding, tokens and provider choice.
  - Authentication: a private gateway environment file supplies a token, and the gateway binds loopback only.
  - Model default: only a fresh install with a private, account-owned `~/.virsilipasKey` and a nonempty key plus HTTPS endpoint onboards the `virsilipas/default` OpenAI-compatible provider. OpenClaw stores an environment secret reference, and the gateway reads the current key from that file at startup; an existing account configuration or missing/invalid key keeps skipped provider onboarding.
  - Late key: install without a valid key writes mode-0600 `~/.openclaw/pmss-provider-pending`. Check stats it once; while pending it adopts a valid key only when the primary model is unset, writes mode-0600 `pmss-virsilipas`, removes pending and restarts once. A user-selected primary removes pending without configuration changes.
  - Provisioning: `addUser.php --app-profile=openclaw` writes `.rtorrentDisable` as the account, skips rTorrent start and Pikkumies, and queues account-owned installation in the user's slice only with RAM >= 2048 MiB, >= 60000 free file-quota slots and absent `~/.openclaw`. Unavailable quota or failed gates log a skip. Default provisioning is unchanged.

- etc/skel/bin/install-pikkumies.sh
  - Scope: command run by the account owner, or queued by `addUser.php` as the new user (in the user's slice, with `PIKKUMIES_SKIP_HERMES=1`) when the account has at least 1000 MiB RAM (ADR 0090), or the same way for an existing account by `scripts/lib/update/apps/pikkumiesPreinstall.php` on hosts with `/etc/seedbox/config/pikkumiesPreinstall.enabled` (an account is marked in `/var/lib/pmss/pikkumies-preinstall/` once its install succeeds and is not tried again); no root, port, service, or PMSS-managed cron.
  - Self-update: first fetches its newest copy from PMSS main into a temporary file and runs it with `--skip-update` when line 1 is the bash shebang and line 2 is `# PMSS: Pikkumies Installer`; otherwise the local copy runs. The fetched copy deletes its temporary file. `--no-self-update` keeps the local copy (ADR 0090).
  - Lifecycle: writes only `~/.mcxPikkumies` (git checkout with fast-forward updates), `~/pikkumies` (symlink), and `~/.virsilipasKey` when absent and not a link (Pulsed Media endpoint in `endpoint=`, empty `key=`). Through `pikkumies install`, it adds one daily `pikkumies update` crontab line tagged `# pikkumies-update` and one per-minute line tagged `# pikkumies-cron-tick` (PHP only unless a scheduled job is due) and preserves every other line, and puts `pikkumies` on `PATH`: a symlink in `~/.local/bin` or `~/bin` when either is on `PATH`, otherwise one marked block in `~/.bashrc` and `~/.profile`.
  - Local settings: PMSS does not edit files inside `~/.mcxPikkumies` because the Pikkumies updater stops on edited tracked files; use `~/.virsilipasKey` (platform key), `~/.pikkumiesKey` (a provider of the user's own, read first) or `~/pikkumies/local/`.

- scripts/util/systemTest.php
  - Behavior: Read-only probe of system readiness (binary versions, config presence);
    intended post-provision.

- scripts/util/supportCommand.php <message>
  - Behavior: Saves a read-only support snapshot under `/home/<user>/.support/requests/` and submits the same snapshot to the configured support inbox.
  - Inputs: Reads `/etc/seedbox/config/support.php`, `/etc/seedbox/config/version`, optional root-owned `/home/<user>/.billingServiceId` with `.billingId` legacy fallback, and optional root-owned `/home/<user>/.billingClientId`.
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

## Customer Bonus Display – `etc/skel/www/scriptsIncBonus.php`

- `pmssCustomerBonusDisplayStateRead(string $userBonusPath='../.userBonus', string $bonusQuotaPath='../.bonusQuota'): array`
  - Output: `['unit'=>'percent|gib', 'value'=>int]` for the customer-facing additional-resource banner.
  - Read order: an unsigned integer `.userBonus` value is authoritative and represents a percentage; otherwise a positive `.bonusQuota` value represents GiB.
  - The GiB fallback is aggregate additional quota and may combine earned bonus with paid addon disk; the customer tree has no source that can split those components.
  - Missing, zero, malformed, or unsafe values fall back to `['unit'=>'percent', 'value'=>0]`; the helper never derives a percentage from quota bytes.
- `pmssCustomerBonusDisplayTextBuild(array $state): string`
  - Formats loyalty percent states as `BONUS: N%` and aggregate `.bonusQuota` fallback states as `ADDITIONAL DISK SPACE: N GiB` so paid space is not presented as a gift.
- `pmssCustomerBonusDisplayNoteBuild(array $state): string`
  - Describes an applied GiB fallback as additional disk already included in the displayed quota; percentage states retain the loyalty-bonus wording.

## Customer Panel Frame Source – `etc/skel/www/index.php` and `welcome.php`

- The outer panel owns the browser title on both frame paths: `<short hostname> <vendor name> Dashboard`. It reads the system hostname without shelling, falls back to `php_uname('n')`, escapes the title as HTML, and defaults missing or invalid vendor names to `Pulsed Media`. Frame-source title elements are omitted so only the outer title remains.
- Remote `guiFrames` remains the default and primary on-load frame-definition source; bundled definitions remain its failover.
- The customer may select bundled definitions through the welcome-page control. The preference is represented only by the customer-owned `~/.guiFramesLocalOnly` marker; an absent marker restores remote-primary behavior.
- The write accepts the fixed `local` and `remote` values through a same-origin AJAX POST, refuses symlinked or conflicting marker paths, and remains reversible and idempotent.
- Local frame definitions suppress only the remote frame-definition request. They do not promise a fully network-free panel, and receive navigation changes through server PMSS updates rather than the remote on-load update path.
- `pmssCustomerContextualHelpLinkBuild()` renders the panel's `(?)` wiki link only for items registered with a published wiki page; an absent or unknown item renders no link.

## Customer File Manager URLs – `etc/skel/www/filemanager.php`

- A successful Settings save returns to the current folder listing without retaining `settings=1`.
- Settings persist in the account's `.config/filemanager-settings.json`, outside skeleton sync. Missing or invalid settings fall back to the script defaults; writes replace the JSON atomically without rewriting PHP source.
- Preview, Open, and DirectLink URLs retain the request script's directory (such as `/user-<username>/`) when tinyfilemanager has not selected a mapped multi-user root.
- The fallback runs after mapped-root selection so enabling tinyfilemanager's multi-user mode cannot double the request prefix.

## Customer Server Status – `etc/skel/www/welcome.php` and `stats.php`

- Traffic Info uses the same over-cap result for its warning and disclosure. An over-cap account without an active throttle marker reports the cap as policy, not current speed; an active marker reports its effective Mbps value. Approaching and over-cap states link to the service's traffic upgrade, or KB article 36 when the billing service ID is unavailable. Unlimited plans retain their separate path.
- When the update-only `statsHelpers.php` has not arrived, `stats.php` returns
  HTTP 200 with a short reload notice instead of failing during the guiv heal.
- `statsLive.php` (loaded by `stats.php` through `pmssWelcomeRequireLocalHelper()`; the block is simply absent when the helper has not arrived) prints
  the "Node load vs. your share" block from two root-written artifacts: `/var/lib/pmss/public/node-live.json` (node sums) and
  `/var/lib/pmss/live/<own user>.json` (own samples, `root:<user> 0640`). Customers never receive another account's samples (ADR 0126).
- `pmssStatsChartOptions()` caps the shared Traffic, CPU, Storage I/O, and IOPS chart x-axis at six visible date ticks and gives legends explicit point markers, width, and spacing (Refs #878).
- The Storage I/O text block emits explicit newlines between read, write, and
  operations values; PHP template close tags otherwise consume source newlines (Refs #879).
- The Storage I/O text uses saved display values when present and formats raw
  read/write bytes for month, week, day, and hour when display values are absent.
- `pmssWelcomeServerInfoHtmlBuild()` renders host uptime plus the 1/5/15-minute load averages after the per-account RAM section.
- Load is explicitly labeled as shared-server data rather than a per-account metric.
- `/proc/uptime` and `/proc/loadavg` are read directly from the customer tree without shelling; each unavailable or malformed metric fails soft to `unavailable` without hiding the other metric.
- `info.php` renders the `/home` RAID notice first, then a full-width standing
  Server storage row. The row is absent when `/proc/pressure/io` is missing,
  unavailable when it cannot be read or parsed, and heavy when live five-minute
  full I/O PSI reaches 20% or fresh `/home` ioping exceeds 100 ms.
- `welcome.php` retains an alert-only notice, with RAID taking precedence over
  heavy host pressure. Root cron publishes the optional ioping measurement to
  `/var/lib/pmss/public/host-pressure.json` at mode 0644. Snapshots older than
  15 minutes, future-dated, malformed, oversized, or symlinked are ignored;
  customer PHP never loads operator `/scripts`.

## Customer Torrent-State Backup – `etc/skel/www/scriptsIncBackup.php` and `welcome.php`

- `welcome.php?backup=download`
  - Streams a gzip-compressed tar archive directly to the authenticated customer without retaining a server-side copy.
  - Includes only the fixed, customer-owned rTorrent, ruTorrent, Deluge, and qBittorrent configuration and session/resume paths returned by `pmssCustomerBackupRelativePaths()`; media and unrelated account paths are excluded.
  - Rejects unsafe or top-level symlinked paths, runs as the customer UID, and does not accept archive paths from request data.
  - The panel warns that the archive may contain private tracker URLs and separate application credentials.
- `scheduledConfigBackup`
  - Per-user config-store toggle, default off. When true, the daily root cron runs the same allowlist tar primitive as the manual backup under the customer UID.
  - Archives are written as `~/.pmss-backups/config-YYYYMMDD-HHMMSS.tar.gz` with a private `0700` directory and `0600` files.
  - Retention keeps the newest 7 scheduled archives and prunes older files only after a newly written archive is verified as an existing non-empty file inside the same home.

## Customer Whole-Account Service Restart – `etc/skel/www/scriptsIncAppsHtml.php` and `scriptsIncActionJs.php`

- Welcome and Apps render one shared `Your apps` section and use its read-only status JSON to queue the customer-owned restart endpoints for rTorrent, enabled Deluge/qBittorrent/rclone/slskd/Copyparty frontends, stopped media-stack tmux apps, and Lighttpd.
- Requests run sequentially as the authenticated customer; qBittorrent retains its account-password synchronization challenge, media-stack recovery retains its same-origin AJAX POST gate, and failures do not prevent later services from receiving their request.
- The Lighttpd request is sent last so its graceful restart cannot interrupt earlier panel requests. Media-stack recovery uses the existing `--start-stopped` path and preserves live tmux sessions.

## Customer Apps Controls – `etc/skel/www/scriptsIncAppsStatus.php` and related parts

- Both `welcome.php?status=1` and `apps.php?status=1` emit the same live tmux, native-process, marker, and media-stack auth state; both are read-only. `?log=APP` reads only the selected media app's own log, capped at 16 KiB, twenty lines, and 300 characters per line with HTML escaping.
- A media app's tmux state is running only when its sole pane was launched from that app's installer directory. The root watchdog uses the same check; a same-named shell session is not an app.
- If the guiv-delivered Apps status part is absent, that Apps feed returns HTTP 503 JSON with `updating: true` and a reload message. The shared status poll shows the message in app rows and stops retrying; other transient failures still retry.
- While the stored media-stack launcher pid belongs to this account and its command line exactly matches the panel-launched installer, its `poll` status takes precedence over partial install markers: the Media Stack group shows one progress row and no app controls. A stale pid reused by another process does not keep the panel in Installing state. The shared status poll runs every five seconds until the installer exits, then renders app rows or the existing failure message and Install action. Media per-app controls require `appsRuntime.php` in the customer tree.
- The web install launcher closes its standard streams before returning the POST response; its PID file records the installer process used by status polling.
- All state-changing customer endpoints require POST with `X-Requested-With: XMLHttpRequest`. A GET action returns HTTP 405. qBittorrent retains the HTTP 428 password-sync challenge.
- Media app stop writes `~/.<app>Disable`; start removes it; restart preserves it. The observe-only watchdog publishes `off` with zero failures for marked apps, and bulk recovery skips them. Native frontend stops signal only account-owned processes in the panel's mount namespace whose executable resolves to the app's native binary allowlist; Deluge's Python entry points also require an exact interpreter and script match.
- Root-side Deluge Web configuration and port changes use the shared native stop predicate for system and account-selected entry points. A Deluge Web process outside those install paths is left running.
- slskd and Copyparty start only after account-UID PHP writes a mode-0600 config and separate random service login, followed by a secured marker. Their port-manager-assigned listeners bind to loopback and retain `/user-<name>/<app>/` through the lighttpd proxy. Root stores crash counts outside home: five consecutive starts missing at the next watchdog run yield `gave up`; restart clears that gate. The Apps row reads owner-only login and account-written `running`, `stopped`, `not secured`, or `gave up` state.

---

## User Management (CLI)

- Shared traffic and IOPS integer setting parsing accepts non-negative values
  only when PHP can represent them as an integer. Oversized decimal strings and
  floating values take the existing parse-failure path before persistence.

- `scripts/util/writeHomeMarker.php <user> <marker> <intValue>` accepts unsigned
  decimal marker values, including zero and leading zeroes, only when they fit
  a PHP integer. Invalid or oversized values retain exit code 4 and the
  existing stderr error before any home-file write.

- scripts/addUser.php USERNAME PASSWORD RAM_MiB QUOTA_GiB [trafficLimitGB]
  - Alternate form: `scripts/addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=QUOTA_GiB [resource options]`
  - Behavior: Creates Unix user with `/etc/skel`, sets password or generates one,
    sets expiry far future, ensures bash shell, records to per-user config store (`/etc/seedbox/config/users/<user>.json`),
    assigns lighttpd port, applies config (`userConfig.php`), configures per-user
    lighttpd, regenerates nginx, starts rTorrent and lighttpd, refreshes network,
    queues permission fix; optional traffic limit persists to runtime traffic files (user config store always writes `trafficLimit=0`).
  - Resource passthrough: Supports optional `--traffic-limit-gb`, `--traffic-cap-mbit`, `--upload-throttle-kib`, `--cpu-weight`, `--io-weight`, `--io-read-bw`, `--io-write-bw`, `--io-read-iops`, `--io-write-iops`, and `--cpu-quota-percent` flags while preserving the legacy positional form.
  - Resource options share one specification and projection across creation and reconfiguration (ADR 0080). Creation keeps raw nonempty values; targeted updates use explicitly supplied values; full updates retain defaults. Named option precedence and help order stay fixed.
  - Bonus display state: optional `--bonus-quota-gib` accepts a non-negative integer; positive values are atomically written to `~/.bonusQuota` as `<user>:<user> 0640` during post-provisioning, while omission or zero leaves the artifact absent.
  - Help: `-h` / `--help` prints structured usage and exits successfully.
  - Parse failures: missing or invalid CLI arguments print usage/error text to stderr and exit non-zero.
  - Guardrails: Per-user lock file prevents concurrent addUser runs for the same username.
  - Guardrails: Before `useradd`, removes a regular cron spool for the username only when its owner UID has no account; claimed or unusual entries abort provisioning for inspection.
  - Guardrails: After `useradd`, addUser converges the symlink-safe per-user `.lighttpd` directory before initial credential sync; an unsafe or uncreatable path fails provisioning and retains early rollback.
  - Guardrails: Rejects reserved system/service usernames to avoid future account collisions.
  - Recovery gate: When `/etc/passwd` already contains the username, addUser may self-heal only if the latest `###ADDUSER_JSON` summary for that user is a recent internal `FAIL` and both per-user `rtorrent` and `lighttpd` are inactive; the stale account is cleaned before retry. All other existing-user cases still abort.
  - Recovery gate: When the current run fails after `useradd` but before `userConfig.php` completes, addUser reuses the stale-account cleanup path on shutdown so `/etc/passwd`, `/home/<user>`, ports, lock files, and the per-user config store do not linger half-created.
  - Fail-fast: Aborts on existing user, orphaned home directory, failed cleanup of stale failed-provision state, failed `useradd`, failed `changePw.php`, or failed `userConfig.php` to avoid unsafe overwrites.
  - Logs: `/var/log/pmss/addUser.log`, shared user logs (`/var/log/pmss/users.log`,
    `/var/log/pmss/users.jsonl`), and per-user logs under `/var/log/pmss/users/<user>.log`.
    Emits `###ADDUSER:SUCCESS|FAIL|ERROR` summary markers for grep plus
    `###ADDUSER_JSON:{...}` with explicit `success`/`exit_code` fields for automation.

- scripts/changePw.php [--jsonl] USERNAME [PASSWORD]
  - Behavior: Sets the Unix password (generated if omitted), writes the per-user htpasswd and any existing qBittorrent config with the account's privileges, and prints the password.
    On Debian 11 and newer, shadow and htpasswd receive the same `$2y$` bcrypt hash. Debian 10 and unreadable or nonnumeric Debian versions retain the `passwd` and `htpasswd -B` path. Both shadow paths feed credentials through stdin.
    With `--jsonl`, suppresses human status lines and emits one JSON object with the new credential, sync return codes, and `qbittorrent_updated` (`true` when the existing qBittorrent config was updated, `false` when no qBittorrent config was present, `null` before that sync step is reached).

- scripts/suspend.php USERNAME
  - Output: With non-TTY stdout, emits empty heartbeat lines during silent steps. TTY output is unchanged.

- scripts/unsuspend.php USERNAME
  - Behavior: Unlocks the account, restores and validates the active web root,
    refreshes nginx, starts lighttpd directly, and verifies that both the
    user-owned `lighttpd` and `php-cgi` processes remain running.
  - Failure: Still attempts the existing rTorrent start, then exits non-zero when
    the web-stack launch or process verification fails.
  - Output: With non-TTY stdout, emits empty heartbeat lines during silent steps. TTY output is unchanged.

- scripts/terminateUser.php [--dry-run] [--confirm] USERNAME
  - Behavior: Validates the managed user and exact `/home/<user>` path, disables systemd linger and removes tmux and screen sockets under the account UID without a PAM session before `userdel`, then synchronously purges `/home/<user>`, `/home/backup-<user>`, and `/home/backup-<user>.superseded`. Socket cleanup uses `runuser --pty` where supported; older util-linux uses `setsid runuser` with stdin closed. The selected path is logged. After the final process kill, it waits for the user manager to stop before resetting a failed state. The linger, socket, and manager steps are time-bounded and run only when the initial passwd lookup succeeds.
  - Scope: The backup purge targets only the two exact recreate-backup paths; it never sweeps `backup-*` prefixes.
  - Output: With non-TTY stdout, emits empty heartbeat lines during silent steps, including preflight and synchronous purge. The existing completion line remains the last non-empty line. TTY output is unchanged.
  - Removal: Removes ordinary contents first, clears immutable attributes only from the remaining residue, and retries removal — so the recursive attribute walk never traverses a full account.
  - Completion: After the final cleanup, exit 0 and print `## Done. User termination verified.` only when every purge step ran, the immutable clear and leftover removal of each path succeeded (the first removal fails by design on immutable files), the passwd entry and linger file are absent, and all three paths are absent. Otherwise exit 1 and print `## Incomplete: <first failed check or step label>`. The lifecycle end event records `OK` or `INCOMPLETE`.
  - Dry-run: Logs planned removal work without deleting those paths; its final line is `## Done. User termination flow completed (dry run).` and does not claim verification.
  - Safety: Direct cleanup helpers reject NUL-containing paths and skip malformed/out-of-range rTorrent port values before unlinking reservation files. The recursive purge step builder accepts only exact managed home or recreate-backup paths for valid usernames; invalid paths throw before a shell step is built.

- scripts/recreateUser.php USERNAME RAM_MiB QUOTA_GiB
  - Pikkumies: Clears the root-owned preinstall marker only after the skeleton rebuild succeeds; the next staged update can reinstall it.
  - Pre-flight: Before backup handling or process termination, exclusive private write probes must succeed in `/home`, the port runtime directory (or its parent before creation), and `/etc`; failure names the path and leaves the account untouched.
  - Behavior: Acquires a per-account runtime lock, then kills user processes;
    if `/home/<user>` exists, makes it private to root
    and moves it within `/home` to `backup-<user>`. Rebuilds from `/etc/skel` with the new
    home private to root. Renames `data`, `session`, and `.local/share/pmss` from the
    backup into vacant destinations (including skeleton directories holding only
    a regular `.gitkeep`, which is removed). Directory moves require the source
    and destination parent on the same device before placeholder removal and
    verify the moved inode after rename. Then restores root-owned billing/contact
    files (`.billingServiceId`, legacy `.billingId`,
    `.billingClientId`, and `.notifyEmail`) and regular `.htpasswd`. Billing
    files retain `root:<user> 0640`. Billing sources outside the file-type or
    ownership contract stay in the backup and are reported.
    The home is handed to the user only after restore, before service configuration,
    nginx regeneration, and permissions. Ownership is validated, then the home
    remains account-owned for the `changePw.php` password reset. The backup stays
    root-private during the rebuild;
    after the password step succeeds, its top-level directory is returned to
    `<user>:<user> 0700` so the account can reach files left in it. The shared
    per-user environment pass used by addUser and update-step2 runs after the
    password succeeds and any backup is handed back. A failed pass is logged
    and left for the next PMSS update (ADR 0089). The backup remains
    root-private on password failure. Per-user config overrides stay in the
    backup and their paths are printed for manual review. A missing home leaves
    any prior backup alone.

---

## Pikkumies Eternals and web UIs (contract v1)

This is the shared contract for PMSS, mcxPikkumies, and
mcxPikkumiesAgentboard. ADR 0124 records Eternals and account web UIs;
ADR 0125 covers vanilla Hermes. Paths beginning with `~` are relative
to the account home. `L` means `Config::localDir()`.

- Install root: `~/.mcxPikkumies/pikkumies`; `L = Config::localDir()` is
  `<root>/local`. There is no `~/.pikkumies` state root.
- Eternal records: `L/eternals/<id>` where `id` matches `^pm[1-9]$`.
  The locks are `L/eternals.lock` and `L/eternals/<id>.run`; the Off marker is
  `L/eternals.off`. The source hold is `L/eternals.hold` with JSON
  `{"until": <ts>}`;
  `hold --until <ts>` applies on both source and target during transfer.
- Host marker: `/etc/seedbox/config/pikkumiesEternals.enabled` is root-owned
  and staged on hosts. No `etc/seedbox/config/*.enabled` file is shipped.
- Lifecycle: Pikkumies' own per-minute user cron tick keeps the Eternals running
  and resumes them after reboot unless the user quit them. The tmux session is
  `pikkumies`; its windows are `pm1` through `pm9`, named by `id`, with `pm1`
  as Primary. The pinned invocation argv is `pikkumies run --eternal <id>`;
  fleet monitoring matches this argv.
- `pikkumies eternal list --json` returns
  `[{"id":"pm1","state":"running|starting|waiting-for-setup|not-ready|quit"}]`.
  The only states are `running`, `starting`, `waiting-for-setup`, `not-ready`,
  and `quit`; there is no `failing` state. The reported capability flag is
  `eternal`: an existing `pikkumies eternal list --json` means capable.
- `pikkumies ask` reads text on stdin, limited to 4000 chars. Exit codes:
  `0` = sent; `1` = error; `2` = setup not finished; `3` = approval prompt open;
  `4` = rate-capped; `5` = Primary starting; `6` = Primary busy.
  The UI retries exits 5 and 6 every 5 s. `ask` pastes only when Primary is idle.
  Terms pending is NOT an exit-75 refusal: Primary starts and shows the terms prompt.
  Do not emit the string
  `Primary not running`; show state via the list, badges, and launcher pages.
- Simplified UI (port A): only `pikkumies ui start` starts the process. PMSS
  installs the root-owned binary `/opt/pikkumies-agentboard/agentboard`.
  It uses the Unix socket `L/ui/ui.sock` in `L/ui/` (0700), single-use tokens
  in `L/ui/tokens/` (0700, files 0600, lifetime 60 s), and TMPDIR
  `L/tmp/` (0700). The `pikkumies` tmux server, Eternals, `ask`, `ui start`,
  and UI processes use `TMUX_TMPDIR=L/tmux` (0700) and `TMPDIR=L/tmp`
  (0700), never `/tmp/tmux-<uid>`. Each UI process keeps its own data dir,
  log file, and tmux pid file under `L/ui/<ui>/`. Environment names are
  `AGENTBOARD_MODE=pikkumies`,
  `AGENTBOARD_SOCKET`, `AGENTBOARD_TOKEN_DIR`, `AGENTBOARD_LAUNCHER_URL`,
  `AGENTBOARD_ALLOWED_ORIGIN`, `AGENTBOARD_FRAME_ANCESTORS`,
  `TMUX_SESSION=pikkumies`, and `TERMINAL_MODE=pty`.
- Welcome-page prompt bar: hands a question to Primary. Pending-ask JSON in
  the port-A origin's localStorage is a queue of up to 5
  `{"text": string <= 4000 chars, "nonce": string, "ts": unix seconds}` items,
  delivered in order. Its TTL is 60 minutes. The UI frames and panel use
  `pikkumies:switch`, `pikkumies:ready`, `pikkumies:reconnect`,
  `pikkumies:rows:1`, `pikkumies:rows:2`, `pikkumies:rows:3`,
  `pikkumies:rows:4`, `pikkumies:rows:5`, and `pikkumies:rows:6`
  postMessage names, always with
  an exact target origin. UI pages accept no inbound postMessage.
- `?session=` accepts only `^pm[1-9]$`. Per-UI `ui=` selector values are
  `simplified` (port A, agentboard fork in pikkumies mode), `hermes` (port B,
  Pikkumies' Hermes dashboard, "Power user"), `vanilla-hermes-web`, and
  `vanilla-agentboard` (port C, one at a time).
- Ports A, B, and C are respectively 844, 845, and 846. ADR 0124 records
  their account UI routing. Each port serves its UI at `/` on the service
  permalink. A also serves `/bar` from a root-owned host path outside `/home`.
  The host marker gates A and B; C does not depend on it.
- Port C tokens live in `~/.config/pmss/ui/tokens/` (0700, files 0600,
  single-use, 60 s). The launcher accepts `?view=status` for a same-origin
  GET that reads state and may place a guarded certificate request; it does
  not start a UI or mint a token. An absent `Sec-Fetch-Site` is denied.
- Account UI preferences live in `~/.config/pmss/ui/`; absent means Simplified
  for Pikkumies and Hermes web UI for vanilla Hermes.
- The Agentboard binary is `/opt/pikkumies-agentboard/<version>/agentboard`,
  reached through `/opt/pikkumies-agentboard/agentboard`. It uses the
  `bun-linux-x64-baseline` target. The installer is inactive with an empty
  version or sha256 pin.

The PMSS port-A launcher and route are inert until the host marker, pinned
binary, root-owned billing identity, and per-service certificate exist. The
launcher refuses the `hermes`, `vanilla-hermes-web`, and
`vanilla-agentboard` selectors while their authenticated backends are absent.
The service permalink is built only from the root-owned provisioning record;
an account-owned billing dot-file cannot select another service name.

---

## Notes for Agentic Coding
- Prefer high-level helpers (`runStep`, `pmssRefreshRepositories`, `pmssApplyDpkgSelections`, `pmssUpdateUserEnvironment`) to keep logs/profile consistent.
- Honor environment overrides in tests (`PMSS_*` flags) to avoid mutating the real system.
- Treat vendor/third-party code as read-only unless a narrower rule explicitly allows the change. First-party `etc/skel/www` files are editable normally.
- Keep destructive actions guarded and idempotent; reuse existing conventions.
