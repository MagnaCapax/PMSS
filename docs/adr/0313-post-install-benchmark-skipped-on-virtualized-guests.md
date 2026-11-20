# ADR 0313: Post-install benchmark is skipped on virtualized guests

Date: 2026-11-20
Category: architecture

## Status
Accepted

## Context

update-step2 runs `storageBenchmark.php --require-idle --devices --label=post-install`
once on tenant-empty hosts (#656, ADR 0273). It writes a 500G fio file and runs
per-device tests.

On a KVM guest that is the wrong measurement and an unsafe load:

- `--require-idle` reads the guest's own counters. A guest sees idle while the host
  array beneath its virtio disk is still in its initial resync, which the guest cannot see.
- `--devices` on a guest measures the virtual layer, not the hardware.
- The write load lands on a host shared with other guests. On 2026-11-20 one such run
  stalled neighbouring guests and cut the host array's recovery from about 100 to about
  26 MB/s.
- The run then failed, the marker stayed unset, and the next update repeated it.

## Options Considered

- Keep the run and bound it (smaller file, no `--devices`) on guests. Rejected: it still
  loads a shared host and the baseline it records says nothing about hardware.
- Make `--require-idle` detect guests itself. Rejected: it would also change the manual
  run, which an operator may want on a guest and which is bounded by the operator.
- Skip the automatic run on guests and keep the manual path. Chosen.

## Decision

`pmssStorageBenchmarkPostInstallRun()` skips the automatic run when the host is a
virtualized guest, logs a `[SKIP]` line that says why and names the manual path, writes
no marker and returns 0. Detection reuses `pmssSysctlIsVm()` (`systemd-detect-virt
--quiet`, with its DMI fallback and `PMSS_SYSCTL_IS_VM` override), the one VM detector
PMSS already has. The check runs after the managed-user and marker skips, so their log
lines and precedence are unchanged. Bare-metal behaviour is unchanged.

## Consequences

- Guests no longer start a 500G fio run from an update, and no longer retry it every
  update.
- Containers also report as virtualized under `systemd-detect-virt` and are skipped too.
- No post-install baseline is recorded on guests. The marker stays unset on purpose; an
  operator who wants a guest figure runs `/scripts/util/storageBenchmark.php` by hand.

## References

- ADR 0273 (post-install deadline and idle evidence), ADR 0189, ADR 0178.
