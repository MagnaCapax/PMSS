# ADR 0113: Enforce per-user upload throttles through UID marks

Date: 2026-10-18
Category: architecture
Status: Accepted

## Context

The FireQOS renderer lost parent-level rawmark matches when users were nested under a group. Packets therefore never entered the group. Monitoring rules assigned positional marks that drifted when accounts were deleted. FireQOS's default minimum class rate of interface rate/100 raised small caps on fast links. The hourly limits cron wrote cap state but did not apply FireQOS, and reboot cleared its tmpfs markers. Finally, tenant-owned homes let a customer replace `.throttle` with an arbitrary cap.

## Options Considered

- Restore one root match per user before the first group child: matches FireQOS's flowid behavior and the existing class layout.
- Use one masked root match: fewer lines, but depends on a reserved mark namespace and mask behavior that PMSS does not maintain.
- Flatten the group: removes a routing level but loses group fairness and its ceiling.
- Apply in a separate cron: duplicates user and network state collection. Converge in the existing limits cron: one comparison after its state updates. Edge-trigger each update: misses reboot and external state changes.
- Persist cooldown markers: survives reboot but adds migration and state ownership work; retain current tmpfs behavior.

## Decision

Mark each managed user's OUTPUT packets with the account UID. Render a bare match for each UID immediately inside the users group, before its first child, then retain the UID match in each leaf class. Set interface `minrate 1Mbit`. The hourly limits cron compares desired caps with the rendered config and invokes the full network setup on drift, so iptables and FireQOS change together. Only a regular, non-symlink, root-owned `.throttle` without group or world write permission may supply a cap; the marker remains authoritative for whether throttling is active.

## Consequences

- All managed accounts' egress now passes the users group, activating balanced fairness and its 95% ceiling.
- The default guaranteed class rate falls from link/100 to 1 Mbit; low caps remain effective.
- A convergence-triggered setup reloads iptables and loses at most the current partial five-minute metering window, estimated well under 0.1% per month and in the customer's favour.
- After reboot, an account inside the three-day cooldown can be released early because its marker was on tmpfs.
- Forwarded per-user VPN traffic remains unmarked; that path is unchanged and out of scope.

## Amendment 2026-10-19: a plan's own cap outranks the default stages

Once enforcement works, the default overage stage (100 Mbit at any overage) would lower an account whose plan sets a higher post-quota cap. Some plans are sold as 1 Gbps after the quota. The hourly limits cron therefore uses an account's own `trafficCapMbit` as its post-quota cap whenever the plan sets one, and applies the overage stages and progressive throttle only to accounts without a plan cap. This matches the post-quota speed the customer panel already shows.

## References

- Refs #1056
- [Throttling architecture](../throttling.md)
- [ADR 0065](0065-traffic-limit-storage-and-cli-boundaries.md)
