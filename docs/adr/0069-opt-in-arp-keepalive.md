# ADR 0069: Opt-in ARP keepalive

Date: 2026-09-26
Category: architecture

## Status

Accepted (implemented, default-OFF; enabled per host by a marker file)

## Context

On one network segment the upstream router drops a host's ARP entry at its
fixed ARP age (a periodic ~4h rhythm) and then fails to re-resolve the host for
one to several minutes. Inbound traffic to the host is blackholed during that
window while the host itself stays up with clean counters (#900). The fault is
on the router side; its root cause is not yet proven.

A host-side remedy has been measured twice on outcome. Hosts that periodically
made the kernel broadcast an ARP request carrying their own address had zero
outages while untreated hosts on the same segment kept having them: 0 vs 15
episodes on customer-operated hosts, then 0 vs 8 on two independently chosen
hosts with a separate implementation. The mechanism that makes it work is not
proven; the outcome is.

## Options Considered

- **A: Router-side fix only** (static ARP entries or retry-timer tuning). This
  addresses the router directly and is pursued separately, but it needs router
  access and does not help until applied. It is not a reason to leave hosts
  exposed meanwhile.
- **B: Bespoke per-host script outside PMSS.** Drifts, is invisible to the
  update path and is not tested. REJECTED.
- **C: Always-on keepalive for every host.** Changes behaviour fleet-wide on
  segments that do not have the fault. REJECTED.
- **D: Opt-in cron job: one datagram per run to an unused on-link address named
  in a per-host marker.** CHOSEN. Inert without the marker, reversible by
  deleting it, and enabled host by host.

## Decision

`scripts/cron/arpKeepalive.php` runs every 10 minutes from `root.cron`. It does
nothing unless `/etc/seedbox/config/arp-keepalive` holds exactly one IPv4
address. It then sends one UDP datagram to that address (port 9, discard)
only when:

- the address is on-link (`ip -4 route get` shows no gateway), and
- nothing answers it (its `/proc/net/arp` entry is not complete).

An answering address is refused with a WARN line: the kernel would stop
broadcasting for it, so the keepalive would silently stop working. Output is one
line per run to `/var/log/pmss/arpKeepalive.log`, which logrotate bounds.

## Consequences

- Default fleet behaviour is unchanged. Hosts without the marker run a no-op.
- Enabling is an operational decision per host. It must follow the operator's
  batch rollout rules, like any other configuration change.
- The operator picks the target address. It must stay unused. If it is ever
  assigned, the job logs a WARN every run instead of failing silently.
- Rollback: delete the marker. Nothing else is changed on the host.
- When the router-side fix lands, the markers can be removed and the job is
  inert again.
