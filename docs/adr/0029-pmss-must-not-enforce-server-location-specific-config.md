# ADR 0029: PMSS must not enforce any server/location/network-specific config

Date: 2026-07-09
Category: architecture

## Status
Accepted (2026-07-09). Generalizes ADR 0030 (PMSS must not manage `/etc/resolv.conf`) from
the single resolv.conf case to the whole class of server/location/network-specific
configuration. ADR 0030 remains the canonical worked example of this rule.

## Context
PMSS is a **portable Debian overlay** that can run on ANY host, including third-party /
rented servers that are NOT on Pulsed Media's network. ADR 0030 established, for
`/etc/resolv.conf` specifically, that PMSS must not bake in PM-network resolver IPs: a PMSS
install on a non-PM host would have its DNS continually rewritten toward unreachable
resolvers, breaking the box.

The same reasoning applies to EVERY server/location/network-specific setting, not just
resolv.conf. Any config the overlay *enforces on update* that encodes a specific host's
network position — resolver/nameserver addresses, `/etc/hosts` site entries, hardcoded IPs or
address ranges, site hostnames or DNS search domains — will break or churn a PMSS install on
any host off that network. A reachability guard that masks the breakage on PM hosts does not
make the layer correct.

Operator directive 2026-07-09:

> "PMSS codebase enforcing nameservers — it should not enforce local configs ever! In any
> manner or form, any server- and location-specific setting should be sysadmin one-time
> configs, not enforced by the config."

## Decision
**PMSS MUST NOT define or enforce ANY server/location/network-specific configuration.** This
includes, but is not limited to:

- DNS resolver / nameserver content
- `/etc/hosts` site entries
- hardcoded network IPs or address ranges
- site hostnames or DNS search domains
- any other location- or network-specific addressing

Such state belongs exclusively to the **one-time provisioning layer** (network install /
provisioning profile / rescue-debootstrap step), applied ONCE at build time. The portable
overlay stays network-agnostic and never re-applies it on update.

**Reviewer rule (layer-check) for every PMSS change:** *"Would this change break or churn a
PMSS install on a rented box on a third-party network?"* If yes → wrong layer, reject.

## Consequences
- PMSS remains installable and correct on ANY network — portability is the product value.
- A PM host's location-specific config (internal resolvers, site addressing) comes from the
  provisioning layer, applied once — not from the overlay.
- Any PR adding location-specific config into the overlay is rejected by this ADR.
- Audit for and removal of any existing instances is tracked in GH #703.

## Origin
- Generalizes ADR 0030 (2026-06-26, resolv.conf case).
- Operator directive 2026-07-09.

## References
- ADR 0030: PMSS must not manage /etc/resolv.conf
- GH #703
