# ADR 0114: PMSS hosts are IPv4-only; IPv6 stays disabled

Date: 2026-10-18
Category: architecture
Status: Accepted

## Context

PMSS hosts already run without IPv6. The system-prep sysctl baseline sets `net.ipv6.conf.all.disable_ipv6=1` and `net.ipv6.conf.default.disable_ipv6=1` (`scripts/lib/update/systemPrep/sysctlSettings.php`). ProFTPD is rendered with `UseIPv6 off`, the media-stack installer writes Jellyfin's network config with `EnableIPV6` false, and per-user traffic marking and shaping are built on IPv4 `iptables` rules (ADR 0113). There is no `ip6tables` rule set. None of this was recorded as a decision, so IPv4-only code paths keep getting reported as "IPv6 gaps". This ADR records the decision and the reasons.

IPv4 cannot be dropped: much of what a seedbox talks to, trackers, peers and clients alike, is reachable only over IPv4, and each host is reached by its public IPv4 address. IPv6 would therefore never replace anything. It could only be added next to IPv4.

## Options Considered

- **Dual stack on every host.** Peers with IPv6-only connectivity could connect directly, and IPv4 stays as it is. Every network surface would exist twice: firewall and owner-match rules, per-user marks and shaping classes, monitoring, address plans, reverse DNS and mail authentication records, and DNS records that can drift. The upstream network would have to carry a full IPv6 routing table, which needs more forwarding-table memory per route than IPv4 and brings router hardware replacement forward. If accounts got their own IPv6 addresses, each would be identifiable more narrowly than by the shared host address.
- **IPv6 only for selected services.** Fewer surfaces, but it still needs a second address plan, a second firewall policy and per-service exceptions, and it gives the seedbox product no capability it lacks today.
- **IPv4 only (current state).** One address family, one firewall policy, one shaping path, one set of records. Peers that can only use IPv6 are not reached directly. Trackers and the peer pool a seedbox depends on remain reachable over IPv4, so the product does not lose function.

## Decision

PMSS hosts are IPv4-only. The sysctl baseline keeps IPv6 disabled, and PMSS does not configure IPv6 addresses, routes, firewall rules or services. The reasons, in order of weight:

1. **No advantage for the product.** IPv4 is mandatory and already gives every service full reachability. IPv6 adds no capability a seedbox user needs.
2. **It is purely additive cost.** Because IPv4 cannot go away, dual stack doubles the network surface that must be configured, tested, monitored and kept correct, permanently. That is lasting maintenance and sysadmin burden, not a one-time migration.
3. **Routing.** IPv6 routes tend to be worse than IPv4 routes in practice, and a full IPv6 routing table already takes more forwarding-table memory than IPv4 in the routers that must carry it. Dual stack would consume router capacity faster for no product gain.
4. **Privacy.** Accounts on a host share the host's public IPv4 address, which gives every account the same network identity. Per-account IPv6 addresses would make each account individually identifiable, which works against the privacy the service exists to provide.
5. **Operability.** Short IPv4 addresses can be read, typed and compared by people during operations. IPv6 addresses in practice can only be copied and pasted.

This decision can only be changed by a superseding ADR.

## Consequences

- IPv4-only code paths are correct by design. Shaping, marking, firewall and monitoring code that handles only IPv4 is not a defect and must not be reported as an "IPv6 gap".
- New PMSS code must not add IPv6 configuration. A component that enables IPv6 by default (an application listener, an installer flag) is configured to IPv4, as ProFTPD and the media stack already are.
- `template.wireguard.wg0` still sets `net.ipv6.conf.all.forwarding=1` in its `PostUp`. With IPv6 disabled the setting has no effect. It is residue, not an IPv6 feature.
- The sysctl removes IPv6 addresses but not the protocol. Applications that listen on the IPv6 wildcard keep working and accept IPv4 connections as mapped addresses. An application that needs a routable IPv6 address does not work on PMSS hosts.
- Peers that can only use IPv6 cannot connect to a PMSS host directly.

## References

- `scripts/lib/update/systemPrep/sysctlSettings.php` (IPv6 disable in the sysctl baseline)
- `etc/seedbox/config/template.proftpd`, `etc/skel/install-media-stack.sh`
- ADR 0113 (per-user upload throttle enforcement through IPv4 `iptables` marks)
