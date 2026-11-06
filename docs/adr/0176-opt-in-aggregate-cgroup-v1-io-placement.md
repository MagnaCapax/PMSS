# ADR 0176: Opt-in aggregate cgroup-v1 I/O placement

Date: 2026-10-25
Category: architecture

## Status
Accepted. ADR 0231 makes placement the default; ADR 0233 removes the host-local policy file.

## Context
Production uses cgroup v1 (ADR 0019). The direct IOPS writer validates and writes
only files in the account slice. Default per-unit I/O accounting creates child
blkio groups for the account's scopes and services, but v1 throttling treats
those groups independently. Slice caps therefore miss ordinary daemon I/O.
The kernel's blkio documentation describes this flat throttling behavior.

Issue #1171 reports testing-machine placement and direct-I/O measurements on
Debian 10/11/12. Disabling default I/O accounting moves system scopes into the
slice at manager reexec and places new processes there. The corrected reports
state that existing delegated user-manager processes remain in child groups
until their manager restarts. Those measurements are issue evidence, not tests
performed by this implementation session.

## Options Considered
- Write limits to every child: widens the protected sysfs allowlist, multiplies
  the aggregate cap by the number of groups and leaves new scopes uncapped.
- Disable default per-unit accounting immediately: preserves aggregate semantics
  but activates previously ineffective caps without measuring customer impact.
- Add an explicit placement opt-in and a read-only census: preserve today's
  default behavior and the slice-only guard while allowing controlled activation.

## Decision
Choose the placement opt-in. `cgroup.ioSlicePlacement` defaults to boolean false;
only boolean true on a detected v1 host installs the PMSS manager drop-in from
`template.systemd.io-placement.conf`. The existing runtime-template flow converges
it before daemon-reexec. Slice accounting remains explicit; default per-unit
accounting is disabled. Disabled policy or non-v1 mode removes only this drop-in.
The template and managed-file writer follow ADR 0036.

Host opt-ins live in the operator-owned `cgroup.policy.local.php`, which the
repository never ships. The shared policy loader recursively replaces shipped
defaults with this array; unspecified defaults remain current. The seedbox
overlay preserves files absent from the snapshot, so updates retain the opt-in
and the managed placement drop-in. Non-array returns and symlinked local files
are ignored. Set the local switch to false (or remove the local key) to restore
the shipped default during the next convergence. This also preserves host-local
shadow-measurement settings without changing the shipped off defaults.

`cgroupIopsLimitApply.php --census` reuses the actual writer's cap computation and
exits before every throttle write. Its TSV output compares caps with 24-hour
nearest-rank read-IOPS P95 from existing bounded telemetry and exposes interval
and child-directory counts. Missing data stays unavailable, and output warns
that slice-local history can omit child I/O. This diagnostic cannot by itself
establish safe production cap values.

No account services are restarted and no processes are moved by this code.
Existing delegated managers need a planned restart or reboot followed by a
membership check. Explicit host-local unit I/O properties can still create
child groups; activation requires checking those overrides.

## Consequences
- [ADR 0230](0230-enforced-read-iops-with-iowait-pool-curb.md) adds a separate
  host opt-in for pressure/quota read-cap enforcement, including md-backed
  storage. This placement opt-in and live membership checks remain prerequisites
  for proving an effective aggregate cap; neither switch enables the other.
- The slice remains the single aggregate enforcement point; its path validator,
  numeric constraints, root-account exclusion and BFQ writer are unchanged.
- Default updates retain current behavior. Activation also makes the slice's
  bandwidth limits and local I/O accounting effective for newly placed tasks.
- Per-unit I/O accounting for system services is no longer the default after
  opt-in; explicitly configured accounting still applies.
- Dry runs leave configuration untouched. Rollback removes the managed drop-in
  during the next runtime-template convergence and uses its existing reexec.
- Hermetic coverage checks opt-in types, mode selection, rollback, unsafe paths,
  dry runs and telemetry boundaries. Live placement, fio, Docker and BFQ rollout
  validation remain testing-machine work. No production activation occurs here.
- Buffered writes and monthly-clamp persistence are separate defects.

## References
- [Issue #1171 and its corrected testing-machine evidence](https://github.com/MagnaCapax/PMSS/issues/1171)
- [Kernel blkio hierarchy semantics](https://www.kernel.org/doc/html/latest/admin-guide/cgroup-v1/blkio-controller.html#hierarchical-cgroups)
- [Production cgroup-v1 pin](0019-production-cgroup-v1-pin.md)
- [Generated managed configuration](0036-pmss-owned-config-files-are-generated-from-a-template-never-parsed-and-patched.md)
