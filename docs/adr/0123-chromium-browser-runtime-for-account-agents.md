# ADR 0123: Debian Chromium as the browser runtime for account agents

Date: 2026-10-19
Category: architecture

## Status

Accepted

## Context

Agents that run inside a customer account (for example a coding or assistant agent the account
owner starts) increasingly need a real browser: to open the account's own web services, log in to
them and take screenshots. Without a system browser each account downloads its own Chromium
build. Those copies cost several hundred megabytes per account, nobody updates them, and a
browser that misses security fixes is a browser exposed to every page it opens.

Debian 12 and 13 ship `chromium` with security updates through the Debian security archive, so
one root-managed copy per host is patched with the rest of the system.

Measured on 2026-10-19 by resolving the install against the Debian 12 and Debian 13 selection
baselines with recommends off (a conservative baseline, so the result is an upper bound):

| Release | New packages | Installed size | Downloaded archives |
| --- | --- | --- | --- |
| Debian 12 | 8 | about 355 MiB | about 111 MiB |
| Debian 13 | 40 (GTK and audio libraries the baseline lacks) | about 450 MiB | about 131 MiB |

The selection baselines are applied additively on every host at every update
(`dpkg --set-selections` followed by `apt-get dselect-upgrade`). A `chromium install` line there
would install the browser on every Debian 12 and 13 host at its next update, including hosts whose
root filesystem has little room left, where a failed unpack could leave dpkg half-configured in
the middle of an update.

## Options Considered

- Add `chromium` to the Debian 12 and 13 selection baselines. Rejected: it installs everywhere at
  once, with no free-space check.
- Leave the browser to each account. Rejected: per-account copies are large, duplicated and not
  covered by Debian security updates.
- A dedicated app step that installs the Debian package only where it fits. Chosen.

## Decision

`scripts/lib/update/apps/chromium.php` runs in phase 2 with the other app installers:

- Debian 12 and 13 only; on other releases it does nothing and logs nothing.
- Installs `chromium` with `apt-get install -y --no-install-recommends`. On Debian 13 it also
  installs `slirp4netns`, which the Debian 12 baseline already carries and the
  Debian 13 baseline does not. It does not install `uidmap`: the account browser runs in a
  namespace made with `unshare --map-root-user`, which needs no subordinate ID ranges, and
  `uidmap` would add two setuid-root helpers (`newuidmap`, `newgidmap`).
- Never installs `chromium-sandbox`. That package contains a setuid-root helper
  (`/usr/lib/chromium/chrome-sandbox`). Chromium also sandboxes itself with user namespaces and
  seccomp-bpf, which Debian's kernels support, and Debian stopped recommending the setuid package
  for this reason (Debian bug #1135653, fixed in chromium 154.0.8037.57-1). Older builds still
  recommend it, so recommends stay off on every install.
- Free-space floor: `/`, `/usr` and `/var` must each have at least 3 GiB available before the
  install. The measured worst case is about 0.6 GiB including archives, so at least 2 GiB stays
  free afterwards. Below the floor, or when free space cannot be measured, the step logs one
  `[SKIP]` line and changes nothing. A later update tries again.
- Idempotent: when every needed package is already installed it makes no apt call and logs
  nothing. It runs only after the package phase completed.
- An administrator's choice wins: a package selected `deinstall`, `purge` or `hold` makes the
  step skip with one log line instead of reinstalling it at every update. It never passes
  `--allow-change-held-packages`.
- Fail-soft: an apt failure or an exception is logged as a warning and the update continues.

## Consequences

- Hosts with room get one Debian-maintained browser that follows Debian security updates through
  the normal update path. Hosts without room keep running exactly as before.
- The packages are not in the selection baselines, so they are not converged by them. Once
  installed they stay installed (apt marks them manually installed, so autoremove keeps them).
  Removing them is an administrator action and is respected afterwards.
- This is a second place, next to the baselines, where an updater step installs packages. If
  every supported host ends up with the browser installed, the packages can move into the
  baselines and this step can be deleted.
- Isolation between accounts is the consumer's responsibility. Installing a browser grants no
  account anything new. Software that runs the browser for an account must keep that browser's
  debugging and control endpoints unreachable from other accounts on the same host, and must
  keep each account's credentials scoped to that account's own services.

## References

- `scripts/lib/update/apps/chromium.php`
- `scripts/lib/tests/development/ChromiumRuntimeInstallTest.php`
- `scripts/lib/update/environment.php` (`pmssApplyDpkgSelections`)
- `scripts/lib/update/dpkg/selections-debian12.txt`, `scripts/lib/update/dpkg/selections-debian13.txt`
- Debian bug #1135653: https://bugs.debian.org/1135653
- ADR-0122
