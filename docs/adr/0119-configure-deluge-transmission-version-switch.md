# ADR 0119: Configure switches Deluge and Transmission versions per account

Date: 2026-10-19
Category: architecture
Status: Proposed

## Context

ADR 0106 gave qBittorrent a password-guarded Configure picker and a locked,
recoverable switch. Deluge virtualenvs and Transmission socket-capable builds
already have customer-side resolvers and launchers (ADRs 0110 and 0108), but
no account-facing selection. The shipped catalog currently offers only system
Deluge and no Transmission build; absent projection rows must stay inert.

## Options considered

- Add separate switches with new daemon commands: duplicates launch policy.
- Share qBittorrent's switch state machine by changing its existing path:
  risks changing a working account contract.
- Add Deluge and Transmission adapters with the same switch sequence and
  reuse their existing launchers and customer-side native identity readers.

## Decision

Configure filters the bounded public projection for each client. A shared
customer-side renderer supplies all four clients' cards and matching index
entries. Multiple offered versions retain the existing controls. One offered
version equal to the current selection shows Running and the notice without
switch controls; a different selection retains controls. The projection's
`notInstalled` map counts enabled catalog entries skipped because their binary,
Deluge web entrypoint or Transmission socket capability is unavailable; disabled
entries are excluded and `versions` is unchanged. Cards explain that other
versions are not installed only when that client's count is positive, independent
of the account. Older projections default the counts to zero. Clients with no
entries are named once at the end of the first card. When no client has controls,
one general card lists installed clients and their running builds, notices and
availability sentences, with one matching index entry. An absent, unreadable,
symlinked, oversized or invalid projection instead shows one general version-choice
unavailable card. The existing account password check guards version switches
for every client. Deluge and Transmission use their own
account lock, private lastRun/lastgood/notice files, configuration snapshot,
native stop, checked atomic default-link replacement, and restart. A failed
start selects and restarts the prior version. Both watchdogs skip launch while
the corresponding switch lock is held. qBittorrent and rTorrent retain their
existing launch and recovery paths.

All four clients now share ADR 0106's same-version no-op contract: both the
selection and native running build must match exactly under the lock. rTorrent's
descriptive system fallback/pending-restart states do not match a requested
version, so those requests still restart through the existing path. Real switches
retain all validation, backup, downgrade refusal and recovery checks.

An invalid selection link may switch to an offered `system` build; other
requests still receive the existing invalid-selection refusal. Failed recovery
recreates the original link verbatim, including a dangling target. An unresolved
store selection with a native process reports that the server build is running
because the selected version is not installed. Backup failure stops before any
daemon or selection change and records the backup's reason without claiming a
restore. Post-setup notices and logs name the client and explain server fallback.

Deluge restarts a selected virtualenv through `pmssTorrentClientDelugeLaunch()`;
the legacy toggle and system-version restore share the same system launch
command. The customer-side Deluge stop uses the existing native process reader
for both daemon and web scripts. Transmission uses its existing socket probe,
native PID reader, and `pmssTransmissionLaunch()`. On a first selection, the
customer prepares the same socket-only managed settings that the root cron
would otherwise write after seeing a selected link. It preserves unrelated
settings, removes unused RPC credentials, writes a private regular file, and
refuses symlinked paths. The launcher still requires exact managed settings.

## Security and consequences

Deluge has published Web UI and daemon advisories, including
[CVE-2025-46561 through CVE-2025-46564](https://securitylab.github.com/advisories/GHSL-2024-188_GHSL-2024-191_Deluge/).
Transmission's RPC exposure risk includes
[CVE-2018-5702](https://nvd.nist.gov/vuln/detail/CVE-2018-5702).
The switch adds no network listener: Transmission remains on a mode-0700 Unix
socket behind the account's htpasswd proxy, with an empty RPC whitelist. The
primary account password is checked against its existing hash and never logged
or stored by the switch; Deluge's separate service credential remains separate.
State files are mode 0600. A compromised account can alter its own selection
and settings only; the operator store and public projection remain root-owned.

Development fixtures cover password rejection, version filtering, native path
checks, locked operation order, private state, and restore on failed start.
Production canaries must verify both Deluge processes and the Transmission
socket after a real version change before a wider catalog rollout.
