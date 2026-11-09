# ADR 0247: rTorrent configuration source and text boundaries

Date: 2026-11-09
Category: architecture

## Status
Accepted

## Context
The configuration facade mixes source selection, shallow defaults, text parsing,
two exact-line migrations, file publication and allocation. Template normalization
builds separate removal/replacement tables from its catalog and has two duplicate
gates. Reservation acquisition also embeds ownership decoding, and reconciliation
projects already-valid port sets through nested loops.

## Options Considered
- Move methods without simplifying: retains duplicate workflows and derived rules.
- Replace the public class or reservation schema: breaks established consumers.
- Preserve facades while separating acquisition and text policy and unifying their
  existing flows (chosen).

## Decision
Keep the public class, protected allocation hooks, reservation includes and all
existing functions. `rtorrent/configDefaults.php` owns injected/stored source
selection and shallow defaults. `configText.php` owns assignment parsing and exact
encoding/localnet repairs. Both migrations use one read/compare/write controller.
Keep path guards and metadata-preserving publication together in `writeConfig()`.
The empty home-root prefix directly represents `/`, without a slash sentinel.

`rtorrent/portSources.php` owns the unchanged reservation schema and pure payload
and config-line decoders. Acquisition retains its existing file checks, canonical
precedence and uncertainty rules. The locked reconciler unions their true-valued
integer-keyed port sets directly. Template normalization uses catalog dispatch,
inline alias substitution and one duplicate gate. It retains whitespace-only
legacy values and the narrower duplicate rule for already-modern lines.

## Consequences
Control-flow concepts decrease by three using this explicit accounting:
two derived rule tables, an inline closure, a duplicate gate, a duplicated
migration I/O path, two port projection loops, a single-caller target adapter,
and two home-root intermediate states disappear (-10). A dispatch flag,
an assignment split, a shared migration controller, three pure text helper
boundaries and a pure ownership-decoder boundary are introduced (+7).
Relocated acquisition methods and reservation helpers retain their roles.
Runtime source lines decrease; tests and documentation are counted separately.

Characterization fixtures lock source precedence, shallow defaults and exceptions,
assignment order, LF/CRLF and exact repair bytes, no-op/write-failure results,
inline boundaries, duplicate policy, ownership uncertainty and port-set union.
Existing rendering, allocation, metadata, path refusal and reconciliation cases
continue through the same facades. No parallel implementation or migration is
needed. Tests execute only on the testing machine; local verification uses PHP
syntax checks and deleted-symbol searches. No service or provisioning dry run
executes on the development box.

## References
- [ADR 0060](0060-rtorrent-config-rendering-and-reservation.md)
- [ADR 0081](0081-stop-reserving-rtorrent-dht-and-listen-ports.md)
- [ADR 0141](0141-rtorrent-016-family-launch-and-rc-guard.md)
- [Configuration contracts](../contracts.md#rtorrent-configuration--scriptslibrtorrentconfigphp)
