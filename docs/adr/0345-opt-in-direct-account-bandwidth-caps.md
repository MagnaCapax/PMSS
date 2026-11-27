# ADR 0345: Opt-in direct account bandwidth caps on cgroup v1

Date: 2026-11-27
Category: architecture
Status: Proposed

## Context

Account IOReadBW/IOWriteBW values reach systemd properties but the existing
direct v1 applier writes only IOPS. Enforcing previously inert caps on update
could throttle accounts unexpectedly (issue #1150).

## Options Considered

- Enable all configured bandwidth limits immediately: unexpected fleet impact.
- Add a separate bandwidth cron: duplicates account scans and runtime guards.
- Extend the existing pass behind a shipped policy switch: selected.

## Decision

Add `cgroup.bandwidthEnforce=false` to the repository policy (ADR 0233).
Boolean true enables only explicit account bandwidth specs, using decimal
K/M/G/T units consistent with systemd. Reuse device resolution, positive-UID
slice paths, change detection and private audit. Preserve the IOPS allowlist;
bandwidth gets a separate writer restricted to the two bps files. Reject zero,
overflow, unsafe targets, symlinks, read failures and incomplete writes.
Empty/invalid specs and disabling enforcement never clear existing caps.
No new cron, host-local override, automatic default expansion or v2 writer.

## Consequences

Updates preserve current throughput until the repository switch is enabled.
Dry runs preview bytes/sec; census stays read-only. Missing slices retry next
cycle. Unknown devices count as errors without falling back to `/home`.
Rollback disables enforcement and explicitly clears the affected device caps.
Actual throughput and placement need testing-host verification (ADR 0176).
Hermetic parser, writer and cron tests run on the testing machine/CI only.

## Security Review

The cgroup-v1 `release_agent` privilege-escalation history (CVE-2022-0492) makes
target confinement essential. The new writer cannot address that file, root
slices or IOPS files. Incorrect rates can affect account throughput; overflow
and invalid-target tests cover that boundary. Existing account discovery, root
gate and private audit own identity and permissions. No credentials, secrets,
authentication, listening ports or public endpoints are added or changed.

## References

- [Kernel blkio controller](https://www.kernel.org/doc/html/latest/admin-guide/cgroup-v1/blkio-controller.html)
- [systemd resource control](https://www.freedesktop.org/software/systemd/man/latest/systemd.resource-control.html)
- [Debian CVE-2022-0492](https://security-tracker.debian.org/tracker/CVE-2022-0492)
