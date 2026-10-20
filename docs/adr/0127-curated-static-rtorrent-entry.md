# ADR 0127: Curated static rTorrent entry

Date: 2026-10-20
Category: architecture

## Status

Accepted

## Context

ADRs 0105 and 0106 provide the inert store and customer selection foundation.
ADR 0124 curates static qBittorrent artifacts. rTorrent already resolves an
explicit store selection in its executor, but lacks a curated artifact and a
Configure picker. Dynamically linked builds can fail after distro upgrades
when shared libraries change (issue #662).

## Options Considered

- Build another rTorrent on every host: adds build dependencies and retains
  shared-library coupling.
- Adopt a newer 0.15/0.16 fork immediately: requires separate compatibility work.
- Pin jesec's self-contained 0.9.8 build and reuse the existing store and switch:
  chosen; preserves the current configuration and process-name family.

## Decision

Curate jesec/rtorrent's latest release, confirmed through the release API on
2026-10-20: tag `v0.9.8-r16`, asset `rtorrent-linux-amd64`, label `0.9.8-r16`,
`kind: static`, `compatFamily: 0.9.8`, `isDefault: false`.

Asset URL:
https://github.com/jesec/rtorrent/releases/download/v0.9.8-r16/rtorrent-linux-amd64

The downloaded 4,669,808 bytes have SHA-256
`974c367b92eca69b85de8afde4937559b8396dc996694da2e7cb4fdc38dc8b40`.
`file` reports `ELF 64-bit LSB executable, x86-64, version 1 (SYSV), statically linked, stripped`;
`ldd` reports `not a dynamic executable`. The release API supplies no digest;
the pin is computed from the downloaded bytes, not independently attested.
The binary is not committed.

The existing static store branch calls `pmssInstallPinnedRemoteBinary()` and
checks the installed SHA-256, failing closed. Its canonical path is
`/home/.pmss/clients/rtorrent/0.9.8-r16/rtorrent`. Static linking sidesteps
issue #662's missing/incompatible shared-library crash class.

Configure uses the generic projection reader and shared native-client switch,
including the account-password gate, snapshot, lock, atomic selection and
failure rollback. rTorrent's existing executor respawns its child, so the
shared stop operation leaves it running until after repointing. The start
operation invokes the existing Apps restart marker consumer
`~/.rtorrentRestart.php`, then verifies the selected native executable runs.
This avoids racing a pre-selection stop against the executor. Restart failure
restores the previous selection through the same shared sequence. Disabled
accounts remain disabled. Existing delivered helpers suffice; no new customer
file or delivery-manifest entry is needed.

The 0.9.8 build uses `rtorrent main` (space) and the current rc syntax.
The watchdog, executor and rc template require no changes. The system rTorrent
default row remains unchanged. The enable marker remains absent; curation
does not download anything on a host or select a version for any customer.
Publication requires an installed regular binary. The strict reserve of more
than 3 GiB plus existing store size free on `/home`, no pruning and the
all-throwables-caught app include remain unchanged.

## Consequences

Rehosting the pinned artifact on a PM mirror is a later sovereignty and
availability follow-up, not a blocker: upstream HTTPS pulls are tamper-evident
against the SHA-256 pin. Availability and vulnerability freedom are not implied.
Debian's rTorrent security tracker lists no CVE rows at review time; upstream's
XMLRPC guidance warns that RPC access can execute commands as the account.
This change adds no listener, credential storage or authentication policy.
Existing account-hash verification, private switch state, public host-only
projection, native UID/mount/executable checks and customer include boundaries
are preserved. A compromised binary runs with the customer's privileges;
static bundled dependencies still require future security review and updates.

Genuinely newer rTorrent 0.15/0.16 releases and other forks are staged follow-up
work requiring version-aware watchdog process identity and rc rendering:
`rtorrent main` becomes `rtorrent-main`, and 0.16.14 rejects the current rc
template encoding. Neither compatibility mechanism is built here.
Changing the system/default rTorrent to the latest version is a separate staged
fleet-behavior decision. Operator canary launch and live verification remain
rollout work; no live host is touched by this curation.

## References

- [jesec release](https://github.com/jesec/rtorrent/releases/tag/v0.9.8-r16)
- [rTorrent security tracker](https://security-tracker.debian.org/tracker/source-package/rtorrent)
- [Upstream RPC guidance](https://github.com/rakshasa/rtorrent-doc/blob/master/RPC-Setup-XMLRPC.md)
- Issues #1068 and #662; ADRs 0105, 0106, 0119 and 0124
