# ADR 0202: vm.min_free_kbytes keeps the 1 GiB baseline floor on every profile

Date: 2026-10-29
Category: system-prep

## Status

Accepted

## Context

The hardware-aware sysctl builder (#221) set out to make the long-standing
legacy `99-pmss.conf` values the baseline and adapt to hardware above it. That
file sets `vm.min_free_kbytes = 1048576` (1 GiB). The builder departed from it
in two places:

- A virtual machine with swap got a fixed 131072 (128 MiB) reserve. That value
  came from an ad-hoc guest tuning experiment. It was never a decided design.
- Every other profile used `max(131072, ram_gb * 5120)` (10240 on fast swap).
  On hosts below roughly 200 GiB of RAM, that falls under the 1 GiB baseline.

`vm.min_free_kbytes` is the kernel's free-memory reserve, not the memory a
machine has. A low reserve leaves less headroom for atomic and network
allocations when page cache fills memory, and seedbox workloads fill page
cache. The smallest PMSS hosts in production have about 8 GiB of RAM, and the
fleet has run the 1 GiB legacy value for years, including guests of about
10 GiB.

## Options Considered

- Keep the 128 MiB VM special case and the 128 MiB floor.
- Remove only the VM special case and keep the 128 MiB floor.
- Use the 1 GiB baseline as the floor for every profile and keep the RAM
  scaling and the existing 2 GiB / 4 GiB caps above it.

## Decision

`vm.min_free_kbytes = min(cap, max(1048576, ram_gb * factor))` for every
profile. The factor is 5120, or 10240 on fast bare-metal swap. The cap is
2097152, or 4194304 on fast bare-metal swap. A virtual machine has no separate
value: it gets the same floor and RAM scaling as any host with slow swap.

## Consequences

- VMs with swap move from 128 MiB to at least 1 GiB, which matches the value
  the legacy file already applies on most of them.
- Bare-metal hosts below the scaling crossover rise to 1 GiB. Large and
  fast-swap hosts keep their current values.
- A future reserve below 1 GiB needs a new ADR backed by a measured reason. It
  must not quietly come back as a per-profile special case.
