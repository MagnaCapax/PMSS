# ADR 0019: Production cgroup v1 pin (GRUB unified_cgroup_hierarchy=0)

Date: 2026-05-29
Category: architecture

## Status
Accepted. Supersedes ADR-0003 in part (the "v2 adoption" framing) for deployment-state purposes; ADR-0003's dual-path detection + v1/v2 template infrastructure remains in force as code-portability defense.

## Context

ADR-0003 ("Cgroup v2 adoption with dual-path detection and policy floors/caps") documented the software architecture for supporting both cgroup v1 and v2 hosts via kernel-mode detection + dual templates. The framing implied gradual migration as Debian 11/12 default to v2.

Production deployment reality diverged from that framing. Every PMSS host is pinned to cgroup v1 via `systemd.unified_cgroup_hierarchy=0` in `/etc/default/grub`, regardless of Debian version. The pin is operationally required for:

- **Rootless Docker compatibility**: `docker-ce-rootless-extras` chain on the supported configurations expects v1 controller hierarchy semantics; the `dockerd-rootless.sh` path is tested against v1.
- **`hidepid=2` interaction**: the user-namespace + cgroup-namespace overlay that v2 reshuffles breaks the `hidepid=2` posture PMSS uses for tenant isolation on `/proc`.

Result: ADR-0003's "v2 adoption" framing is incorrect for production. The dual-path detection code is correct and used, but the v2 branches see zero traffic in the deployed fleet.

Concrete incident: on 2026-05-29, an autonomous issue (PMSS#597) was generated with the premise "customers on cgroup-v2 hosts get the bonus disk but NOT the bonus I/O priority — silent inconsistency." An implementing agent built a v2 sibling writer faithfully (commit on PMSS main), and the operator reverted on noticing the target host population is empty. The doctrine gap (no architectural pin documented) was the upstream cause.

## Options Considered

- **Option A — Amend ADR-0003** with a "Production deployment" section. Reject: mixes two contradicting decisions (the original "v2 adoption" framing and the actual "v1 pin") in one document. Future readers see internal contradiction. AGENTS.md governance rule (line 54): "One ADR decides one subject."
- **Option B — Mark ADR-0003 Deprecated, write a new ADR for the v1 pin**. Reject: ADR-0003's dual-path detection + template infrastructure remains correct and used. "Deprecated" overstates the scope of supersession.
- **Option C — New ADR (this one) supersedes ADR-0003 in part**. The new ADR documents the production v1 pin as the operative architectural decision; ADR-0003 stays in force for the code-portability framing (dual templates, kernel detection, lifecycle hooks). Selected.

## Decision

- Production pins cgroup v1 on every host regardless of Debian version, via `systemd.unified_cgroup_hierarchy=0` in `/etc/default/grub`. This pin is part of the install bootstrap (`docs/install.md`) and is verified at install time.
- The dual-path detection in `scripts/lib/cgroup/RealSystem.php` and the v1/v2 templates in `etc/seedbox/config/template.cgroup.user-slice.*.conf` are retained as defense-in-depth. They make the software portable to v2 if the GRUB pin is ever removed; they are not production-active.
- New feature work targeting cgroup-v2 hosts (sibling writers, v2-mode appliers, v2 `io.weight` handlers, v2-specific bonus logic) is **forbidden without explicit operator override** that removes the GRUB pin. The target host population is empty; such work ships irrelevant code that the standard verification suite cannot catch as misdirected (the code is syntactically correct but exercises no deployed path).
- Bugfixes to defensive v2 code paths (correctness of detection, template rendering edge cases on hypothetical v2 hosts) are permitted — defense-in-depth integrity matters.
- Autonomous issue-generating and issue-implementing agents must verify the host population is non-empty before building features keyed on cgroup version. The verification: read `docs/install.md` for the GRUB pin status; do not proceed if the targeted population is the empty set.

## Consequences

- **Positive**: doctrine gap closed; future autonomous agents (issue generation + implementation) have an explicit architectural pin to consult before fabricating v2-host scenarios. Implementation agents have a clear rule: do not add features to v2 paths.
- **Positive**: defense-in-depth v2 detection retained — if the operator removes the GRUB pin in the future, the software is ready.
- **Negative**: dual-path code stays in the codebase indefinitely as dead-weight (zero traffic) until the GRUB pin is ever reconsidered. This is intentional — the deletion cost of the v2 paths is high (touches many files) and the optionality of v2 has non-zero value.
- **Follow-up**: AGENTS.md INVIOLABLE list carries a one-line pointer at this ADR.
- **Follow-up**: ADR-0003 Status updated to "Superseded by 0019 (in part)" and its "2026-05-29 Update" inline amendment (added in PMSS commit `080f16d0`) is reverted in favor of the cross-reference to this ADR.

## References

- ADR-0003 (Cgroup v2 adoption with dual-path detection and policy floors/caps) — superseded in part by this ADR for the production-deployment framing.
- `docs/install.md` — install bootstrap including the GRUB pin.
- PMSS commit `bc0e92e0` "fix: cgroup — apply bonus IO weights on v2 (Refs #597)" — the implementation that the doctrine gap allowed. Reverted by PMSS `4a48a86a`.
- PMSS issue #597 — closed with explanation; premise fabricated by autonomous issue generation.
