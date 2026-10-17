# ADR 0110: Deluge catalog versions use per-version virtualenvs

Date: 2026-10-17
Category: architecture
Status: Proposed

## Context

Deluge already has a system installation and opt-in per-account watchdog. The
torrent-client catalog and customer resolver can name store versions, while the
shared Python virtualenv installer already owns environment creation and pip
execution. A Deluge catalog version needs both daemon entrypoints from one
Python environment.

## Options considered

- Copy a prepared virtualenv archive into the store: the existing archive path
  does not establish a host-local Python environment.
- Add a second virtualenv installer: duplicates the established app installer.
- Build a version directory with the shared virtualenv helper and pinned wheels
  from a local mirror.

## Decision

A Deluge store row has `kind: venv`, empty `url` and `sha256`, and a nonempty
`wheels` list. Each wheel names a local `.whl` file and its SHA-256. The optional
`compatWindow: bullseye-cp37-310` confines an older wheel set to Debian 11 and
Python 3.7–3.10. The root-side store checks every mirror file before invoking
`pmssPythonVenvInstallCli()`, which uses `pmssPythonVenvEnsure()`. Pip receives
`--no-index --no-deps` and the pinned wheel paths. The version's `bin/deluged`
and `bin/deluge-web` must both exist before publication.

The public projection names only installed versions. The customer resolver
accepts a Deluge store selection only for a published venv and launches its two
scripts under the account. The watchdog retains the existing system commands
when no version is selected. The shared process identity helper recognizes the
interpreter plus script path for both Deluge daemons.

## Consequences

The shipped catalog contains only the system Deluge row, so this mechanism is
idle for all current accounts. A later rollout supplies the wheel mirror, catalog
versions, account selection UI, and live-host validation. The shared virtualenv
helper still updates its pip tooling during environment setup; the Deluge package
wheel set itself is installed only from the pinned local mirror.
