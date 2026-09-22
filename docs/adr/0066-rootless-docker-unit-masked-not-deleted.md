# ADR 0066: Rootless Docker per-user unit is masked, not deleted

Date: 2026-09-22
Category: architecture

## Status
**SUPERSEDED 2026-09-22 (same day) — the mask was REVERTED.** The Decision below (mask the unit)
was wrong: it broke the documented, KB-published customer start path.

## Supersession (2026-09-22)
This ADR's premise — "PMSS never uses the unit, so it is only a footgun" — is FALSE for the party
that matters: **customers use it.** The Rootless Docker KB article tells customers to start rootless
Docker with `systemctl --user start docker.service`; and GH#794 (closed/complete-verify) treated a
broken `systemctl --user` as a FAULT TO FIX (dead per-user manager, missing
`~/.config/systemd/user/`), NOT an unsupported path — the original claim here that
"rootless-via-systemctl is unsupported" mis-cited #794. The watchdog uses `nohup dockerd-rootless.sh`
for its OWN liveness path (ADR-0027), but the unit remains the **customer** path and must stay
functional. Masking it (`/dev/null` symlink) makes `systemctl --user start docker.service` fail with
"Unit is masked" — a regression against the documented customer behaviour.

**Reverted:** `pmssEnsureRootlessDockerInstalled()` no longer masks the unit; it keeps a valid unit
functional (install-marker = `is_file`), UN-MASKS any unit a prior release masked (removes the
`/dev/null` symlink so the real unit is recreated by the guarded setuptool reinstall), and retains
the stale-real-unit reinstall path. The `pmssNeutralizeUserDockerServiceUnit()` /
`pmssUserDockerServiceUnitIsNeutralised()` helpers are removed. The `systemctl --user restart`
collision (the real #873 footgun) is handled by DOCUMENTATION (`docs/docker-help.md`,
`docs/linuxserver.io.md` steer to `pkill` recovery), not by disabling the unit — the "or document
that it must not be used" alternative in #873's own title. The higher-leverage root cause (why users
reach for `systemctl` — the watchdog process-existence-not-serving liveness gap) remains tracked in
#718/#872/#913/#875.

The original (now-reverted) decision is retained below as the historical record.

---

Original status: Accepted. Companion to ADR-0027 (rootless Docker decoupled from the per-user systemd manager).

## Context
`dockerd-rootless-setuptool.sh install` creates a per-user `~/.config/systemd/user/docker.service`
unit. PMSS never starts the daemon from it — the watchdog launches `nohup dockerd-rootless.sh`
directly (ADR-0027). The unit is therefore a footgun: a user whose daemon is wedged runs the
obvious `systemctl --user restart docker`, which collides on the socket with the watchdog's running
daemon; if they then kill the watchdog daemon to clear it, they get a dual-manager conflict.

Removing the unit outright (the first-instinct fix) is WRONG: `scripts/lib/update/users/docker.php`
`pmssEnsureRootlessDockerInstalled()` uses `is_file(<unit>)` as its "is rootless Docker installed
for this user?" idempotency marker. A permanently-deleted unit reads as "not installed", so the
setuptool reinstalls on every maintenance cycle — a fleet-wide reinstall loop. The unit is
load-bearing as the install-marker.

## Decision
Neutralise the unit by **masking** it — replace it with a symlink to `/dev/null`
(`pmssNeutralizeUserDockerServiceUnit()` in `scripts/lib/user/rootlessDockerConfig.php`), guarded
to only ever touch a path whose parent resolves under the user's home.

- `systemctl --user start|restart docker` then fails with a clear "Unit docker.service is masked"
  instead of colliding with the watchdog daemon.
- The install-marker in `pmssEnsureRootlessDockerInstalled()` recognises a masked unit as installed
  via `is_link(<unit>) && readlink(<unit>) === '/dev/null'`, so the setuptool is never re-run for a
  neutralised user — no reinstall loop.
- Existing users (a real unit) are detected as installed via the retained `is_file` branch and
  neutralised **in place** on their next maintenance run, with NO setuptool re-run — so there is no
  fleet-wide reinstall wave. New users' freshly-created unit is masked immediately after the
  setuptool install.
- The stale-real-unit reinstall path (a real unit whose ExecStart binary is missing) is retained
  for the `is_file` branch only; a masked symlink can never be "stale".

## Options Considered
- **Delete the unit** — breaks the install-marker (reinstall loop). Rejected.
- **Mask the unit + `is_link` marker** (this ADR) — removes the footgun, keeps the marker, no
  reinstall wave. Selected.
- **Change the install-marker to a non-unit sentinel (subuid, etc.)** — larger change, and would
  trigger a one-time fleet reinstall wave for existing users (no sentinel yet). Rejected as
  disproportionate.

## Consequences
- A customer's DIY-customised `docker.service` is not preserved (rootless-via-systemctl is an
  unsupported pattern on PMSS-managed accounts). Same outcome a delete would have had.
- The masked symlink created during maintenance is root-owned (the maintenance runs as root);
  systemd reads it as masked regardless of owner.
- The higher-leverage root cause — why users reach for `systemctl` at all — is the watchdog
  liveness gap (it checks PID existence, not that the daemon serves). That is tracked separately
  and is out of scope for this ADR.

## References
- ADR-0027 (rootless Docker decoupled from the per-user systemd manager), ADR-0019 (cgroup v1 pin).
- GH MagnaCapax/PMSS#873 (the footgun + the install-marker discovery), #718 / #913 (watchdog
  liveness + stop-path, the separate root cause), #794 (DIY systemctl is customer-education).
- Docs: `docs/docker-help.md`, `docs/linuxserver.io.md` (steer users to the watchdog-safe recovery).
