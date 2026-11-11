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
reservation lock. After ADR 0081 left only SCGI allocation, the multi-port
rollback was removed. `rtorrent/configRender.php` renders tokens directly;
`rtorrent/portReservation.php` uses one exclusive writer for random attempts and
the ordered fallback. Username checks reuse the existing reservation validator.

## Consequences
The sizing map, tri-state reservation result, duplicate attempt body, and duplicate
username fallback disappear. Port ranges, random-attempt budget, exceptions,
file modes, localnet handling, and replacement order remain unchanged.
Literal render snapshots, memory boundary cases, a seeded fallback, and existing
unsafe-path tests lock behavior. No production migration is needed.

The exclusive writer also attempts to remove its uncompleted reservation when
closing the stream or checking readability throws. Failed exclusive opens never
authorize removal of occupied slots. The original throwable propagates unless
rollback itself throws, retaining PHP's usual exception precedence. Successful
reservations and ordinary refusal messages remain unchanged.
`RtorrentPortReservationCleanupSafetyTest` covers these boundaries with in-memory
streams and recorded filesystem operations; execution stays on testing machines.

If an exceptional close leaves the acquired stream live, rollback retries close
once before removing the reservation. Removal is attempted even when that retry
throws; already-closed streams are never closed again. Later cleanup throwables
retain PHP's normal precedence. `RtorrentReservationCloseSafetyTest` covers close
faults before and after release, repeated close faults, and rollback failures
without allocating filesystem reservations. Successful calls remain unchanged.

## References
- [ADR 0036](0036-pmss-owned-config-files-are-generated-from-a-template-never-parsed-and-patched.md)
- [Contracts](../contracts.md#rtorrent-configuration--scriptslibrtorrentconfigphp)
