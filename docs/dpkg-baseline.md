# Capturing a New DPKG Baseline

When onboarding a new distro (e.g. Debian 13, Ubuntu derivatives), follow this
process to generate the immutable `scripts/lib/update/dpkg/selections-<distro>.txt`
manifest.

> #TODO #Debian13: capture and land `scripts/lib/update/dpkg/selections-debian13.txt` with platform sign-off.

## Debian 13 Validation Roadmap (#111)

Debian 13 (`trixie`) remains experimental until PMSS has both a captured dpkg
baseline and a second-host replay validation. Use this checklist to promote it
from experimental to supported without widening scope during the capture work.

### Current guardrails

- Keep the existing `template.sources.trixie` flow; do not migrate baseline
  Debian repositories to deb822.
- Do not change MediaArea repository handling during Debian 13 validation.
- Never hand-edit `scripts/lib/update/dpkg/selections-debian13.txt`; capture it
  from a converged host and land it with platform sign-off.

### Validation checklist

1. Provision a clean Debian 13 host and record `/etc/os-release` plus the VM or
   bare-metal context used for the run.
2. Capture a full interactive install/update transcript with `script`; keep
   `/tmp/pmss-install.typescript`, `/var/log/pmss-install.log`,
   `/var/log/pmss/update.log`, `/var/log/pmss-update.log`, and
   `/var/log/pmss-update.jsonl` for review.
3. Triage any package-phase or phase-2 breakage on `main` with minimal diffs
   before capturing the baseline.
4. Export install-only selections from the converged host, verify replay on the
   same host, and land the resulting file as
   `scripts/lib/update/dpkg/selections-debian13.txt`.
5. Provision a second clean Debian 13 host, replay the captured baseline, and
   run `/scripts/util/systemTest.php` to confirm the baseline converges.
6. Only after the baseline exists and replay succeeds should PMSS docs and rails
   promote Debian 13 beyond experimental.

### Promotion gate

Debian 13 is ready to move beyond experimental only when all of the following
are true:

- `scripts/lib/update/dpkg/selections-debian13.txt` exists and was captured from
  a real converged host.
- Replay on a second clean Debian 13 host succeeds without manual package drift
  fixes.
- The relevant docs and support matrix entries have been updated together.

## Baseline Capture Procedure

1. **Provision a clean host** with the target OS and run the current PMSS
   updater (`install.sh` + `/scripts/update.php git/main`). Make sure the run
   completes without package queue warnings.
2. **Refresh package metadata** and clean strays:
   ```bash
   apt-get update
   apt-get -y autoremove
   apt-get -y --fix-broken install
   ```
3. **Export the selections** and strip `deinstall` rows:
   ```bash
   dpkg --get-selections \
     | awk '$2 == "install" { print $1 }' \
     | sort -u > /tmp/selections-new.txt
   ```
4. **Review the list** for transitional/meta packages (e.g. `proftpd-basic`).
   Replace them with the real package names before committing.
5. **Verify availability** by replaying the list on a staging host:
   ```bash
   dpkg --set-selections < /tmp/selections-new.txt
   apt-get dselect-upgrade -y
   ```
   The command must complete without missing-package warnings.
6. **Commit under `scripts/lib/update/dpkg/`** using the naming scheme
   `selections-debianXX.txt` (or similar) and update `AGENTS.md` if the support
   matrix changes.

> **Always regenerate from a live host.** Never hand-edit the manifests: capture
> a new list, keep it sorted, and land it with platform sign-off.

## Operational Expectations

- Treat `install.sh` as immutable bootstrap glue. Any behavioural change must
  follow the guardrails documented in [`docs/install.md`](./install.md) so fresh
  hosts still mirror the environments used to capture the baseline.
- The committed snapshots (`selections.txt` plus
  `selections-debian10/11/12.txt`) originate from production systems. Preserve
  the lists exactly as captured—no manual edits, reorderings, or deletions.
- When the support matrix changes (new Debian release or derivative), update
  this document and `AGENTS.md` alongside the new selections file so operators
  know which baselines exist.

## Validation Commands

After provisioning a host with the refreshed baseline, collect health evidence
before rolling into production:

```
/scripts/util/systemTest.php
```

Produces a human-readable summary of binary versions, configuration layout, and
other sanity checks.

```
/scripts/util/componentStatus.php --json
```

Emits structured JSON suitable for dashboards or CI pipelines. Both utilities
are non-destructive and provide confidence that the captured selection file
matches real-world hosts.
