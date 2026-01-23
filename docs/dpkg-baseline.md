# Capturing a New DPKG Baseline

When onboarding a new distro (e.g. Debian 13, Ubuntu derivatives), follow this
process to generate the immutable `scripts/lib/update/dpkg/selections-<distro>.txt`
manifest.

> #TODO #Debian13: capture and land `scripts/lib/update/dpkg/selections-debian13.txt` with platform sign-off.

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
