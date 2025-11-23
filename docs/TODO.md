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

## Cgroup & Resource Control (Architecture & Findings)

### Current State
- **Cgroup Mode:** Debian 10/11 (legacy/hybrid v1), Debian 12 (v2). We currently default to legacy hierarchy (`systemd.unified_cgroup_hierarchy=0`) even on newer distros to support Rootless Docker.
- **Per-User Limits:** Implemented via `systemd` slices (`user-UID.slice`).
  - **Memory:** Enforced via `MemoryHigh` (throttle) and `MemoryMax` (kill).
  - **CPU/IO Weight:** Enforced via `CPUWeight` and `IOWeight` (mapped to `BlockIOWeight` on v1).
  - **Algorithm:** Weights are derived from allocated RAM (`8 * sqrt(RAM)`).
- **Intra-User Prioritization:** Currently handled by `cron` jobs (`/etc/seedbox/config/root.cron`) executing `ionice` and `renice` on specific process names (`rtorrent`, `deluged`, `php`, etc.).
  - `rtorrent`: ionice -c2 -n3
  - `deluged`: ionice -c2 -n3
  - `php` (web): ionice -c2 -n6 (lower priority than torrents? *Wait, higher number = lower priority*)
  - *Correction:* `ionice` class 2 (Best Effort), priority 0-7 (0=highest, 7=lowest). So `rtorrent` (n3) has higher I/O priority than `php` (n6). This seems inverted for a responsive GUI.

### Findings & Analysis
1.  **Process Model:**
    - `rtorrent` is launched inside a `screen` session via `scripts/startRtorrent`. It is NOT a systemd service.
    - `lighttpd` is launched via `scripts/startLighttpd` (direct execution, monitored by `checkLighttpdInstances.php`).
    - **Implication:** We cannot use per-service systemd resource controls (`CPUWeight` in `.service` files) because these are not systemd services. They run as simple processes inside the user's slice.

2.  **Intra-User Prioritization Strategy:**
    - Since processes share the `user-UID.slice`, they compete for the *slice's* total resources.
    - Prioritization *between* them must happen at the process level (nice/ionice).
    - The current `cron`-based approach is low-tech but effective for this architecture.
    - **Risk of change:** Converting to `systemd --user` services is a major architectural shift (High Complexity/High Risk) involving user session management, lingering, and changing how users interact with `screen` sessions.

3.  **Docker Rootless Compatibility:**
    - We force `systemd.unified_cgroup_hierarchy=0` (v1) to support Docker Rootless on current kernels.
    - This constraints us to v1 controllers (`blkio`, `memory`, `cpu`).
    - Our new `userCgroup.php` correctly maps `IOWeight` to `BlockIOWeight` for v1, ensuring per-user fairness works.

### Recommendations (Future Work)
1.  **Tune `ionice` Priorities:**
    - Review `root.cron`. Currently `rtorrent` (n3) beats `php` (n6). If web UI responsiveness is sluggish, `php` should be n2 or n3, and `rtorrent` n4 or n5.
    - Verify `renice` values. `php` is not reniced in cron, but `ffmpeg` is (+18).

2.  **Migrate to Systemd User Services (Long Term):**
    - *Benefit:* Proper Cgroup v2 delegation, restart logic, logs.
    - *Path:*
        - Create `~/.config/systemd/user/rtorrent.service`.
        - Use `Type=forking` with `screen -dmS`.
        - Use `Slice=app.slice` (sub-slice) to separate apps from session/web.
        - *Blocker:* Requires robust testing of screen session persistence and user interactivity.

3.  **Storage I/O Failsafes:**
    - Continue with the plan to add optional arguments to `userConfig.php` for manual I/O throttling (IOPS/BPS) as a safety valve for "noisy neighbor" tenants on HDD raids.

### Decision
**Do not refactor `check*Instances.php` or `start*` scripts to systemd yet.** The risk/reward ratio is poor given the stability of the current cron+screen model. Focus on refining the *weights* (done) and potentially tweaking the `ionice` values in cron if specific performance issues arise.