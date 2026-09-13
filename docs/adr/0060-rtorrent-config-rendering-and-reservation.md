# ADR 0060: Separate rTorrent rendering from port allocation

Date: 2026-09-11
Category: architecture

## Status
Accepted

## Context
The configuration class combined file IO, template sizing, and two reservation
attempt loops. Sizing crossed a temporary result map before becoming tokens;
reservation attempts crossed a nullable boolean before becoming exceptions.

## Options Considered
- Keep the class intact: preserves duplicated attempt handling and intermediate state.
- Split methods without simplifying them: relocates the same control flow.
- Extract rendering and allocation while removing their intermediate state (chosen).

## Decision
`rtorrentConfig` retains its public API, protected hooks, defaults, file IO, and
transaction lock/rollback. `rtorrent/configRender.php` renders tokens directly;
`rtorrent/portReservation.php` uses one exclusive writer for random attempts and
the ordered fallback. Username checks reuse the existing reservation validator.

## Consequences
The sizing map, tri-state reservation result, duplicate attempt body, and duplicate
username fallback disappear. Port ranges, random-attempt budget, exceptions,
rollback, file modes, localnet handling, and replacement order remain unchanged.
Literal render snapshots, memory boundary cases, a seeded fallback, and existing
unsafe-path/rollback tests lock behavior. No production migration is needed.

## References
- [ADR 0036](0036-pmss-owned-config-files-are-generated-from-a-template-never-parsed-and-patched.md)
- [Contracts](../contracts.md#rtorrent-configuration--scriptslibrtorrentconfigphp)
