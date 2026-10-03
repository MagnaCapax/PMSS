# ADR 0081: Stop reserving rTorrent dht and listen ports

Date: 2026-10-03
Category: architecture

## Status

Accepted

## Context

The shipped `template.rtorrent.rc` has no `##dhtPort` or `##listenPort` token;
the historical search found no such token under `etc/`. A read-only fleet check
on 2026-10-03 found the override `template.rtorrentrc` on no production host. DHT
uses the template's static `network.port_range`. The dht and listen reservations
therefore have no template consumer.

Each pool has 20,000 slots. Exhaustion throws during `createConfig()` and aborts
`userConfig.php`, causing `addUser` to roll back. On an affected host every new
account creation failed once its pool was full. The rTorrent
watchdog recovery path passed only the scgi port to `createConfig()`, so retries
every two minutes reserved new dht/listen pairs. A failed config write did not
release them. The reconciler also treated the static `network.port_range` as a
reserved listen port and skipped on uncertain ownership on many hosts.

## Options Considered

- Keep all three reservations and fix watchdog retry cleanup. The pools would
  still reserve ports with no consumer.
- Make dht/listen exhaustion non-fatal. This would retain unused state and
  uncertain reconciliation.
- Delete dht/listen reservation behavior and retain scgi reservation (chosen).

## Decision

Only scgi is acquired, reused, persisted, rendered, and reconciled. Existing
dht/listen payload keys and markers are not deleted. Termination cleanup may
still remove legacy markers for an account being removed.

## Consequences

Old dht/listen markers are inert, including in reconciliation. A full dht or
listen pool cannot prevent account creation. Templates using `##dhtPort` or
`##listenPort` are unsupported. `##dhtPort` can still have its supported
`##dht` prefix replaced; no numeric port is substituted. The scgi
lock, rollback, range, and cleanup behavior remain in place.

## References

- [ADR 0060](0060-rtorrent-config-rendering-and-reservation.md)
- Issues #844, #871, #649
- [rTorrent contract](../contracts.md#rtorrent-configuration--scriptslibrtorrentconfigphp)
