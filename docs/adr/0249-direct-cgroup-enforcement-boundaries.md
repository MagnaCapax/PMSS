# ADR 0249: Direct cgroup-v1 enforcement boundaries

Date: 2026-11-09
Category: architecture

## Status

Accepted

## Context

`scripts/lib/cgroup/directApply.php` combined target/specification policy,
integer-file writes, slice permissions, account discovery, auditing and two
independent throttle lifecycles. Setting and clearing duplicated validation,
state acquisition, dry-run handling and write-result reporting, but differed
in convergence rules and the timing of writability checks.

## Options Considered

- Retain two controllers: every lifecycle change needs two reviews.
- Unify all controls under a generic writer: obscures the different integer,
  per-device and permission contracts.
- Separate pure policy and guarded filesystem work; share only the throttle
  lifecycle behind the existing public helpers: selected.

## Decision

Keep the original include and public APIs. `directPolicy.php` owns slice paths,
target allowlists, device-counter interpretation and IOPS specification parsing.
`directFiles.php` owns checked integer writes and the unchanged ADR 0232 privacy
pass. Account iteration, runtime guards, audit/cycle reporting and throttle
orchestration remain in `directApply.php`.

Both throttle APIs call one controller. A null internal value requests clearing;
the public set API still rejects zero and negative values. Setting still checks
writability before reading and matches any exact device line. Clearing still
uses the first parsed device record, regards zero as present, and checks
writability only after convergence and dry-run decisions. Missing-file results,
captured trimmed state, result key order and legacy non-false write acceptance
remain unchanged. UID-resolution failures share error accounting while retaining
their distinct warning text. Planned-user iteration destructures its existing
tuple directly.

## Consequences

- Two throttle lifecycles become one; the match flag, user-tuple alias and
  duplicated UID failure path disappear. There is no alternate implementation.
- New modules define helpers only. Existing cron ordering, root gates, positive
  UID and target validation, audit permissions and slice modes are preserved.
- `CgroupDirectThrottleCharacterizationTest` locks return payloads, I/O ordering,
  malformed and duplicate records, dry runs, write failures, throwable propagation
  and UID warning/error accounting with memory-only boundaries. Existing source
  contracts follow the moved functions. Tests execute on the testing VM/CI;
  this dev box runs changed-file PHP syntax checks only.
- The original facade remains required by consumers; no deployment or CLI
  migration is needed. Rollback restores the original facade in one change.

## Security Review

The cgroup-v1 attack surface includes privileged kernel control-file writes:
CVE-2022-0492 concerned missing capability checks on `release_agent`.
The retained allowlist restricts throttles to positive-UID account slices and
the two IOPS files; it cannot address `release_agent`. Integer writers retain
their file-type, symlink, writable-target and exact-byte checks. Privacy retains
root-owned directory checks, minimum UID/GID and the same modes. No credentials,
secret storage, network listeners, panel endpoints or additional exposure paths
are introduced. An incorrect target could affect another account's I/O limits,
so target refusals are included in the behavior lock. Audit contents and private
publication remain with the unchanged audit helper.

## References

- [ADR 0232](0232-account-read-limit-status-and-private-slice-directories.md)
- [Kernel blkio controller documentation](https://www.kernel.org/doc/html/latest/admin-guide/cgroup-v1/blkio-controller.html)
- [Debian CVE-2022-0492 record](https://security-tracker.debian.org/tracker/CVE-2022-0492)
