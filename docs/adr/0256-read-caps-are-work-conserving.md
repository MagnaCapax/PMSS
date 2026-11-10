# ADR 0256: Read caps are work-conserving

Date: 2026-11-10
Category: architecture

## Status
Accepted. Replaces ADR 0230's share-only allocation when demand observations are
provided in enforce mode. The shipped mode stays `off` per ADR 0243.

## Context
The share-only allocator divides the pool over every account, including accounts
that are not reading. A lone reader remains at its own share on an idle host.
Production measurements from 2026-11-06 through 2026-11-09 found that hosts with
caps enabled and no journal stalls read at 0.77 times the rate of uncapped hosts,
while arrays were about 29% utilised. Idle neighbours' unused shares should be
available to accounts that are reading, without reducing any existing share cap.

## Options Considered
- Keep share-only allocation: rejected because it preserves the measured loss
  despite idle device capacity.
- Cap only while device utilisation or queue depth indicates contention: not
  chosen alone because it toggles between capped and uncapped each cycle. A
  device contention gate for the pool can be considered later.
- Water-fill over measured demand, with today's cap as a floor: chosen. Reclaim
  quiet accounts' unused allocations and divide the remainder by existing weights.

## Decision
Each enforce cycle reads per-slice cgroup-v1 `blkio.throttle.io_serviced` counters.
Read rate is the counter delta divided by elapsed seconds, using the held-reader
checks: at least 60 seconds, a valid previous timestamp and non-negative monotonic
counters. A previous count older than 900 seconds gives no rate: after caps were off
or cron stalled, the delta would average idle hours into the rate and understate
demand. Unknown rates and accounts satisfying the existing held predicate at
`PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO` have unbounded demand. The predicate uses the
current kernel cap, including any explicit lower limit. Other demand is
`max(floor, rate * workConservingHeadroom)`. No demand exceeds the account's tier
ceiling or explicit cap, so pool an account cannot use goes to the others.

`workConservingHeadroom` defaults to 3.0. It must be finite, at most 100, and its
product with `PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO` must be strictly greater than 1.
This keeps a steady reader below the wants-more threshold after its demand cap
settles; a smaller headroom can alternate between a demand cap and a borrowed pool.

For each quota exponent, weighted max-min water-filling uses the normalised
shares as weights. Accounts below their fair level receive their demand; remaining
accounts share the remaining pool by weight, repeating until none are satisfied.
`proposed` uses its charged pool and `would_be` uses its own counterfactual charged
pool. Both take the maximum of today's proposal and their water-filled allocation
before the existing rounding and tier ceiling. Explicit enforced caps remain the
minimum of the explicit value and `would_be`. Fallbacks and callers omitting rates
retain the previous allocation and payload.

The observer persists timestamp and counters in its existing 0600 state file even
without host PSI or unified slice pressure. Read-only previews do not write it.
The private cycle log records each account's `rate`, `demand`, and `water` values
for both `proposed` and `would_be`. Unbounded demand is JSON `null`; arithmetic
uses infinity internally.

## Consequences
- A neighbour that starts reading is only seen on the next cycle, so protection
  lags up to one cycle and caps may sum above the pool in between. The preserved
  share floors, account floors and rounding can also make their sum exceed it.
- BFQ weights still order requests on the backing devices.
- The shipped mode stays `off` per ADR 0243; this does not address the Linux 6.1
  metadata/journal stall or authorise enabling caps.
- Pool size is unchanged: the demand ceiling, PSI/iowait steering, curb and HDD
  probe multiplier keep their existing behaviour.
- Synthetic disk feedback fixtures cover ten cycles for a disk-limited reader,
  a reader wanting 300/s, and two heavy readers. Testing-machine execution and
  controlled load verification remain required before enabling caps.

## References
- [Read caps and pool curb](0230-enforced-read-iops-with-iowait-pool-curb.md)
- [Contention without cap stall](0231-read-caps-enforced-by-default-and-contention-without-cap-stall.md)
- [Caps off after journal stall](0243-read-caps-off-by-default-after-the-linux-6-1-journal-stall.md)
- [Allocation and operational inspection](../cgroup.md#enforced-read-iops-rollout)
