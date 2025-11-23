# Cgroup & Resource Control TODOs

## Current State
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

## Findings & Analysis
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

## Recommendations (Future Work)
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

## Decision
**Do not refactor `check*Instances.php` or `start*` scripts to systemd yet.** The risk/reward ratio is poor given the stability of the current cron+screen model. Focus on refining the *weights* (done) and potentially tweaking the `ionice` values in cron if specific performance issues arise.
