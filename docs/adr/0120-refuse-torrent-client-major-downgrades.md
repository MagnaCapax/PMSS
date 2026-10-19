# ADR 0120: Refuse recorded torrent-client major downgrades

Date: 2026-10-19
Category: domain
Status: Proposed

## Context

The per-account switches in ADRs 0106 and 0119 snapshot configuration before
changing versions, but an older client may still rewrite newer resume data or
other state in a form that cannot be recovered automatically. The account's
private `<client>.lastRun` file already records the last selected version.

The published `versions[].version` examples use numeric releases such as
qBittorrent `5.1.0`, Deluge `2.1.1`, and Transmission `4.1.3`. The shipped
`system` Deluge entry is not a numeric release. The development host has no
live `/var/lib/pmss/public/torrentClients.json` projection. qBittorrent's
version string does not encode the libtorrent 1.2 versus 2.0 family.

## Options considered

- Allow every switch and rely on the existing backup: a daemon can alter state
  before a failed launch or rollback is detected.
- Refuse every unrecognized version scheme: this would strand legitimate
  selections such as `system` and future catalog formats.
- Refuse only a provable decrease in the numeric major family recorded in
  `lastRun`, using one customer-side comparison helper for all three clients.

## Decision

Inside each client's existing switch lock, compare the previous `lastRun` with
the requested numeric release before backup, stop, link replacement, or start.
Refuse a lower major with a private notice and a support-directed message.
First selections, equal majors, and higher majors proceed. Missing, invalid,
or incompatible version schemes remain indeterminate and proceed through the
existing backup and recovery path. The comparison has no new persisted state.

The test suite compares the root Transmission settings writer with the
customer-side settings contract, preparation, and launch readiness check.
The customer launcher uses one managed-key definition for preparation and
readiness; runtime code never crosses the customer/operator deploy boundary.

## Security and recovery

[CVE-2018-5702](https://nvd.nist.gov/vuln/detail/CVE-2018-5702)
illustrates the consequence of exposing Transmission RPC to a browser-accessible
network listener: arbitrary RPC commands and file writes. This change keeps
the existing mode-0700 Unix socket and account htpasswd proxy; it adds no bind,
credential, log, or permission path. The settings test verifies the same
authentication, whitelist, socket mode, and URL values in both deploy trees.
The private notice contains versions only, never an account password.

## Consequences

This guard prevents known numeric major downgrades without interrupting a
running daemon. A deliberate cross-family rollback requires support to migrate
or restore state. Numeric qBittorrent versions alone cannot detect a change
between libtorrent families within the same qBittorrent major; adding that
protection would require durable, validated library-family history in the
account state. Unknown version schemes retain the existing backup risk by
design, rather than blocking a legitimate customer switch.
