# ADR 0026: Reserve managed service ports from kernel ephemeral selection

Date: 2026-06-26
Category: architecture

## Status
Accepted

## Context
PMSS widens `net.ipv4.ip_local_port_range` to `1024 65535` as part of the
managed sysctl baseline. That gives the kernel permission to choose nearly any
non-privileged TCP port as an ephemeral source port for outgoing connections.

PMSS also assigns long-lived per-user service ports inside that same numeric
space. When the kernel temporarily chooses one of those static service ports as
an outgoing connection's source port, the service cannot re-bind after a restart
until the unrelated connection closes. That turns a normal watchdog restart into
a customer-facing outage.

The managed service allocator currently owns `2000-38000`. The broader legacy
port space is larger, but reserving the whole historical union would leave too
few ephemeral ports for high-connection workloads.

## Options Considered
- Option A - Preserve the full widened ephemeral range without reservations.
  This keeps maximum source-port headroom but allows kernel ephemeral selection
  to collide with PMSS-managed static service ports.
- Option B - Reserve the entire historical static-port union. This protects more
  potential bind ports but leaves only a small high/low remainder for outgoing
  ephemeral connections.
- Option C - Reserve the current PMSS port-manager service band from kernel
  ephemeral selection. This protects the watchdog-managed service band while
  keeping an ephemeral pool close to the default Linux width.
- Option D - Narrow `ip_local_port_range` above all PMSS service ports. This can
  be a clean end-state once all service allocation is confined, but it changes
  the global source-port range more broadly than required for the immediate
  outage class.

## Decision
Choose Option C.

The sysctl baseline sets `net.ipv4.ip_local_reserved_ports` to the current
PMSS port-manager band, sourced from `PMSS_PORT_MANAGER_MIN_PORT` and
`PMSS_PORT_MANAGER_MAX_PORT`. Keeping the value tied to the allocator constants
prevents drift between the managed service namespace and the kernel carve-out.

The reservation is intentionally scoped to the managed service band. Future
work that confines all PMSS and media-stack bind ports to a single namespace can
replace this with a narrower or more complete end-state, but that belongs with
the allocator unification work.

## Consequences
- Positive: kernel ephemeral source-port selection no longer steals the
  watchdog-managed service ports in the PMSS port-manager band.
- Positive: the widened ephemeral range remains available outside the managed
  service band, preserving outbound connection headroom.
- Negative: ports not allocated by the PMSS port manager remain outside this
  immediate reservation.
- Follow-up: co-design the final service-port namespace with the port-allocation
  and release-tracking work, then update this ADR if the reserved band changes.

## References
- PMSS issue #653
- PMSS issue #651
- PMSS issue #649
