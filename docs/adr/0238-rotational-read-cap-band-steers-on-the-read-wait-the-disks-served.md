# ADR 0238: Rotational read-cap band steers on the read wait the disks served

Date: 2026-11-07
Category: architecture

## Status
Accepted. Replaces the latency input of ADR 0236 on rotational `/home`. The
band, step, bounds and fallbacks of ADR 0236 and the solid-state band of
ADR 0237 are unchanged.

## Context
ADR 0236 steered on the mean of three five-minute `iopingHomeMs` values. Each
value is the median of 60 O_DIRECT 4 KiB reads over ioping's default 1 MiB
working set. On a busy array most of those reads are served from a cache, so
the median follows the share of cache hits, not the wait on the disks:
- On one hard-disk guest, 86% of the 60 reads came back in under 1 ms. The
  median was 0.2 ms while that host's iostat read wait on `/home` spends most
  five-minute samples between 15 and 40 ms.
- On another, the median was 16.9 ms (mean 26.5 ms, 90th percentile 72 ms)
  while the disks served reads at 75-84 ms. Below 20 ms the band raised the
  multiplier on an array already past the point where more load delivered more
  reads.

`iostat-history.log` already records `diskAwait`, the iostat `r_await` of the
`/home` leaf-disk group (a 120 s average every five minutes). On hard-disk
hosts with mostly random reads, bins of that history from before caps show
delivered read work reaching its maximum at a read wait of about 20-27 ms, and
not rising beyond it. That is the band ADR 0236 set.

## Options Considered
- Keep the ioping median: it reads 0.2 ms on a used array; rejected.
- Use the ioping mean or a high percentile: still a 1 MiB working set,
  dominated by how much of it a cache holds; rejected.
- A per-host target read from each host's own history (the read wait at which
  delivered work peaks): the same rule gave 0.5-110 ms across twelve hosts,
  because sequential bursts and bulk reads deliver their maximum at very
  different waits. A single rule on passive history is not reliable yet; not
  chosen now. The records written under this ADR carry the multiplier, pool and
  the read wait each cycle, which is the data to measure a host's response to
  its own caps.
- Steer on `diskAwait`: chosen. It is the wait the disks actually served, it
  is already collected, and it needs no new probe.

## Decision
- `pressure.latency_ms` is the mean of the valid `diskAwait` values in the last
  three five-minute samples. A missing value is skipped; none in all three
  gives no latency, as before.
- `pressure.ioping_ms` carries the former ioping mean. Solid-state `/home`
  steers on it against the reference of ADR 0237. Rotational and unknown media
  steer on `latency_ms`.
- Settings, band (20-25 ms), step (1.1), bounds, the steer file and fallbacks
  are unchanged.

## Consequences
- On a busy rotational array the multiplier now falls when the disks wait more
  than 25 ms, whatever the ioping cache hits say; on an idle one it rises.
- A host with no reads reports a read wait of 0 and loosens, as an idle disk
  should.
- Hosts that never reach a 20 ms read wait (lightly loaded arrays) stay at the
  loosest multiplier; caps do not bind there.
- `diskAwait` averages over 120 s; the 15-minute lag of ADR 0236 is unchanged.
