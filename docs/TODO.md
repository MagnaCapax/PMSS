PMSS TODOs and Near‑Term Improvements

This document tracks small, stability‑focused improvements and medium‑term refactors. Items are grouped to align with our doctrine (KISS, DRY, Pit of Success) and are intentionally brief. Each entry explains the motivation so prioritization is easier.

- Defensive directory creation
  - Ensure parent directories exist before writing files into user homes (mkdir -p with consistent permissions) and log when created. Avoids intermittent failures when users remove directories (e.g., .lighttpd/upload) and improves idempotence.

- Strict error handling policy
  - Classify steps as must‑succeed vs. soft‑fail; fail fast after the package phase on must‑succeed steps (repository templating, core service configs). Soft‑fail remains for optional features. Add minimal ADR to codify the policy and annotate call sites progressively.

- Test hooks and hermeticity
  - Expand environment overrides to direct helpers to temp paths (e.g., PMSS_CONFIG_DIR, PMSS_RUNTIME_DIR, PMSS_SKEL_DIR, PMSS_OS_RELEASE_PATH) so dev tests cover more flows without touching the system. Add examples to scripts/lib/tests/common.

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
