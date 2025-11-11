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

