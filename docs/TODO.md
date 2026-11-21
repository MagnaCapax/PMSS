PMSS TODOs and Near‑Term Improvements

This document tracks small, stability‑focused improvements and medium‑term refactors. Items are grouped to align with our doctrine (KISS, DRY, Pit of Success) and are intentionally brief. Each entry explains the motivation so prioritization is easier.

## Tracking

Active work items are tracked in GitHub issues to avoid duplication between this file and the issue tracker. Keep this document as a short index rather than a second backlog.

- #112: Correlation / run ID (end-to-end tracing)
- #113: Repository signing hygiene (`signed-by=` scoping without deb822 migration)
- #114: Config backups with TTL (sshd/nginx/proftpd)
- #115: Test hooks and hermeticity (path overrides + patterns)
- #116: Defensive directory creation (idempotent mkdir+perms in user maintenance)
- #121: Cgroup policy extension backlog (remaining scheduler-aware IO auto-policy, burst allowances, and policy-level network shaping hints) — see `docs/cgroup.md`
- #111: Debian 13 (trixie) validation roadmap (experimental → supported) — see `docs/dpkg-baseline.md`

## Code Quality Audits

- [ ] Resolve storage-benchmark result classification before unifying live and
  saved report flows. `storageBenchmarkRunFileTests()` in
  `scripts/lib/storageBenchmark/file.php` persists the mount device in `device`,
  but `storageBenchmarkShowLast()` in `storageBenchmark/report.php` excludes
  every row with a printable nonzero `device` from its file table. Its device
  table prints only dd, device-ioping and dev-randread tests, so those file
  metrics disappear there too. Existing file-report fixtures omit the device;
  `StorageBenchmarkShowLastTest::testShowLastCharacterizesFileRowsWithMountDeviceMetadata()`
  now locks both shapes, including empty and literal-zero compatibility.
  Correcting the discriminator changes stdout and requires a separate behavior
  change under ADRs 0178/0189; this is a prerequisite, not a refactor to conceal.
- [ ] Establish the compatibility scope of `PmssUserStatsProcessor::spawnWorkers()`
  before removing detached processing. `ResourceStatsProcessor` and
  `TrafficStatsProcessor` select inline processing, while their shared base still
  defaults to detached workers and exposes worker spawning publicly. Characterize
  both modes before deciding whether that branch can be retired; two inline
  subclasses alone do not establish that the inherited contract is unused.
- [ ] Audit string matching patterns across codebase for truncation bugs and typos. Found `.rtorrentexecut` typo (missing 'e') in process.php; similar issues may exist in other `strpos()`, `preg_match()`, or filename pattern checks. Check process name matching, config file patterns, and path validations.
- [ ] Before extracting new required helpers from `etc/skel/www/welcome.php` or
  `userMediaStackPanel.php`, establish delivery closure against the external
  retrieval allowlist and coordinate dependency ordering under
  [ADR 0022](adr/0022-guiv-delivered-customer-files-must-be-self-contained.md).
  The local skeleton manifest alone cannot verify that external prerequisite.
  This also blocks splitting `scriptsIncActionJs.php`: [ADR 0099](adr/0099-scriptsinc-split-into-guiv-delivered-parts.md)
  requires new part names in both the external allowlist and sync list before
  loader dependencies change. `pmssUserPanelHelperFiles()` cannot prove either.
  `CustomerActionScriptCharacterizationTest` freezes the generated browser body
  for a future coordinated extraction; request maps remain covered separately.
- [ ] Before sharing child-process cleanup across `etc/skel/bin/dockerLsioSeedHttp.php`,
  `etc/skel/bin/pmssAccountAgent.php` and `etc/skel/www/toggleAppRuntime.php`,
  verify a common helper's delivery to both account trees. Preserve each caller's
  pipe count, still-owned-child check, termination/reaping order and throwable
  precedence. The candidates are `pmssDockerLsioSeedCapture()` and `pmssAgentRun()`
  (two pipes), plus `pmssToggleAppQuiAccountCommand()` (three pipes).
  `pmssHermesStart()` in `etc/skel/bin/pmssHermesRuntime.php` also closes three
  pipes but reaps without termination; a common helper must not add that step.
  The skeleton manifest lists the bin files but cannot prove the external
  panel retrieval allowlist or dependency ordering. See
  [ADR 0022](adr/0022-guiv-delivered-customer-files-must-be-self-contained.md).
- [ ] Establish helper-version delivery ordering before sharing customer
  torrent-client lock release. The 2026-11-17 tracked-tree duplication scan also
  found the same resource guard and unlock-in-try/close-in-finally sequence in
  `pmssConfigureTorrentClientSwitchApply()`, `pmssConfigureTorrentClientOtherSwitch()`,
  `pmssTorrentClientResolverLaunch()` and `pmssCustomerTorrentClientToggleStart()`.
  Their files are respectively `configureTorrentClients.php`,
  `configureTorrentClientsSwitch.php`, `qbittorrentResolverLaunch.php` and
  `scriptsIncAppProcess.php` under `etc/skel/www/`. The last explicitly supports
  mixed deliveries; `scriptsInc.php` also tolerates absent parts. An existing
  helper filename alone does not prove that a new release symbol arrives before
  its callers. Preserve held-lock ownership and close-after-unlock-fault behavior;
  resolve the [ADR 0022](adr/0022-guiv-delivered-customer-files-must-be-self-contained.md)
  delivery prerequisite before replacing these four copies.

## Recently Completed

- Single-run locking for updates is implemented (lock + JSON events) in `ab31f8b`.
- Atomic staging swaps for `/scripts` and `/etc/seedbox` are implemented in `ab31f8b`.
- Phase 2 preflight checks are implemented in `scripts/lib/update/filesystem.php` (expanded as of `ab31f8b`).
- Per-user action logs via `pmssUserLog()` are implemented and used in cron/util flows (see `2923d6c`).
- #117 strict error handling policy is codified via ADR 0014 and classified update-step2 wrappers.
