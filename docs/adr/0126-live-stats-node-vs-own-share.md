# ADR 0126: Live stats — node total versus the customer's own share

Date: 2026-10-17
Category: architecture

## Status

Proposed (prototype validated on a playground host; discussion in MagnaCapax/PMSS#1071).

## Context

"Is my box slow, or is the node?" is a frequent support question. Customers can
see their own counters (`stats.php`, `~/.resourceData`, `~/.trafficData`) but
not how those relate to the whole node, and there is no 24 h trend. The raw
inputs mostly exist already: `resourceLog.php`, `trafficLog.php` and
`trafficIngressLog.php` write per-user five-minute samples. Missing are the
node totals in the same shape (CPU time, RAM in use, network bytes).

## Options Considered

- Read live counters on every page view: needs privileged data, costs CPU per
  request and cannot give averages. Rejected.
- Third-party monitoring daemon (netdata, see #3): new service and attack
  surface per node. Rejected.
- Sample once per second or minute: needless load. The data already exists at
  five-minute resolution, which is enough for 15 min / 1 h / 24 h averages.
- Reuse the existing five-minute logs and add only the node totals, kept in a
  fixed-size ring buffer: selected.

## Decision

`scripts/cron/liveStatsLog.php` runs at minute 1 of every five-minute slot,
after the collectors it depends on. It does not read or flush any per-user
counter itself; it reads only the newest line of the existing per-user logs
(ignoring lines older than seven minutes) and three node files
(`/proc/stat`, `/proc/meminfo`, `/proc/net/dev` of the interface `detectPrimaryInterface()` returns; `PMSS_LIVESTATS_IFACE` overrides it for hosts whose PMSS network config names another NIC).

Each run appends one sample to two kinds of ring buffers (288 slots, 24 h):

- `/var/lib/pmss/public/node-live.json` — node sums only, world-readable, same
  pattern as `host-pressure.json`.
- `/var/lib/pmss/live/<user>.json` — that user's samples, `root:<user> 0640`
  inside a `root:root 0711` directory (users cannot list it).

The customer panel (`etc/skel/www/statsLive.php`) reads
those two files and renders averages for 15 min, 1 h and 24 h plus a 24 h trend
as server-side SVG. No JavaScript, no polling, no `/scripts` access (ADR 0016).

Privacy rule: a customer sees the node total and their own numbers, never
another customer's samples. Ring files are not placed in `/home/<user>` so a
tenant cannot replace them with a symlink that a root writer would follow.

Scope: cgroup v1 production hosts (ADR 0019). No v2-specific code is added; the
per-user samples come from the logs that already handle the cgroup mode.

## Consequences

- One small cron job per host; the per-account cost is two short log-tail reads
  and one JSON rewrite. Revisit if hosts carry thousands of accounts.
- Disk: about 21 KB per account (288 samples), rewritten every five minutes.
- A user log line is counted once: a line at or before the previous sample's time
  is ignored, so an early, repeated or late collector run never double counts.
  If a collector finishes after minute 1, its value lands in the next slot.
- Ring files of accounts that no longer exist are removed on each run, so a
  re-used username does not inherit another tenant's history.
- Node CPU/RAM/network are host-wide, so the share of a customer is a share of
  host capacity, not of their quota. Quota-relative figures stay in `stats.php`.
- `www-data` is part of the managed account list and gets a ring file as well.
- Log timestamps are local time; a DST fall-back hour can hide one slot of user
  values (the node totals are unaffected).
- Log-tail reads return the existing unavailable result (`null`) when file size,
  seek, or read fails, and reject NUL-containing paths before filesystem calls.
  Opened streams close even when those operations throw; the original exception
  propagates. Successful tail selection and partial-line handling are unchanged.
