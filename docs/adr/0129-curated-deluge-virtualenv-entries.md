# ADR 0129: Curated Deluge virtualenv entries

Date: 2026-10-20
Category: architecture

## Status

Accepted

## Context

The shipped Configure Deluge picker consumes the same installed-only public
projection as qBittorrent, but the manifest has only the Deluge system row.
ADR 0110 already defines local wheel installation; ADR 0124 establishes the
precedent of a small per-series catalog without changing system defaults.

## Options Considered

- Fetch an unpinned pip requirement: violates ADR 0110's local wheel contract.
- Supply every point release: expands maintenance without a clear benefit.
- Pin the latest published stable wheel in each recent series: chosen.

## Decision

Curate Deluge 2.0.5, 2.1.1 and 2.2.0 as non-default `kind: venv` entries with
`compatFamily: "2"`, empty `url` and `sha256`, and a `wheels` list containing
the exact `deluge-<version>-py3-none-any.whl` filename and SHA-256. On
2026-10-20 the PyPI release index showed these as the latest non-prerelease
versions in their respective series, with no yanked wheels. Each wheel was
downloaded and hashed; all three matched the published digests. Exact pins
live in `etc/seedbox/config/torrentClients.json`. No wheel bytes are committed.
These universal Deluge wheels do not need the optional older platform-specific
`bullseye-cp37-310` compatibility window.

The existing store dispatches Deluge venv rows to
`pmssTorrentClientsDelugeVenvInstall()`, before its generic venv archive branch.
It requires root-owned regular wheel files under `/home/.pmss/clients/wheels/`,
verifies each hash, then uses the shared virtualenv helper with
`--no-index --no-deps` to install under `/home/.pmss/clients/deluge/<version>/`.
Both `bin/deluged` and `bin/deluge-web` must be regular files before publication.
The existing projection and customer-local picker already accept these entries;
no projection, installer or customer include changes are needed.

The Deluge system default remains unchanged. Installation and publication stay
behind the absent `torrentClients.enabled` marker. The strict reserve of more
than 3 GiB plus existing store size, no-pruning behavior and all-throwables-caught
optional app include remain unchanged. Transmission and rTorrent are outside
this decision.

## Consequences

This curates Deluge package pins; it does not certify a runnable host environment.
Before enabling a staged canary, the operator must provision the pinned wheel
mirror and a compatible runtime dependency closure, including libtorrent's
Python bindings. An isolated venv cannot inherit distro Python packages, and
`--no-deps` deliberately performs no dependency resolution. Dependency wheels
must also receive reviewed filename/hash pins in `wheels`; the present rows
contain only the Deluge distribution wheel. The current success check proves
entrypoint presence, not successful imports or daemon startup. Canary import,
launch and WebUI checks therefore remain required before customer rollout.

PyPI provenance and SHA-256 pins detect changed bytes, not vulnerabilities in
older releases. Mirroring the reviewed bytes under PM control and completing
platform-specific dependency sets are follow-ups, not reasons to replace the
existing pinning contract. The shared helper still upgrades pip, setuptools and
wheel from its configured index; pinning or mirroring that bootstrap remains
a separate supply-chain follow-up. No live host, credentials, listeners or
account selections change here.

## References

- [Deluge PyPI release index](https://pypi.org/pypi/deluge/json)
- [Deluge 2.0.5](https://pypi.org/project/deluge/2.0.5/)
- [Deluge 2.1.1](https://pypi.org/project/deluge/2.1.1/)
- [Deluge 2.2.0](https://pypi.org/project/deluge/2.2.0/)
- [Issue #1068](https://github.com/MagnaCapax/PMSS/issues/1068)
- ADRs 0105, 0110, 0119 and 0124

## CPython 3.11 dependency-closure completion (2026-10-20)

This follow-up supersedes the Deluge-only wheel scope and universal-runtime
assumption above. The document was absent from baseline 127f476d; its original
curation decision is retained here alongside the completed closure. The new
manifest rows are resolved afresh on that baseline, without applying the prior
Deluge implementation commit.

Each row now pins the entire Linux x86_64 CPython 3.11 runtime wheel closure
using the existing `wheels: [{file, sha256}]` contract. On 2026-10-20, each
Deluge version was independently resolved with:

```sh
pip download deluge==<version> --only-binary=:all: --python-version 3.11 \
  --platform manylinux2014_x86_64 --platform none-any -d <temporary-directory>
pip download libtorrent --only-binary=:all: --python-version 3.11 \
  --implementation cp --abi cp311 --platform manylinux_2_17_x86_64 \
  -d <libtorrent-directory>
```

All three binary-only resolutions succeeded without sdist-only failures.
Libtorrent is required at runtime but absent from Deluge's declared dependency
metadata, so its separately resolved wheel is included in every row:
`libtorrent-2.1.1-cp311-cp311-manylinux_2_17_x86_64.manylinux2014_x86_64.whl`.

| Deluge | Declared closure wheels | Additional libtorrent | Total pinned |
| --- | ---: | ---: | ---: |
| 2.0.5 | 23 | 1 | 24 |
| 2.1.1 | 22 | 1 | 23 |
| 2.2.0 | 22 | 1 | 23 |

The closure includes Twisted's TLS extra and its transitive dependencies,
cryptography, cffi, pycparser, pyOpenSSL, service-identity, pyasn1, rencode,
Mako, MarkupSafe, pyxdg, setuptools and zope.interface. Deluge 2.0.5 also
requires six. Exact versions, filenames and SHA-256 values live in the manifest;
no wheel bytes or temporary environments are committed. Every downloaded
wheel's computed SHA-256 matched its non-yanked PyPI release record.

An isolated Debian 12 / Python 3.11.2 check exposed that the unconstrained
setuptools 84.2.0 resolution breaks `pkg_resources` imports in Deluge 2.0.5 and
2.1.1 despite `pip check` succeeding. Those two closures were independently
re-resolved with the additional requirement `setuptools==80.9.0` and explicit
`--implementation cp --abi cp311`; all dependencies remained binary wheels.
The reviewed pins use that compatible setuptools wheel. Deluge 2.2.0 retains
setuptools 84.2.0. Each final set was installed with `--no-index --no-deps` in
an isolated Bookworm venv: `pip check`, `deluged --version`, and
`deluge-web --version` passed and reported libtorrent 2.1.1. These are import
and entrypoint checks, not a live torrent/daemon/WebUI certification.

Each curated row declares `compatWindow: bookworm-cp311`. Catalog validation
accepts that explicit window; the existing compatibility helper requires Debian
12, x86_64 and Python 3.11 before checking mirror files or invoking pip. Debian
11 / Python 3.9 therefore returns false from the installer and remains on the
existing system fallback. Other Python minors and malformed version responses
also skip. The older `bullseye-cp37-310` window and rows without a window keep
their existing behavior. The system Deluge row is unchanged, all curated rows
are non-default, and `torrentClients.enabled` remains absent.

Remaining follow-ups before enabling customer rollout:

- Resolve and review a separate Python 3.9 / Debian 11 Bullseye wheel closure
  for the remaining Bullseye (Python 3.9) hosts; the current CPython 3.11 set targets the
  Bookworm hosts, the large majority of the fleet. Do not reuse cp311 wheels on Bullseye.
- Operator hosting of the exact reviewed wheel bytes under PM control, then
  provisioning the root-owned local mirror at `/home/.pmss/clients/wheels/`.
  Public PyPI is resolution/provenance evidence, not a runtime download path.
- Pinning/mirroring the shared helper's pip/setuptools/wheel bootstrap, which
  still upgrades tooling from its configured index before installing our pins.
- Canary daemon startup, torrent operation and WebUI validation, including
  compatibility of older Deluge releases with the pinned libtorrent runtime.

## Start-tested closure and mirror fetch (2026-10-21)

The 2026-10-20 closure installed but could not run. In a Debian 12 / Python
3.11.2 container, each version was installed with `--no-index --no-deps` and
then started:

- Deluge imports `pkg_resources`, which setuptools 81 and later removed.
  The pinned setuptools 84.2.0 (2.2.0) failed at import.
- deluged builds its certificate with `OpenSSL.crypto.X509Req`, which the
  pinned pyOpenSSL 26.4.0 no longer provides. deluged exited at startup on
  every version, while deluge-web still started.

The closure is re-resolved with `setuptools<81` and `pyopenssl<24.3`. That
gives setuptools 80.10.2, pyOpenSSL 24.2.1, cryptography 43.0.3, Twisted 25.5.0,
service_identity 24.2.0 and pyasn1_modules 0.4.2; 2.0.5 also needs six.

With this closure, deluged and deluge-web start for 2.0.5, 2.1.1 and 2.2.0. That
holds in the PMSS helper order too: the bootstrap upgrades setuptools from the
index, and the pinned `--no-deps` install then replaces it with 80.10.2.

The reviewed wheel bytes are hosted under PM control at
`https://pulsedmedia.com/remote/pkg/wheels/`. When a pinned wheel is missing or
differs from its pin, the installer fetches it into the root-owned
`/home/.pmss/clients/wheels/` through `pmssInstallPinnedRemoteBinary`. That
helper verifies the SHA-256 before installing. The existing per-wheel hash and
ownership check still decides whether pip runs, so a failed or wrong download
never reaches pip and is retried on the next update.

This supersedes the "operator hosting" and "provisioning the local mirror"
follow-ups above. The Bullseye closure, the bootstrap pinning and the live canary
remain open.

Host-marker opt-in clauses superseded by [ADR 0163](0163-torrent-client-store-on-by-default.md).
