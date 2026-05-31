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
3. **update-step2.php** – Orchestrator that consumes the staged tree and runs
   modules under `scripts/lib/update/`. Responsibilities and ordering are
   documented in [`docs/update.md`](./update.md#phase-2--scriptsutilupdate-step2php).

## Key Modules
- **scripts/lib/update/environment.php** – dpkg/apt guards plus helper to apply release-specific package selections.
- **scripts/lib/update/filesystem.php** – warning-only filesystem preflights, including `/home` inode density detection for media-stack-heavy hosts.
- **scripts/lib/update/kernelHardening.php** – module blacklist hardening that writes PMSS-owned `modprobe.d` entries and attempts runtime eviction so already-loaded modules do not silently persist. Interim blacklists such as `pmss-algif-blacklist.conf` are reverted by deleting the PMSS-owned file after patched kernels are deployed fleet-wide.
- **scripts/lib/update/repositories.php** – Applies `/etc/seedbox/config/template.sources.<suite>` when version is known; otherwise logs and leaves sources untouched. Finishes with `apt update` via `runStep()`.
- **scripts/lib/update/systemPrep.php** – Cgroups, systemd slices, base permissions, locale setup.
- **scripts/lib/update/services/** – Runtime templates (rc.local, systemd, sshd), legacy service disablement, mediainfo installer, security tweaks.
- **scripts/lib/update/users/** – User maintenance (context, HTTP, home maintenance, ruTorrent refresh).
- **scripts/lib/update/apps/** – Application installers (rtorrent, deluge, docker, etc.) called during phase 2. These modules perform one-time bootstrap tasks only; ongoing configuration and scheduling belong under `scripts/util` and `scripts/cron`.

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
- CLI probes: `/scripts/util/systemTest.php`, `/scripts/util/componentStatus.php`, `/scripts/util/agentDiagnostics.php`

Development tests must avoid network/system changes; production tests and the CLI probe are intended for curated post-provision runs.

## Config Templates
- `/etc/seedbox/config/template.*` (rc.local, systemd.conf, nginx, proftpd, etc.) are copied by service helpers.
- `/etc/seedbox/config/template.sources.<suite>` defines apt sources for each distro.
- Generated configs under `/etc` must be idempotent: invoking the matching `scripts/util/*Config*.php` multiple times converges on the same safe state without accumulating duplicate directives or diverging permissions.
- `etc/skel/www` first-party files are editable normally. Bundled vendor/third-party trees remain read-only unless explicitly approved.

## Logs & Profile
- Plain log: `/var/log/pmss-update.log`
- JSON events: `/var/log/pmss-update.jsonl`
- Optional profile: `PMSS_PROFILE_OUTPUT` or `<json>.profile.json`
- `runStep()` in `install.sh` and update modules logs every command; respect fail-soft principle.
