# ADR 0003: Cgroup v2 adoption with dual‑path detection and policy floors/caps

Category: architecture

## Context
Production systems span Debian 10/11/12 with a mix of kernel capabilities. Debian 11/12 default to the unified cgroup v2 hierarchy; Debian 10 relies on v1. We need per‑user resource control for multi‑tenant seedbox workloads (CPU, memory, I/O, task caps) while preserving backward compatibility for older hosts and upgrades. Operators must have predictable defaults, a central policy override, and safe, auditable application of limits. Root must never be constrained by tenant caps.

## Decision
- Detect cgroup mode by kernel presence, not distro name: v2 if `/sys/fs/cgroup/cgroup.controllers` exists; otherwise v1 when controller directories exist; else `unknown` (log‑only).
- Keep two slice templates and select by detected mode:
  - v2: `etc/seedbox/config/template.cgroup.user-slice.v2.conf`
  - v1: `etc/seedbox/config/template.cgroup.user-slice.v1.conf`
- Render user slice overrides only under admin paths: `/etc/systemd/system/user-.slice.d/15-pmss.conf`. Vendor paths are never used; any lingering vendor drop‑ins are removed.
- Enforce safe floors/caps in code when rendering:
  - `MemoryHigh` ≥ 250 MiB (floor)
  - Default `MemoryHigh` ≈ 10% of total RAM
  - `MemoryMax` = min(1.5 × `MemoryHigh`, 95% of total RAM)
  - Default `CPUWeight`=200; `IOWeight`=200; `TasksMax`=512; `CPUQuota`=85%
- Root slice must be unlimited: create `/etc/systemd/system/user-0.slice.d/99-pmss-unlimited.conf` with `MemoryHigh/Max/TasksMax=infinity` and install a lightweight repair utility invoked on boot and periodically.
- Policy overrides come from a PHP array file: `etc/seedbox/config/cgroup.policy.php`, using mount‑based IO defaults (`/` and `/home`) resolved to devices at runtime (via `findmnt`). Policy keys include memory/CPU/IO weights, `CPUQuotaPercent`, `TasksMax`, and mount IO caps (bandwidth/IOPS). Guardrails above always apply.
- Utilities:
  - `scripts/util/userCgroup.php` manages per‑user slice config/status with explicit flags and shorthands (device resolution by mount path such as `/home`); changes are additive; `--wipe` reverts slice.
  - `scripts/cron/checkRootCgroup.php` ensures root slice is unlimited (`@reboot` and periodic cron cadence).
- Lifecycle hooks:
  - User creation applies policy defaults: `php /scripts/util/userCgroup.php USER --apply --defaults`.
  - User termination reverts the user slice (`systemctl revert user‑UID.slice`) before removal.
- CI/Guardrails:
  - Cgroup template lint verifies required placeholders exist in both templates.
  - Dev tests cover v2/v1 rendering, floors/caps, mount‑based IO expansion, utility CLI dry‑runs, lifecycle hooks, and root guard flows.

## Rationale
- Kernel detection is more robust than distro heuristics and supports upgraded hosts.
- Two templates avoid complexity while keeping explicit control over differences between v1 and v2.
- Floors/caps prevent misconfiguration from breaking hosts while keeping sane defaults for tenants.
- Mount‑based IO policy hides device naming variability and reduces operator error; per‑host policy is simple PHP (no new parsing stack).
- Dedicated utilities make the orchestrator thin, observable, and idempotent; repair job protects against accidental misconfiguration of root slice.

## Guardrails
- Never break old users: v1 path remains supported; detection chooses mode at runtime.
- Admin drop‑ins only under `/etc/systemd/system`; remove vendor‑path drop‑ins if found.
- Policy guardrails enforced in code even when overridden.
- Tests are hermetic (no system mutations required) and use env overrides.
- PHP 7.3 compatibility is mandatory for all code paths.

## Implementation
- Templates: provide both v1 and v2 templates with context‑first placeholders:
  - `%%USER_CGROUP_MEMORY_HIGH%%`, `%%USER_CGROUP_MEMORY_MAX%%`, `%%USER_CGROUP_CPU_WEIGHT%%`, `%%USER_CGROUP_IO_WEIGHT%%`, `%%USER_CGROUP_TASKS_MAX%%`, `%%USER_CGROUP_CPU_QUOTA%%`.
- Policy file: `etc/seedbox/config/cgroup.policy.php` defines defaults and per‑mount IO settings; implementation resolves devices via `findmnt` in a single render pass.
- Utilities: `userCgroup.php` (inspect/apply, shorthands, dry‑run, wipe) and `checkRootCgroup.php` (repair).
- Orchestrator: `pmssEnsureCgroupsConfigured()` and `pmssEnsureSystemdSlices()` invoked from `update-step2.php` after package phase and before user/service refresh.
- CI: add `scripts/testing/cgroup-template-lint.sh` and dev tests; advisory sharp‑edges/net‑edges lints validate safe patterns.

## Implementation Plan
1. Add templates and policy file; wire detection and render logic with floors/caps.
2. Add per‑user lifecycle hooks (create/terminate) to apply/revert limits.
3. Ship utilities and cron entries for root repair (`@reboot` and periodic cadence).
4. Add dev tests (v1/v2 rendering, floors/caps, IO mount expansion, CLI dry‑run/profiles, lifecycle hooks, root guard) and enable template lint in CI.
5. Monitor logs; when stable, consider making sharp‑edges/net‑edges lints strict.

## Risks
- Mis‑detection on exotic kernels: mitigated by kernel file checks and log‑only fallback to `unknown`.
- IO throttles on NVMe may be ignored or behave differently: use weights conservatively; strict throttles applied only when explicitly configured.
- Device resolution failures (e.g., bind mounts): we skip entries that cannot be resolved and log a warning.
- Systemd bus restrictions in containers: utilities and tests tolerate `systemctl` failures and continue with log‑only warnings.

## Validation
- Dev tests cover: placeholder replacement, floors/caps clamping, v1/v2 selection, mount‑based IO expansion, additive CLI semantics and wipe, user lifecycle hooks, and root guard flows.
- CI lints: template placeholders enforced; doctrine & sharp‑edges/net‑edges run advisory until tree is clean for strict mode.

## Notes
- IO effectiveness depends on scheduler (e.g., BFQ vs mq‑deadline). Profiles are advisory starting points.
- The policy file is intentionally minimal; more knobs can be added as TODOs once hard requirements emerge.

## Scope
This ADR governs per‑user resource control (cgroups) via systemd slices and related tooling, including detection, policy, templates, utilities, lifecycle hooks, and CI lints. It does not cover application‑level QoS or network shaping beyond notes and TODOs.
