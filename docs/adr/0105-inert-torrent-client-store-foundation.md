# ADR 0105: Inert torrent-client store foundation

Date: 2026-10-17
Category: architecture
Status: Proposed

## Context

Per-user torrent-client version selection needs a catalog, a shared artifact
store, and account-owned links before launchers can safely consume them. The
existing update flow loads every app installer under `apps/`, so an optional
installer must return quietly when disabled and must contain exceptions when
enabled. Customer PHP cannot load operator code from `/scripts` (ADR 0016).

## Options considered

- Wire launchers and panel immediately: changes running services before the
  version data and identity checks have been verified.
- Store copies in each account: duplicates downloads and makes root inspect
  tenant-owned paths.
- Add an inert root-owned store and customer-local resolver: isolates the new
  behavior behind one marker and preserves existing launch paths.

## Decision

The manifest names one system entry per client. Remote entries, when curated,
must have a SHA-256 pin and are installed under `/home/.pmss/clients/` only
while `torrentClients.enabled` exists. The app include catches all throwables.
The store requires more than 3 GiB plus its measured existing set size free on
`/home`; a shortfall skips installation without pruning prior versions.
Size probes reject empty or NUL-containing roots. Failed, negative or
unrepresentable measurements use the existing space-skip path. The reserve
comparison subtracts 3 GiB from free bytes to avoid overflowing the required-size
sum; equality still skips and one byte above the requirement permits installation.
`TorrentClientsStoreTest` covers measurement failures and these boundaries.

Account version links are created only through `pmssAccountPathRun()`; P1 stopped
automatic default-link creation so selection requires a customer switch (ADR 0106).
The customer resolver lives in `etc/skel/www/`
and reads only the public host projection. Process identity uses UID, process
name, and mount namespace; the later native-stop path also checks the kernel
executable path against the store and that account's bin prefix. No launcher,
panel, or credential flow uses these helpers in this phase.

## Security and consequences

This phase adds no torrent-client WebUI listener, changes no WebUI
authentication, and stores no passwords.
Root-owned store files are 0755; the host-only projection is 0644 beneath the
root-owned public directory. Logs contain client/version status, no account
credentials. A compromised tenant may alter only its own links and cannot
change the store or another tenant's default. The marker should remain absent
until curated remote entries and live-host validation are ready.

Host-marker opt-in clauses superseded by [ADR 0163](0163-torrent-client-store-on-by-default.md).
