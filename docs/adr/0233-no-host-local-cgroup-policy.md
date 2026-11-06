# ADR 0233: No host-local cgroup policy

Date: 2026-11-06
Category: architecture

## Status
Accepted. Removes the host-local policy file added under ADR 0176.

## Context
`pmssCgroupPolicyLoad` read `cgroup.policy.local.php` on top of the shipped policy and
the updater preserved it. It was added so a feature could be switched on host by host.
That made per-host configuration a supported path: hosts diverged from the repository
without the repository showing it, and a setting that was meant for every host had to
be written by hand on each. ADR 0231 made the read-limit features the default, so no
setting needs the file.

## Options Considered
- Keep the file for opt-outs: every opt-out is a host that runs different software
  from the rest; rejected.
- Keep the file and warn when it exists: the divergence stays; rejected.
- Read only the shipped policy: chosen.

## Decision
- `pmssCgroupPolicyLoad` returns the shipped `cgroup.policy.php` only. A leftover
  `cgroup.policy.local.php` is ignored.
- Every host runs the same policy. A setting changes in the repository and reaches
  hosts through the rolling update.
- The diagnostics catalog keeps reporting whether the file exists, so leftovers can be
  found and removed.

## Consequences
- A host that had a local file runs the shipped policy after its next update.
- There is no per-host opt-out from a cgroup default; changing a default is a
  repository change.
