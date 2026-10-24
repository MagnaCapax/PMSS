# ADR 0163: Torrent-client store on by default

Date: 2026-10-23
Category: architecture

## Status

Accepted. Supersedes the host-marker opt-in clauses of ADRs 0105, 0107, 0108,
0124, 0129 and 0146.

## Context

On 2026-10-21 the operator rejected the global host gate as an artificial
barrier to seedbox clients and directed that the feature reach distribution.
The canary hosts that carried the marker since 2026-10-21 showed no fallback
notices and no store errors before this change.

## Options Considered

- Retain per-host enablement: contradicts the operator ruling.
- Replace it with an opt-out: retains an unnecessary host policy switch.
- Converge the store on every normal update with the existing protections: chosen.

## Decision

Remove the host opt-in marker from installation, publication, account links,
Configure offers, toggles, watchdogs and Transmission proxy selection. Add no
replacement flag or environment switch. Preserve catalog validation, checksums,
locks, per-account disable markers, the `/home` free-space skip and rTorrent's
fast-exit system fallback exactly as they are.

## Consequences

- Each host downloads about 1 GB of builds on its next normal update; the store
  skips a client installation when `/home` lacks the existing required reserve.
- Configure shows installed version sections for all four torrent clients.
- Eligible accounts that never switched rTorrent receive 0.16.25 at their next
  rTorrent restart under ADR 0146's unchanged custom-rc and ruTorrent compatibility
  checks, with system fallback after three consecutive fast exits. Running
  processes are not restarted by link convergence.
- qBittorrent and Deluge retain system defaults; Transmission still requires
  an account selection. Customer/operator PHP boundaries stay unchanged.
