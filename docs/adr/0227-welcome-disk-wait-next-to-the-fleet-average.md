# ADR 0227: Welcome page shows disk wait next to the fleet average

Date: 2026-11-03
Category: architecture

## Status
Accepted

## Context

The Welcome tab shows the account's own usage. It does not show where the server
stands against the other servers of its plan family (GH #1191, the first step of
balancing load by showing it). The averages already exist: the public
fleet-performance feed (`https://pulsedmedia.com/data/v1/fleet-performance.json`,
built from the hallinta brain's `statsNodeIostat`, per public tier and fleet-wide).
A node knows neither its own weekly figures in the feed's definitions nor its public
tier name, so it cannot pick the right row on its own.

## Options Considered

- The brain computes per-server-group averages and pushes them to every node: a
  second source of averages that can disagree with the public page.
- The node derives its own figures from its logs: different definitions than the feed.
- The brain pushes only the node's own figures and its public tier name; the page
  reads the averages from the public feed through the pinned, cached fetch (chosen).

## Decision

The brain queues, at most once a day per allowlisted node, one command:
`php /scripts/util/remote/fleetCompare.php <tier|-> <ownPsi> <ownSvcMs> <unixTs>`
(PSI full over the past week as percent of time and response time in milliseconds,
both from the node's own `statsNodeIostat` row; the tier is the public tier name or
`-`). `fleetCompare.php` takes exactly those four arguments, refuses anything that
does not match its strict patterns (and a 10-digit timestamp) with exit 2 before
writing, refuses a symlinked target, and writes `/var/lib/pmss/public/fleetCompare.json`
(root:root 0644) through `pmssAtomicJsonFileWrite`. No new name, certificate or
port (ADR 0150).

`welcome.php` adds `/data/v1/fleet-performance.json` to the pinned allowlist of
`pmssWelcomeRemoteFetch` (https, host pulsedmedia.com, 1 MB cap) and caches it with
`pmssWelcomeHomeCachedFetch`. The page render reads the cache only; the News refresh
(`welcome.php?news=1`) refills it, so there is no new endpoint. The page adds one card
to the metric row, "Waiting on disk": the server's own value, a bar on a scale of
twice the reference average, a red tick at the average, and
`Fleet: <average>% · response <ms> ms, fleet <ms> ms`. The reference is the tier row
of the feed, or the fleet-wide row when the tier is unknown or not reporting. The
card is absent when the own-figures file or the feed is missing, not plain numbers,
or older than 48 hours. It states numbers only and never grades the server.

## Consequences

- Every panel gets a card while the feed and the node's file are fresh; the card
  appears on the page load after the first News refresh filled the feed cache.
- The grid gains a six-card layout (`h-m6`) beside the existing five-card one.
- The averages stay in one place: the public feed.
- The own-figures file is display only; no root job reads it.
- Without a push for 48 hours the card disappears.
## References

- [ADR 0016: Customer PHP tree separation from operator scripts](0016-customer-php-tree-separation-from-operator-scripts.md)
- [ADR 0150](0150-no-forced-public-identifiers-only-the-end-user-turns-on-a-public-certificate-or-record.md)
