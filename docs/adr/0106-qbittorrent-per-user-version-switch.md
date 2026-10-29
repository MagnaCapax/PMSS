# ADR 0106: Per-user qBittorrent version switching

Date: 2026-10-17
Category: domain
Status: Proposed

## Context

ADR 0105 installed an inert client store and customer resolver. A host marker
must not change a user's running qBittorrent until the user explicitly chooses
a version. P0's account-link convergence previously made a default symlink
automatically, so P1 removes that creation.

## Options considered

- Start every account through the resolver once the host marker exists: this
  changes legacy launch behavior without an account choice.
- Let the customer Configure action select a version and share a lock with the
  watchdog launcher: chosen to keep the legacy command unchanged for accounts
  without a default symlink.

## Decision

Configure requires the account password for `torrentClientSwitch`. It accepts
only qBittorrent versions in the root-owned public installed-version projection.
The customer process holds `~/.config/pmss/torrentClients/qbittorrent.lock`
across a private configuration snapshot, native daemon stop, atomic symlink
replacement, last-run record and restart. The watchdog launch shell takes
the same lock without waiting whenever the switch state directory exists,
including the first switch from the legacy version. A busy lock exits quietly; the next
watchdog pass can recover. Failed starts restore the preceding selection and
record a private notice.

Selecting the current version is a successful no-op only when the native
running-version reader also reports that exact version under the switch lock.
It returns an already-running message without a backup, restart, link change,
enable marker, or state/notice write. A stopped or different build still uses
the existing switch and recovery path, including ADR 0120's downgrade refusal.
An unresolved selection may recover to an offered `system` build; other
requests retain the invalid-selection refusal. Recovery failure restores the
original link target verbatim. Backup failure reports its reason and that
nothing changed, without claiming a restore. ADR 0119 documents the shared
availability counts and combined read-only version card.

The root watchdog uses the P0 launcher-liveness predicate (name, UID and mount
namespace). Operator stop sites use the P0 native predicate (those checks plus
an allowed executable path), rechecking immediately before each PID signal.
The customer Configure process cannot traverse `/scripts`, so its native stop
check runs wholly inside the customer tree. The system qBittorrent executable
is an allowed native path. A process in another mount namespace is excluded.

The selected version's `compatFamily` controls `Session\\DiskIOType`. Families
at or above 5.0.1 use `SimplePreadPwrite`; older families retain `Posix`;
`lt1.2` removes the unsupported key. Without selection, the managed config
retains the previous `Posix` behavior. The WebUI auth reconciler keeps its
`LocalHostAuth` policy and uses the shared native identity for selected builds.

## Security and consequences

Switching versions adds no WebUI listener, changes no WebUI credentials, and
logs no password. The Configure password is verified against the existing
account hash and is absent from the action log. The account-owned lock, state
and notice files are private; the public projection contains only host
availability. Exposure of the chosen binary path identifies a version but
does not grant access to another account's filesystem. Rollout still depends
on the canary and WebUI authentication checks from ADR 0102 and issue #1061.

## Amendment 2026-10-29: the disk backend is set in the panel's stopped window too

A Configure switch stops qBittorrent, repoints the selection and starts the new
build inside one locked call, so the watchdog never sees it stopped and the root
applier could not set `Session\\DiskIOType` before the first run: an account
switched from 4.x kept `Posix` on 5.x (#1061). The customer-side restore that runs
in every panel start's stopped window (switch, Apps start, and the resolver launch that the
watchdog also uses to start qBittorrent) now
applies the rule above from the selected build's `compatFamily`
(`pmssQbittorrentFrontendDiskIoType` in `etc/skel/www/qbittorrentBuild.php`, a
twin of `pmssQbittorrentCompatFamily` plus `pmssQbittorrentDiskIoType`). It never writes while the account's
qBittorrent runs.
