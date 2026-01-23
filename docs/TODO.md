PMSS TODOs and Near‑Term Improvements

This document tracks small, stability‑focused improvements and medium‑term refactors. Items are grouped to align with our doctrine (KISS, DRY, Pit of Success) and are intentionally brief. Each entry explains the motivation so prioritization is easier.

- Defensive directory creation
  - Ensure parent directories exist before writing files into user homes (mkdir -p with consistent permissions) and log when created. Avoids intermittent failures when users remove directories (e.g., .lighttpd/upload) and improves idempotence.

- Strict error handling policy
  - Classify steps as must‑succeed vs. soft‑fail; fail fast after the package phase on must‑succeed steps (repository templating, core service configs). Soft‑fail remains for optional features. Add minimal ADR to codify the policy and annotate call sites progressively.

- Test hooks and hermeticity
  - Expand environment overrides to direct helpers to temp paths (e.g., PMSS_CONFIG_DIR, PMSS_RUNTIME_DIR, PMSS_SKEL_DIR, PMSS_OS_RELEASE_PATH) so dev tests cover more flows without touching the system. Add examples to scripts/lib/tests/common.

- Debian 13 (trixie) experimental support
  - #TODO #Debian13: capture `scripts/lib/update/dpkg/selections-debian13.txt` from a converged trixie host and verify baseline replay on a second host before promoting support status.
  - #TODO #Debian13: audit Debian-version gating in installers; confirm trixie behavior and adjust only if needed:
    - `scripts/lib/update/apps/deluge.php` (Debian 10 pip path vs apt; verify apt packages on 13).
    - `scripts/lib/update/apps/docker.php` (rootless helper path for <12; verify 13 package set/assumptions).
    - `scripts/lib/update/apps/packages/system.php` (package lists currently documented for 11/12).
    - `scripts/lib/update/apps/packages.php` (wireguard dkms gating, docker package set).
    - `scripts/lib/update/apps/rtorrent.php` (debian_version[0] logic; confirm 13 target versions).
    - `scripts/lib/update/apps/openvpn.php` / `scripts/lib/update/apps/vnstat.php` (legacy Debian 8 branches; ensure no 13 assumptions).
    - `scripts/lib/update/apps/pyload.php` / `scripts/lib/update/apps/packages/python.php` (package availability on 13).

- Repository signing hygiene
  - #TODO #Security: audit external repository entries for `signed-by=` adoption to gain key-scoping security without a deb822 migration (Docker, MediaArea, Sonarr, etc.).

- Single‑run locking
  - Add a global lock (flock on /var/run/pmss-update.lock) to prevent overlapping update.php / update-step2.php executions. Emit JSON events for lock wait/acquire/release. This prevents concurrent dpkg and service races.

- Preflight checks (explicit)
  - Implement a preflight step prior to phase 2: disk space threshold on /, apt cache availability, dpkg lock/audit clean, and basic network reachability to mirrors. Emit structured outcomes (preflight_ok/error) to JSON logs and abort on critical failures.

- Correlation / run ID
  - Generate a correlationId in phase 1 and thread it through logs and JSON events in both phases. Store in /var/run/pmss/correlation so child processes can attach it. Simplifies cross‑phase tracing and incident timelines.

- Config backups with TTL
  - Standardize pre-change backups for critical services (sshd, nginx, proftpd) and prune by age/version (TTL). Some ad‑hoc backups exist; consolidate naming, include version/correlationId, and add a simple retention policy.

- Profile completeness
  - Ensure every sub-step is wrapped with runStep()/pmssRecordProfile (apps, user steps, network). Where practical, record per-step counts (e.g., files changed) to aid diagnosis. This complements the existing profiling TODO in docs/update.md.

- Per-user action logs (observability)
  - Introduce a simple, dependency-free helper for per-user logs under `/var/log/pmss/user-<username>.log` so root/cron actions taken on behalf of users are traceable.
  - Adopt the helper progressively in cron scripts (start/stop daemons, quota updates, web restarts) to append concise, timestamped lines; avoid excessive noise.
  - Wire boot-time actions to per-user logs (e.g., rc.local starting `user@UID.service`). Add correlation IDs later and optional JSON lines if needed.
  - Unify existing ad-hoc logs (e.g., pmss-update-user-<username>.log) under the helper over time to reduce duplication.
  - Add a lightweight logrotate policy for `/var/log/pmss/user-*.log` with sane retention.

- Consistent Command Execution and Error Handling
  - Standardize on a single, consistent approach to executing shell commands and handling errors. The `runStep()` function is a good candidate for this.

- Robust Argument Handling and Input Validation
  - Adopt a consistent approach to argument parsing, such as using `getopt()`.
  - Implement robust input validation in all scripts.

- Refactor Hardcoded Values
  - Move configurable values to configuration files or command-line arguments.
  - Generalize the approach taken with `etc/seedbox/config/apps.php` for other hardcoded values.

- Cgroup & Resource Control (Architecture & Findings)
  - See [docs/analysis/cgroup-architecture.md](analysis/cgroup-architecture.md) for deep dive.

- Atomic updates for /scripts and /etc/seedbox
  - Move the update process toward atomic replacement of the `/scripts` and `/etc/seedbox` trees (e.g., stage into a versioned directory then swap via rename) so cron and long-running jobs never see partially-removed libraries. The 2025 `/home` wipe incident showed how mid-update removals of `/scripts/lib/user/*.php` turned `listUsers.php` output into fatal/error text, which legacy consumers like `updateQuotas.php` treated as usernames and fed directly into destructive shell commands. Atomic updates plus strict output validation reduce the odds of similar cascades.
- Single per-user loop in update-step2
  - Refactor per-user maintenance so all user work (permissions, linger/docker kick, crontab refresh, etc.) happens inside one validated loop. Today multiple helpers (pmssUpdateAllUsers, pmssEnsureLingerAndDockerAllUsers, pmssRestoreUserCrontabs) each traverse user lists separately, increasing runtime and risk of drift. Fold the extra sweeps into the main loop and simplify orchestration.
