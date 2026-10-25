# ADR 0173: One cgroup CLI plan handoff

Date: 2026-10-24
Category: architecture

## Status
Accepted

## Context
The cgroup CLI unpacked parsed options and repeated IO properties into local
arrays, then passed three parallel collections through display, finish and
step-building calls. Live-property filtering and apply execution were private
Manager responsibilities despite the existing input, profile and IO modules.

## Options Considered
- Keep the parallel argument lists and the separate returned step list.
- Add a plan class and separate executor, expanding the object model.
- Pass one array through the existing stages and finalize it in one module.

## Decision
Parsing starts the plan's `options` and ordered `ioProperties`. The manager
adds account identity, actions, `ioCostWrites` and computed `properties`;
IO profiles update that same plan. `plan.php` owns live-property filtering,
display handoff, apply guards, complete step construction and ordered execution.
The public Manager resource-sizing methods remain available.

Two handoff mechanisms (parallel option/IO argument groups and the private
finish/build protocol) become one plan finalization boundary: concepts delta -1.
There is no alternate executor or compatibility implementation in the tree.

## Consequences
- CLI stdout, stderr, return codes, flag precedence and property order stay fixed.
- Existing-property filtering still precedes display. Empty plans skip apply;
  dry runs skip root checking; root-slice refusal precedes every write.
- All commands are built after root checking and before execution. A nonzero
  command still allows remaining steps; a thrown exception still propagates.
- Existing cgroup-v2 compatibility paths are retained without new behavior;
  production remains cgroup v1 under ADR 0019.
- Characterization cases lock exact output, command labels and quoting, wipe
  order, dry runs, empty plans, failed applies and the v1 io.cost skip. Existing
  profile snapshots retain defaults, repeated IO flags and override precedence.

## Security Review
The privilege boundary is retained: unit property changes remain privileged
systemd operations, root slices remain refused, and IO targets retain their
existing validation. Cgroup-v1 release-agent privilege escalation
(CVE-2022-0492) is a relevant failure class; this path never writes release_agent.
An incorrect resource target can affect another account's resource availability,
so identity, validation, root-check and command ordering are behavior locks.
No credentials, secret files, logging fields, listening ports or public names
are introduced or changed.

## References
- [Cgroup v1 production pin](0019-production-cgroup-v1-pin.md)
- [CLI contract](../contracts.md)
- [systemd privilege requirements](https://www.freedesktop.org/software/systemd/man/latest/org.freedesktop.systemd1.html#Security)
- [CVE-2022-0492 disclosure and kernel fix](https://seclists.org/oss-sec/2022/q1/123)
