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

Configure filters the bounded public projection for each client and renders a
card only when that client's version list is nonempty. Its existing account
password check guards all three clients. Deluge and Transmission use their own
account lock, private lastRun/lastgood/notice files, configuration snapshot,
native stop, checked atomic default-link replacement, and restart. A failed
start selects and restarts the prior version. Both watchdogs skip launch while
the corresponding switch lock is held. qBittorrent's switch code and rTorrent
behavior remain as they were.

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
