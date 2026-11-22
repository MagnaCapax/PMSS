# PMSS Architecture Overview

A quick map for agents touching this repository:

## Usage Notes (agents only)
- Keep this doc compact; use it as a jumping-off point when diving into unfamiliar code.
- Testing philosophy: maintain fast dev-time suites that avoid network/system mutations, and plan for separate production probes that capture real-world health (package presence, service status) for logs once implemented.
- Tests live under `scripts/lib/tests/development` (unit-style) and `scripts/lib/tests/production` (post-provision probes). Use the matching runner for each tier.
- Never break old users: upgrades must be backward compatible and data-safe; treat the existing fleet as immutable requirements.
- Debian 13 (trixie) is experimental; do not assume full support until a Debian 13 dpkg baseline is captured and validated.
- Contracts and invariants: each module must declare its pre/postconditions (e.g., package phase leaves services runnable) and tests should enforce them.
- Repo detection prefers `VERSION_CODENAME`; if neither codename nor numeric version is known the updater skips rewriting `sources.list` and logs a warning (preventing accidental downgrades).
- `scripts/util/systemTest.php` offers a read-only CLI probe of system readiness (binary versions, config presence). Run it only on real hosts after provisioning.

## Bootstrap Flow
Keep the canonical installer/update details under `docs/install.md` and
`docs/update.md`. This section highlights the responsibility breakpoints:

1. **install.sh** – Thin bootstrapper; ensures core tools exist and then defers
   to `update.php`. See [`docs/install.md`](./install.md) for the authoritative
   checklist.
2. **update.php** – Snapshot fetch + staging. Logging, argument parsing, and
   hand-off behaviour live in [`docs/update.md`](./update.md#phase-1--scriptsupdatephp).
   Its source-request decoder shares option dispatch, source records and version
   label projection internally, preserving standalone recovery (ADR 0181).
3. **update-step2.php** – Orchestrator that consumes the staged tree and runs
   modules under `scripts/lib/update/`. Responsibilities and ordering are
   documented in [`docs/update.md`](./update.md#phase-2--scriptsutilupdate-step2php).

## Key Modules
- **scripts/lib/cgroup/userSliceMemsw.php** rejects empty, NUL-containing and
  option-shaped output paths and values other than unsigned decimal digits or
  `-1` before launching its bounded sysfs writer. Invalid requests return false
  without acquiring a child. Planner outputs, valid relative paths, command bytes
  and cleanup retain their contracts (ADR 0298). `UserSliceMemswWriteSafetyTest`
  covers the boundary with intercepted process operations and memory streams;
  execution stays on testing machines, with changed-file PHP lint on the devbox.
- **scripts/lib/mediaStackBootRelaunch.php** rejects empty or NUL-containing boot
  observation paths before file I/O and NUL-containing observations before trimming.
  Non-finite uptime readings also retain the existing zero fallback; unusable boot
  IDs retain the empty fallback. Valid readings, default proc paths and relaunch
  policy stay unchanged (ADR 0302). `MediaStackBootInputSafetyTest` intercepts
  reads to cover these boundaries without inspecting the host; execution belongs
  on the testing machine, with changed-file PHP syntax checks on the devbox.
- **scripts/lib/runtime/filesystemHost.php** requires complete meminfo counter
  rows: unsigned decimal digits, an optional `kB` unit, and whitespace. Decimal,
  exponent, attached-unit and trailing-junk suffixes cannot certify a memory
  reading or replace a valid duplicate. Valid counters, unitless counts, ordering
  and missing-field fallbacks remain unchanged. `RuntimeMeminfoInputSafetyTest`
  covers malformed suffixes and valid neighbouring rows using private fixtures;
  execution stays on testing machines.
- **scripts/lib/runtime/commandProcess.php** owns terminate-then-reap failure
  cleanup through `pmssProcessTerminateAndClose()`. Command capture, account
  commands, root-stage publication, web-root migration and stdout heartbeats
  retain their live-handle guards, pipe cleanup and termination policy. Reaping
  still runs after termination faults with normal `finally` precedence; injected
  process fixtures export the real helper into their interception namespace.
- **scripts/cron/checkGui.php** retains GUI-watchdog traversal, logging and the
  CLI guard. `gui/watchdogPaths.php` owns account normalization, path/ownership
  checks and sentinel-gated reconciliation; `gui/watchdogRepair.php` owns
  directory and managed-file recovery. The original include and all public
  helpers remain available (ADR 0291).
- **scripts/cron/checkWireguard.php** retains the cron entrypoint and loads
  `wireguard/watchdog.php` for configuration preflight and service dispatch.
  `watchdogPeers.php` owns module/peer observations and reconciliation;
  `watchdogCommands.php` owns bounded commands, config staging and reporting.
  Characterization locks output, command order and failure boundaries (ADR 0290).
- **etc/skel/bin/pmssAccountAgent.php** keeps account command capture local to
  the customer tree. Pipe I/O faults attempt both pipe closes, then terminate
  and reap only the child acquired by that invocation. An already-reaped child
  is left alone; later cleanup runs even when an earlier cleanup stage throws.
  Lone throwables propagate unchanged, with normal `finally` precedence for
  combined faults. Successful command bytes, environment, merged output and
  exit-status results retain their contracts. `AccountAgentCommandCleanupSafetyTest`
  covers these paths with memory streams and fake process operations; execution
  stays on testing machines.
- **scripts/cron/rtorrentPortReservationsReconcile.php** owns the home-directory
  enumeration probe through `finally`, releasing it even when the passwd read
  throws. Existing enumeration refusals, JSON output and exit codes remain intact.
  `RtorrentReservationPreflightSafetyTest` covers cleanup and downstream ordering
  with in-memory handles; execution stays on testing machines.
- **scripts/lib/support/diagnostics.php** retains the support snapshot writer and
  original include. `identity.php` resolves account identity; `collect.php` builds
  diagnostics directly in the returned record. `mail.php` selects the transport,
  `envelope.php` owns message bytes, and `sendmail.php` and `smtp.php` own their
  transport lifetimes. SMTP commands, body and quit share one ordered exchange
  loop. Public helpers, snapshot permissions and failure behavior remain stable
  (ADR 0260).
- **scripts/lib/rtorrent/processInspection.php** retains process observations and
  one `ps` acquisition path. `processRows.php` owns PID validation and pure
  executor/state decoding; `processReadiness.php` owns startup stability, reboot
  grace and staggering. Signals and launches remain in `processLifecycle.php`.
  Existing public helpers, raw diagnostic references, command bytes and polling
  order are covered by characterization fixtures (ADR 0251).
- **scripts/lib/user/watchdogProcess.php** keeps watchdog process observations and
  guarded actions behind the existing include (ADR 0211). Option-shaped process
  selectors use the invalid-request result before pgrep runs; valid command bytes
  stay unchanged. Proc start-time reads reject NUL paths and invalid PIDs, and
  unrepresentable kernel fields or timestamp sums retain the inode-time fallback.
  Lock acquisition closes its stream if child-descriptor export throws, then
  propagates the original throwable. Successful exports still transfer the open
  lock to the caller; `WatchdogLockSafetyTest` covers both ownership paths.
- **scripts/lib/systemStats.php** retains host snapshot collection, while
  `systemStats/observe.php` owns host probes (including the `/home` journal read; pure jbd2 parsing is `systemStats/jbd2.php`, ADR 0253) and `systemStats/log.php` owns
  legacy line rendering and checked append. `diskIostat/observe.php` appends
  supplemental observations to the pure iostat fields; its facade retains
  checked publication and one runner invocation path (ADRs 0162 and 0226).
- **scripts/lib/userTransfer/** keeps metadata interpretation behind the existing
  transfer includes. `sessionBencode.php` owns pure session decoding,
  `qbittorrentCategoryPreservation.php` owns category probes and preservation
  ordering, and `homeStats.php` owns aggregate probes and result interpretation.
  Inode-safe session mutation, category JSON publication, ownership checks and
  advisory execution stay with their existing controllers (ADR 0223).
- **scripts/lib/update/runtime/profile.php** retains the profiling include and
  shared command/status row capture. `profileCallables.php` owns PHP invocation
  and classified exceptions; `profileSummary.php` normalizes and counts in one
  pass before sorting, logging and guarded report publication. Shell execution
  stays in `commands.php`, with no reverse dependency from capture (ADR 0217).
- **scripts/lib/rtorrent/watchdogProcessFlow.php** retains the transition include.
  `watchdogMissing.php` owns missing-process recovery, `watchdogEscalation.php`
  owns escalation markers and bounded retries, and `watchdogScgi.php` owns live
  SCGI grace, wedge decisions and recovery. Marker I/O stays in `watchdogState.php`
  and signals/launches stay in `processLifecycle.php`. Pending SCGI decisions
  and restart completion each share one path (ADR 0212).
- **scripts/lib/update/services/systemd.php** retains the service include, cron
  containment and shared restart-drop-in writer. Its `systemd/guard.php` module
  owns disabled-service policy, boot activation and bounded Exim cleanup;
  `timer.php` owns weekly TRIM, and `resolver.php` owns failed-Unbound diagnosis
  and recovery. Includes only define helpers; callers retain execution order
  and the existing fail-soft command path (ADR 0210).
- **scripts/lib/lighttpd/watchdogSocketProbe.php** retains the socket-health include
  and connection API. `watchdogSocketListeners.php` owns live listener snapshots
  and restart verification; `watchdogSocketState.php` owns consecutive-failure
  markers. Both probe consumers use `watchdogSocketRetry.php` for timing, and
  `watchdogSocketHealth.php` supplies the cron's socket restart decision (ADR 0209).
- **etc/skel/www/welcome.php** keeps customer app presentation in one local catalog
  and tile projection. **userMediaStackPanel.php** projects installed-state
  observations after its existing reads. Both keep their delivery boundaries,
  output contracts and action policies (ADR 0203).
- **etc/skel/www/appsRuntime.php** and **userMediaStackPanel.php** close their
  log-tail streams in `finally`, including when seek or read throws. Existing
  paths, limits, fallback results, whitespace and HTML escaping stay intact.
  `CustomerPanelLogTailCleanupSafetyTest` injects I/O faults with memory streams
  and checks original throwables and cleanup order on the testing machine.
- **etc/skel/www/scriptsIncFiles.php** preflights complete local helper groups before loading them in order.
  Statistics, storage notices and memory status share this loader; incomplete deliveries retain their unavailable fallbacks.
  Facades paired with an older Files part preflight every local sibling before
  using its checked single-file API (#1254). Missing, linked or unreadable siblings
  still leave the whole group unavailable; no new delivered dependency is required
  (ADR 0322).
- **etc/skel/www/configureTorrentClientsVersion.php** owns switch-lock acquisition
  until a successful return transfers the stream to its caller. A thrown `flock()`
  closes the stream; busy locks still close before the unchanged refusal is built.
  `TorrentClientsSwitchLockTest` covers retained ownership, acquisition and close
  faults, throwable precedence and refusal payloads with memory streams. Execution
  verification stays on testing machines.
- **scripts/lib/agentDiagnostics.php** – Stable diagnostic CLI and text renderer.
  `agentDiagnostics/catalog.php` defines ordered probes, `command.php` owns checked
  PHP-script execution, and `collect.php` acquires and projects recursive results.
  Root/user guards and existing payload/error shapes remain at their original
  boundaries (ADR 0193).
  Script execution rejects NUL-containing paths before trimming and arguments
  before shell quoting, returning an rc-1 result without echoing malformed bytes.
  Valid path whitespace, scalar argv conversion and command results are preserved.
- **scripts/util/userDocker.php** – Stable rootless-Docker CLI wrapper.
  `user/docker/cli.php` validates requests and dispatches to daemon start, checked
  shutdown, status, and transferred-container handlers. `command.php` preserves
  account-shell execution; `process.php` owns process observations and UID-checked
  signals. Shutdown scans fail at one boundary before restart may proceed
  (ADR 0154); the existing non-systemd launch and account policies remain intact.
- **scripts/restartUser.php** – Root-only, one-account restart entry point for
  billing. It holds the recreate/restart locks, signals only that account's
  processes as the account, and starts rTorrent, marked media-stack apps, then
  lighttpd. Its root-side media-stack bridge loads the root-owned skeleton
  command helper; customer PHP loads the per-user copy (ADR 0086).
  Lock acquisition closes the first stream if resolving or acquiring the second
  lock throws. Successful calls still transfer both streams to the caller;
  ordinary refusals keep their reasons and lock order. Memory-only fault cases
  in `RestartUserLockCleanupSafetyTest` run on the testing machine.
- **scripts/lib/lighttpd/accountPath.php** – `pmssAccountPathRun()` checks paths
  against an existing account home and launches account-owned file work as that
  account. `pmssReplaceAccountFile()` in the adjacent managed writer streams
  content to an atomic replacement. Root-owned managed entries use a private
  stage beside the home and one publish rename while keeping their ownership
  class. Small account-owned directory creation
  and file-mode repairs run as the account, with managed repair for older owners.
  The shared `userFileWrite.php` facade loads separate path, handle, metadata,
  replacement/append, account-execution, and serialized-target modules (ADR 0164).
- **scripts/util/lighttpdSymlinkRepair.php** – Explicit root-only repair for stale
  account lighttpd upload and log symlinks. It requires a healthy live quota
  result, replaces only those links under the account identity, audits each
  change, and then invokes the existing lighttpd starter.
- **scripts/lib/runtime/commands.php** – Stable command facade. `commandProcess.php`
  owns launch/retry/reaping for both I/O modes; `commandPipes.php` drains output,
  `commandTimeout.php` retains ADR 0035 deadlines and group signals,
  `commandEnvironment.php` builds shell environments, and `commandDiagnostics.php`
  reports fork exhaustion. Only piped commands receive process-group wrapping;
  inherited terminals keep their foreground group and empty capture buffers.
  Fork diagnostics close their process-scan directory even when enumeration
  throws, preserving the original exception and normal diagnostic output.
  Kernel reads reject NUL bytes before trimming and use the existing unavailable
  fields or omitted sections. Valid diagnostic bytes and read limits stay intact;
  `RuntimeForkDiagnosticsInputSafetyTest` covers these boundaries without host probes.
- **scripts/lib/runtime/environment.php** – Command lookup rejects NUL bytes before
  trimming binary names or raw lookup output; ioping probes reject NUL targets before shell quoting.
  These boundaries retain their empty-string/null failure results and preserve
  ordinary whitespace normalization and valid probe arguments.
  This stable include owns environment defaults and loads pure numeric values,
  config rows, executable lookup, shell construction and latency observations
  from focused runtime modules. Command capture shares one status-polling loop
  while retaining each I/O mode's deadline and failed-status policy (ADR 0220).
- **scripts/lib/runtime/filesystem.php** – Stable include for path/inode primitives,
  temporary workspaces, stream writes, file reads, and host values. Its sibling
  modules keep those responsibilities separate. Bounded and unbounded readers
  share one checked-open lifetime and the existing inode matcher (ADR 0167).
  Contents and opened-inode metadata are returned only after successful stream
  closure; a failed close retains the null read-failure result and null metadata.
  Read and close throwables retain PHP's `finally` precedence. Valid bytes,
  bounded owner/size checks and unbounded pseudo-file support stay unchanged.
  `RuntimeFileReadCompletionSafetyTest` covers closure faults with memory streams;
  execution stays on testing machines, with changed-file syntax checks on the devbox.
- **scripts/lib/log.php** and **lighttpd/watchdogNginxLogReader.php** snapshot regular
  logs before non-blocking acquisition and verify the opened device/inode before
  reading. Failed snapshots, opens and inode checks retain their false/empty
  results without invoking JSON handlers or publishing nginx recovery state.
  Hard-linked logs remain supported; acquired streams close on refusals and
  throwables. `LogReaderAcquisitionSafetyTest` covers these boundaries on the
  testing machine alongside the existing decoding and cursor fixtures.
- **scripts/lib/update/environment.php** – dpkg/apt guards plus helper to apply release-specific package selections.
- **scripts/lib/update/filesystem.php** – warning-only filesystem preflights, including `/home` inode density detection for media-stack-heavy hosts.
- **scripts/lib/update/arrRootExecutionBlock.php** – occupies `/root/.config/<App>` with a regular file so a root launch cannot create its data directory and aborts before binding; leftover config directories are moved aside, never deleted. Root has no legitimate Servarr instance, and `/opt` stays fully usable by customers (ADR 0034). Backstopped by **scripts/lib/arrRootGuard.php**, which the every-2-minute media-stack cron uses to kill root-owned `/opt/<App>/` processes and any known ARR process holding its default listener.
- **scripts/lib/update/kernelHardening.php** – module blacklist hardening that writes PMSS-owned `modprobe.d` entries and attempts runtime eviction so already-loaded modules do not silently persist. Interim blacklists such as `pmss-algif-blacklist.conf` are reverted by deleting the PMSS-owned file after patched kernels are deployed fleet-wide.
- **scripts/lib/update/repositories.php** retains repository refresh orchestration
  and the frozen MediaArea implementation. `repositoryDocker.php` and
  `repositorySonarr.php` own their respective prerequisites. The existing
  `apt.php` include dispatches release policy; `aptSources.php` owns template
  discovery, planning and backed-up publication, and `aptMaintenance.php` owns
  archived-release housekeeping. Public helpers and prerequisite order remain
  available through the original includes (ADR 0295).
- **scripts/lib/update/systemPrep.php** – Cgroups, systemd slices, base permissions, locale setup.
- **scripts/lib/update/systemPrep/systemdSlicesEnsure.php** – Shared slice setup
  controller. Sibling sizing, I/O policy, drop-in and live-refresh modules keep
  policy interpretation outside orchestration. Integer overrides share one
  table, and manager artifacts share one managed-write loop (ADR 0192).
- **scripts/lib/update/systemPrep/sysctlTuning.php** – Applies the managed sysctl baseline. `sysctlProfile.php` detects host inputs and `sysctlSettings.php` builds, filters, and renders the ordered settings; the entrypoint writes the config and hardware summary.
- **scripts/lib/update/systemPrep/dbusPolicyHardening.php** – One ordered artifact registry renders and converges the systemd1/login1 D-Bus policies and `/run/systemd/{users,sessions}` tmpfiles protections; the update flow has one installer and no per-channel implementations.
- **scripts/lib/update/services/** – Runtime templates (rc.local, systemd, sshd), legacy service disablement, mediainfo installer, security tweaks.
- **scripts/lib/update/users/** – User maintenance (context, HTTP, home maintenance, ruTorrent refresh).
  `userMaintenance.php` retains the single queue and final summary;
  `users/refreshState.php` owns resume markers, `legacyCpuQuota.php` owns the
  legacy repair and `maintenanceAccount.php` owns account convergence. Optional
  checks share ordered phase/exit-policy metadata, and summary accounting feeds
  both the return value and JSON event directly (ADR 0275).
  `filesystem.php` retains ordered skeleton delivery. Its path maintenance,
  guarded text patches and legacy panel-index repair live in `filesystemPaths.php`,
  `filesystemPatch.php` and `panelCompatibility.php`. `panelFiles.php` supplies
  the twelve panel-helper names to both updater delivery and the GUI watchdog;
  each caller retains its existing surrounding order (ADR 0244).
  Web-root migration keeps `webRoot.php` as its stable include and state dispatcher.
  `webRootAccount.php` owns account execution, `webRootMutation.php` owns inode-checked
  moves, and `webRootMigration.php` and `webRootMisfile.php` own durable moves and
  verified misfile copies. `webRootInspection.php` owns one read-only traversal
  with snapshot, link and ownership policies,
  `webRootPaths.php` owns destination conflicts, and `webRootReconcileCopy.php`
  owns skeleton traversal. The locked reconciler retains full/partial recovery
  orchestration; `webRootReconcileRestore.php` owns streamed installation and full
  publication. Staging cleanup and migration rollback each have one failure path
  (ADRs 0172 and 0319).
- **scripts/lib/user/qbittorrentBuildProbe.php** and its customer-tree copy reject
  failed stdout reads through the existing `null` result before further polling,
  then run the existing child, pipe and private-profile cleanup. Empty nonblocking
  reads remain valid; successful output, deadlines and limits retain their contracts.
  `QbittorrentBuildReadSafetyTest` covers first and partial read failures, an exited
  child, empty reads and successful captures using memory streams and fake processes.
  Both probes also reject empty or NUL-containing executable paths before quoting,
  profile allocation or process launch, returning `null`. Valid path bytes and
  command construction stay unchanged. `QbittorrentBuildInputSafetyTest` checks
  these refusals and launch-failure cleanup with intercepted profile/process I/O.
  Execution stays on testing machines; devbox checks changed-file PHP syntax only.
- **scripts/lib/user/qbittorrentWebUiAuth.php** – Opt-in qBittorrent WebUI config reconciliation. It checks the same path predicate as the writer, updates every managed key copy, and stops a matching native daemon at most once per account per day until a stopped pass applies the file. The customer panel's password-required helper lives under `etc/skel/www/`.
- **scripts/lib/user/deluge.php** and **delugeManagedConfig.php** retain provisioning
  and maintenance entry points. Template rendering and file/port publication live
  in `delugeTemplates.php` and `delugeProvisioning.php`; `delugeConfigCodec.php`
  owns the dual-JSON format and one transform path for core and hostlist updates.
  `delugeHostlist.php` retains localclient selection and metadata-preserving writes.
  Writer ownership policies and credential generation remain unchanged (ADR 0199).
- **scripts/lib/update/apps/** – Application installers (rtorrent, deluge, docker, etc.) called during phase 2. These modules perform one-time bootstrap tasks only; ongoing configuration and scheduling belong under `scripts/util` and `scripts/cron`.
- **scripts/lib/update/apps/delugePatches.php** retains the compatibility entrypoints
  and ordered candidate catalog. Four line transforms share guarded acquisition,
  unchanged-input detection and publication in `deluge/patchFile.php`;
  `delugeWebBasePatch.php` retains the venv preflight. Legacy Python 2 discovery
  and cleanup live in `deluge/legacyArtifacts.php`, loaded by the original installer.
  Patch bytes, diagnostics, candidate order and cleanup outcomes retain their
  characterization contracts (ADR 0289).
- **scripts/lib/update/apps/arr.php** – Shared ARR installer facade and app dispatch.
  `arr/config.php` retains input guards, `arr/release.php` owns metadata-only
  version reading and asset selection, and `arr/activation.php` runs explicit
  download/extract/replace steps inside one workspace lifetime (ADR 0190).
- **scripts/lib/update/apps/remoteBinary.php** – Pinned executable refresh facade.
  `remoteBinary/artifact.php` owns one verified-download callback lifetime;
  specialized callers retain explicit dry-run and diagnostic options.
  `remoteBinary/archive.php` owns source extraction and the shared portable
  install/marker controller used by qui, Scryer and slskd (ADR 0180).
- **scripts/lib/torrentClients/** – Versioned torrent-client store and identity predicates, converged on every normal update (ADR 0163). Account links seed the installed rTorrent catalog default only when neither a default entry nor a prior-switch `lastRun` record exists, the custom rc is absent or matches the current skeleton, and all three ruTorrent compatibility markers are present in regular non-symlink files (ADR 0146). rTorrent defaults to 0.16.25; jesec 0.9.8-r16 is offered again. qBittorrent and Deluge retain system defaults, and Transmission stays opt-in. Configure offers projection-listed qBittorrent, Deluge, Transmission and rTorrent versions through password-guarded per-account switches; the customer-side helpers repoint under a per-client lock, restart, and restore the previous selection on failure (ADRs 0106, 0119 and 0130). The qBittorrent and Deluge Apps toggles and their watchdog launches honor the selected version and the switch lock; without a selection link, the toggles retain their system launch commands. The rTorrent executor resolves a published store selection on each restart and otherwise retains its system launch command. After three consecutive runs under 20 seconds of the same store command, it keeps using the system build until the resolved command changes (ADR 0146). Its picker reuses the existing customer restart consumer.
- **scripts/lib/trackerCleaner.php** – Cron-facing facade for the tracker cleaner. The `trackerCleaner/` modules own selection policy, logs, guarded backups, and the bounded run separately (ADR 0076).
- **scripts/lib/user/scheduledConfigBackup.php** retains the scheduled-backup queue,
  CLI and original include. `scheduledConfigBackupCommand.php` owns customer
  command construction, bounded capture and JSON decoding;
  `scheduledConfigBackupAccount.php` owns opt-in policy and create/verify/prune
  ordering. PHP selection, include checks and capture stay directly with their
  sole consumers (ADR 0279).
- **scripts/lib/portManager.php** – Stable service-port and CLI facade. `portManager/state.php` owns assignment files and shared reservation reads; `portManager/selection.php` owns bind checks and allocation. Legacy rTorrent markers remain part of the used-port set. Allocation stops if either reservation namespace cannot be scanned completely; an absent legacy namespace remains valid.
  Both reservation namespaces share one scan/recheck/decode path. The
  `assignment.php` controller owns the preflight/lock/recheck lifetime for assign
  and release; `cli.php` owns dispatch and one failure projection. Release
  reporting stays inside the lock, while assign reports after unlocking
  (ADR 0183).
- **scripts/lib/rtorrentConfig.php** retains configuration I/O and locked SCGI
  allocation. `rtorrent/configDefaults.php` resolves sources and shallow defaults;
  `configText.php` owns parsing and exact managed-line repairs. Both migrations
  share read/compare/write handling. `rtorrent/portSources.php` decodes ownership
  behind the existing reservation facade; reconciliation unions those sets.
  Template normalization dispatches directly from the legacy catalog and shares
  one duplicate gate (ADR 0247).
- **scripts/lib/blockDeviceLeaves.php** – One bounded sysfs topology walk supplies backing leaf disks to BFQ policy and `/home` iostat selection, and visits backing nodes for cgroup storage-class detection. Callers retain their own response to incomplete topology.
- **scripts/lib/cgroup/readIopsPolicy.php** – Derived read-IOPS policy loads the runtime value helpers directly; passive ceiling computation remains in `cgroup/ioCeiling.php`.
- **scripts/lib/cgroup/dynamicIopsAllocation.php** retains cohort and quota
  allocation behind the existing policy include. `dynamicIopsPool.php` owns pure
  host-pool steering and curbing; ordered projection maps share charging, water
  filling and proposal generation. Bounded demand and its row annotations are
  built together, preserving payload order and enforcement policy (ADR 0280).
- **scripts/lib/cgroup/plan.php** – Finalizes the CLI's single plan after input
  and profile expansion: retain live properties, display, guard, build and apply
  ordered steps. `cliInput.php` owns actions and IO selectors; `ioPlan.php` owns
  ordered device/profile/latency/cost planning and policy fallback. Manager keeps
  orchestration and public sizing helpers (ADR 0175).
- **scripts/lib/nginxConfig/** – Generates the public user subdomain route and reconciles obsolete private hash routes. The template catalog contains only active and suspended public variants.
  Global `setup.php` retains ordered orchestration and the render context;
  `setupFilesystem.php`, `setupTls.php`, `setupDefaultSite.php` and
  `setupAccountUi.php` own filesystem diagnostics, existing-certificate selection,
  default-site publication and account-UI defaults. Both legacy UI defaults share
  one ordered convergence loop and one shared-page attempt (ADR 0284).
  Native Hermes uses the existing panel path via `template.nginx-user` and
  the account lighttpd relay, with no port-846 server (ADR 0125 amendment, ADR 0150).
  The account UI facade retains publication and preflight; simplified, Hermes
  and chooser routes share server framing, headers, origin checks, proxy bodies,
  offline locations and protocol fragments; policy stays explicit (ADR 0185).
- **scripts/lib/resources/log.php** – Resource-log entrypoint. `resources/counters.php` selects the hierarchy and projects billing counters; `resources/v1.php` owns the source traversal and parsers shared with performance telemetry (ADR 0161). `resources/counterState.php` owns locked delta persistence shared with traffic ingress (ADR 0078).
- **scripts/lib/traffic/report.php** – Stable traffic CLI facade. `reportModel.php` reads accepted rows and derives totals and summary counts before sorting; `reportDisplay.php` projects that model to JSON and terminal output without filesystem reads (ADR 0157).
  The shared monthly reader in `traffic/storage.php` requires array-valued raw
  windows before indexing persisted data. Malformed nested containers retain the
  null/zero unavailable-data fallbacks; numeric monthly conversions are unchanged.
- **scripts/lib/user/trafficThrottlePolicy.php** – Configuration normalization and
  the stable throttle-policy include. `trafficThrottle/tiers.php` owns tier rules;
  `plan.php` owns progressive arithmetic and one ordered plan/result path (ADR 0195).
- **scripts/lib/user/integerSetting.php** retains storage and path helpers, loading
  value policy and verified reads from `integerSettingValue.php` and
  `integerSettingRead.php`. Both readers share parsing while retaining distinct
  absent/unreadable defaults. Traffic throttle display operations retain their
  include; `trafficLimitMarkers.php` owns runtime markers and
  `trafficLimitReconcile.php` owns recovery with one assertion-age decision.
  `trafficLimitAccount.php` owns GiB CLI account resolution. Existing public
  helpers, guards, errors and retry order stay stable (ADR 0269).
- **scripts/lib/storageHealth/** – SMART/NVMe backends own device eligibility and
  bounded probes; `deviceParse.php` interprets text without I/O. `deviceMetrics.php`
  shares ordered matching, threshold selection, counter growth and entry status.
  SMART retains last-line precedence; NVMe retains first-match precedence (ADR 0173).
  Acquisition policies also differ: SMART parses combined stdout/stderr and
  annotates parsed timeout findings; NVMe returns null for failed, empty or
  NUL-containing stdout. RAID retains its array entries when bitmap inspection
  is unavailable. Keep those contracts explicit when considering shared probes.
  SMART rejects NUL-containing stdout or stderr before parsing, using its existing
  unavailable-output result. Valid text retains SMART exit-code bitmask handling,
  timeout findings and standby status. `StorageHealthSmartOutputSafetyTest` covers
  these boundaries with injected probes on the testing machine.
- **scripts/lib/storageBenchmark.php** – Stable CLI and guarded probe facade.
  `storageBenchmark/cli.php` owns option policy, `file.php` owns reservation and
  inode-checked cleanup, `device.php` owns member-device reads, and `fio.php`
  owns the fio process lifetime with one direction-based metric aggregation.
  `jobs.php` owns the ordered catalogs and shared fio/dd job execution;
  `homeDeviceInspection.php` inspects the mounted device without
  running jobs. The mounted-device controller retains its fio-unavailable
  fallback, and `report.php` owns live and saved summaries (ADRs 0178, 0189).

## Package Strategy
- Per-release bootstrap baselines live under `scripts/lib/update/dpkg/`; the installer only ensures core tools. System-level apps are built via update-step2 modules, while per-account media-stack apps stay in the skeleton/user maintenance tooling.
- Release-specific dpkg snapshots: `scripts/lib/update/dpkg/selections-debian10/11/12.txt`. Apply via `pmssApplyDpkgSelections()` once per run (update-step2 picks the codename-resolved version or logs if unavailable).
- #TODO #Debian13: Debian 13 (trixie) is experimental; add `scripts/lib/update/dpkg/selections-debian13.txt` (captured from a real host) before promoting beyond experimental.
- `pmssApplyDpkgSelections()` is the sole package-state authority during update-step2 package phase; the retired per-app package queue has been removed.
- Always recover pending dpkg configuration first, then run repository refresh +
  package installation at the start of phase 2; no other orchestration steps may
  run before package recovery completes.
- Tests that touch package logic should remain hermetic—seed inputs via temp files and environment overrides (e.g., `PMSS_OS_RELEASE_PATH`, `PMSS_APT_SOURCES_PATH`).

## Testing Layout
- Development runner: `php scripts/lib/tests/development/Runner.php`
- Production scaffolding: `php scripts/lib/tests/production/Runner.php`
- Shared helpers: `scripts/lib/tests/common/`
- Slice fixtures use the shared temporary-directory prefixes; each preparation
  still creates unique tracked directories, with explicit paths kept for migration checks.
- Process, HTTP, plugin, logging, resource, transfer and path-guard fixtures use `TestCase::pmssWriteFiles()`
  and `pmssWriteFile()` for checked publication and parent creation. Keep deliberately
  empty directories, append operations and injected runtime writes explicit.
  AI-launcher, Apps-catalog and private-UI fixtures use the same writers for
  account state, launcher copies and certificate stubs, retaining directory
  creation modes and explicit permission transitions at their callers.
  Support, configuration-permission, memory-panel and installer fixture data
  share those writers too; append operations and injected runtime writes stay explicit.
- Generated text and repository source contracts share `TestCase::pmssAssertStringContract()`
  for required/forbidden fragments, regexes and ordered fragments. Required and
  forbidden maps retain per-fragment diagnostic messages.
  Fragment-only repository checks call the direct batch helpers, including
  `pmssAssertRepoFileContainsAndOmitsStrings()` for forbidden diagnostic maps.
  Repository-only checks use the `pmssAssertRepoFile*` helpers for file reads and
  assertion batches; reuse returned source for later parsing, counts or ordering.
  Ordering-only checks call `pmssAssertRepoFileContainsOrderedStrings()` directly.
  Panel initialization, backup routing, console lifecycle, rTorrent stop and CI
  skip checks share these ordering assertions, retaining independent order pairs.
  Adjacent required/forbidden checks share one read; reuse that source for later
  regexes or checks with distinct diagnostic prefixes, preserving assertion order.
- Generated CLI, service-command and torrent-client fixtures use the same batch
  assertions as source contracts; case labels stay in fragment-to-message maps.
  Keep loops that perform fixture operations or compute distinct diagnostics explicit.
  Installer, storage, live-statistics and memory-status text checks reuse these
  batches too; associative fixture values become lists before forbidden checks.
- Structured result checks share `TestCase::pmssAssertArraySubsetSame()` for
  required fields and strict values. Keep expected field order and fixture cases
  at callers; custom diagnostics, transformed values and exact-shape assertions
  remain explicit.
  Panel, worker-pool, terminal-resource, storage and runtime fixtures also batch
  sibling fields this way; nested records remain separate assertion targets.
  App profiles, launcher environments, panel sessions, fatal reports, scanner
  diagnostics and command results share these batches too, retaining strict
  nested values and keeping exact-shape and ordering checks explicit.
- CLI probes: `/scripts/util/systemTest.php`, `/scripts/util/componentStatus.php`, `/scripts/util/agentDiagnostics.php`

String-only fixture observations reuse `TestCase::pmssMakeArrayLogger()` for
diagnostics, failed paths, fetched filenames and command traces. Callers retain
record transformations and callbacks that inspect publication ordering.
Log substring assertions use `pmssAssertMessagesContain()` for shared search and
failure reporting, retaining caller-owned fragments and custom diagnostics.
Ordered cgroup apply steps reuse `pmssMakeStepRunner()`. Fixed statistics, RPC,
socket, client-link, hardware, quota, account-lookup and incomplete-write probes
use `pmssMakeCallbacks()` with caller-owned results, including console process
snapshots and discarded repository, installer and watchdog logs.
Argument-dependent, stateful and throwing callbacks remain explicit.

Test fixtures call constructors and shared helpers directly when a private
adapter adds no policy. Single-use setup and assertions stay with their scenario,
preserving fixture bytes, strict comparisons, stderr redirection and sandbox skips.
Panel-index source copies use private constants; mount paths, Python stub PATHs
and preflight log captures compose the shared fixture helpers at their scenarios.
Tar headers stay with archive assembly; traffic CLI case tables and panel-asset
collection stay with their sole scenario, retaining checks and traversal order.
Stub command paths use `pmssWithPathPrefix()` / `pmssWithPathPrefixedEnv()` for
callback scopes, or `pmssPathPrefixedEnvironment()` with tracked overrides for
whole-test scopes. These share PATH construction and restoration, including
absent or empty prior values, without local prepend or try/finally adapters.

Development tests must avoid network/system changes; production tests and the CLI probe are intended for curated post-provision runs.

## Config Templates
- `/etc/seedbox/config/template.*` (rc.local, systemd.conf, nginx, proftpd, etc.) are copied by service helpers.
- `/etc/seedbox/config/template.sources.<suite>` defines apt sources for each distro.
- Generated configs under `/etc` must be idempotent: invoking the matching `scripts/util/*Config*.php` multiple times converges on the same safe state without accumulating duplicate directives or diverging permissions.
- `etc/skel/www` first-party files are editable normally. Bundled vendor/third-party trees remain read-only unless explicitly approved.

## Logs & Profile
- JSON Lines traversal in `log/jsonFiles.php` reports completion only after EOF
  and successful stream closure. Read, decoder and handler throwables still close
  the reader; close throwables retain PHP's `finally` precedence. Record order,
  skipped malformed lines and the collecting wrappers' partial results remain
  unchanged. `LogReaderCompletionSafetyTest` covers these boundaries with memory
  streams; execution belongs on the testing machine.
- Diagnostic line compaction and suffixed byte excerpts share `log.php` helpers.
  Callers retain their control-byte policy, cutoff and truncation suffix: snapshot
  fields truncate silently, scheduled backups use `...`, and storage benchmark
  output uses ` ...`. Scalar logging retains its broader control-byte cleanup.
- Plain log: `/var/log/pmss-update.log`
- JSON events: `/var/log/pmss-update.jsonl`
- Optional profile: `PMSS_PROFILE_OUTPUT` or `<json>.profile.json`
- `runStep()` in `install.sh` and update modules logs every command; respect fail-soft principle.
