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
