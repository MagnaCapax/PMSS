# ADR 0027: Rootless Docker decoupled from the per-user systemd manager

Date: 2026-07-02
Category: architecture

## Status
Accepted. Complements ADR-0019 (production cgroup v1 pin). ADR-0019 documents *why the fleet is pinned to v1*; this ADR removes the single dependency that made v1 load-bearing for correctness, so a future v2 migration no longer breaks rootless Docker provisioning.

## Context

ADR-0019 records that the fleet is pinned to cgroup v1 because cgroup v2 + `hidepid=2` breaks the per-user systemd manager (`user@UID.service`) via systemd issue 12955 (the manager, running as the user, cannot read root-owned `/proc` to enumerate controllers under `hidepid=2`). That breakage was believed to compromise tenant isolation and rootless Docker.

Live investigation on a v2 host (2026-07-02) established that the per-user manager is **not required by PMSS's runtime model**:

- Seedbox daemons (rtorrent, lighttpd) are launched by root cron into `user-UID.slice` (`serviceLaunch.php`) — never by the per-user manager.
- Rootless Docker's daemon is launched via `nohup dockerd-rootless.sh` (`userDocker.php`), which deliberately avoids `systemctl --user`. It was observed running normally under managers in `failed` state.
- Per-user resource limits and PSI/`io.stat` metering live on `user-UID.slice` (`cgroup/Manager.php` `systemctl set-property`), independent of the manager — verified live (enforcement + metering present under a failed manager).
- `pam_systemd` is `session optional` in every PAM stack, so login/SSH/FTP cannot fail when the manager is absent — verified live (users logged in on a host with the manager not running).

The **only** hard dependency on a working per-user manager was rootless-Docker *setup*: PMSS ran the setuptool via `machinectl shell user@ …`, which requires a live per-user manager. On a v2 host that manager fails, so new Docker provisioning would break — the actual blocker to v2 adoption.

## Options Considered

- **Option A — global `hidepid=2` bypass (`gid=` exemption)** to make the manager start. Reject: a proc-group member gains full cross-tenant `/proc` visibility — a privacy hole (MISSION cardinal value: liberty/privacy). Empirically confirmed to expose all processes.
- **Option B — per-user PID namespace (CageFS-style)** so the manager runs isolated. Reject (for now): `PrivatePIDs=` is systemd 257 + non-forking-only (not viable for the forking session manager, not on our systemd 247), and a persistent per-user PID namespace requires per-user-container infrastructure. Too much effort; the shared-hosting incumbent that uses this runs no per-tenant manager anyway.
- **Option C — decouple rootless-Docker setup from the manager** (this ADR). Run the setuptool as the user (via `su` + explicit `XDG_RUNTIME_DIR`) instead of `machinectl shell user@`. The manager becomes unnecessary for correctness; where it still auto-starts and fails under v2, that failure is cosmetic (login/metering/Docker all unaffected). Selected.

## Decision

- Rootless-Docker **setup** is manager-independent: the setuptool runs as the user via `pmssBuildUserShellCommand` (`su USER -c`) with `XDG_RUNTIME_DIR=/run/user/$(id -u)` set explicitly, instead of `machinectl shell user@ …`.
  - `scripts/lib/user/userConfigRuntime.php` — provisioning path.
  - `scripts/lib/update/users/docker.php` — maintenance/reinstall path (also converged off the upstream network installer onto the local setuptool for reproducibility + sovereignty).
- `loginctl enable-linger` is retained for Docker users: logind creates the persistent `/run/user/UID` the Docker socket needs, independent of whether the manager starts.
- The explicit `systemctl start user@UID.service` calls are **retained** (not removed): on v1 they help the runtime self-heal path create `/run/user/UID` promptly; on v2 they fail harmlessly (the runtime dir comes from linger, the daemon from nohup). Removing them was judged a needless v1 regression risk for a cosmetic v2 gain.
- The host-environment advisory no longer suggests remounting `/proc` without `hidepid` on v2 — that would remove cross-tenant isolation. It now states the manager failure is expected and non-fatal.
- **Not done (deliberately):** fleet-wide masking of `user@.service`. Login cannot break without the manager (`pam_systemd optional`), so the failed-unit-on-login under v2 is cosmetic only; masking the template would also mask root's `user@0.service` — fleet risk for a cosmetic gain. If cosmetic cleanup is ever wanted, do it per-customer-UID, not the template.

## Consequences

- A future cgroup v2 migration no longer breaks rootless-Docker provisioning; per-user PSI + `io.stat` metering become available without weakening `hidepid=2`.
- Backward-compatible on v1: existing Docker users are unaffected (runtime already uses nohup); only the setup invocation method changes. Existing hosts self-migrate through the normal `update.php` convergence.
- Residual (tracked, not blocking): the `docker.php` reinstall path remains unit-centric in places; a fuller cleanup of unit-based logic is future work. Acceptance tests for a live host: setuptool `install` completes via the run-as-user path; an existing Docker account is unaffected.

## References

- ADR-0019 (production cgroup v1 pin).
- systemd issue 12955 (per-user manager fails under cgroup v2 + `hidepid=2`).
- Investigation + decision record: GitHub MagnaCapax/PMSS#690, #648.
