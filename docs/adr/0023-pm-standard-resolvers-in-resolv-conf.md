# ADR 0023 — Converge /etc/resolv.conf onto Pulsed Media's own resolvers

## Status
Accepted (2026-06-26)

## Context
PMSS did not manage `/etc/resolv.conf`; hosts inherited whatever the base
install, DHCP, or a manual edit left behind. In practice this drifted to
external/public DNS (Google `8.8.8.8`, Cloudflare `1.1.1.1`/`1.0.0.1`).
For a privacy-first provider — and especially for customers who are themselves
privacy/VPN operators — sending every host's recursive DNS to a third party is
a privacy leak and is "not our standard." A freshly delivered server was found
using public DNS because nothing enforced PM's own resolvers (ticket 111736).

PM operates its own recursive resolvers at `185.148.1.2` and `185.148.1.3`,
verified reachable and resolving fleet-wide (confirmed from hosts on distinct
subnets, not just the resolvers' own /24).

## Decision
`pmssEnsureBootDefaults()` now also converges `/etc/resolv.conf` onto the PM
resolvers (`pmssBootDefaultsEnsureResolvConf`), alongside the existing hidepid
and grub convergence. It is self-healing (re-applied every update),
backup-preserving, replaces a systemd-resolved stub symlink with a static file,
and adds `options single-request-reopen` (mitigates the getaddrinfo A+AAAA stall
class seen on these networks).

**Reachability guard (load-bearing):** the host switches to the PM resolvers
only if at least one of them actually answers a DNS query from that host. If
none answer (e.g. a datacenter without a route to them), the existing
`resolv.conf` is left untouched — DNS is never broken to enforce the standard.

This supersedes the implicit "external resolvers" assumption documented in
ADR 0012 for the resolv.conf layer (0012's unbound-caching deferral still holds).

## Consequences
- Customer DNS stays on PM infrastructure (privacy); no third-party DNS leak by default.
- Rollout is gradual and safe: each host applies on its next `update.php`; the
  reachability guard contains blast radius if a resolver is unreachable from a DC.
- The PM resolver IPs are encoded in `pmssEnsureBootDefaults`'s call site; change
  there if the standard resolvers move.
