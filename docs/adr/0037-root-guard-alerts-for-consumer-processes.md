# ADR 0037: Root guard alerts for consumer processes and unknown executables

Date: 2026-07-30
Category: security

## Status

Accepted

## Context

ADR 0034 prevents new root-owned ARR launches, but a process started before that
protection or with an alternate data path can still be present. The existing
two-minute media-stack cron task killed only known ARR paths and did not make a
finding visible to machine monitoring. Unknown root software outside normal
system locations was not reported at all.

The guard must not match command lines: customer processes may use the same
application names, and command-line matching would cross the per-user boundary.
The existing process-group leadership gate from ADR 0035 remains mandatory.

## Options Considered

- **Known names or command lines.** Rejected because names are spoofable and
  command-line matches can kill customer processes.
- **Kill every root process outside a fixed path list.** Rejected because an
  unknown root process has an unbounded false-positive surface.
- **Executable-path and real-uid selection with alert-only unknowns.** Chosen:
  known consumer paths are killed, while unknown executables outside standard
  system paths are reported for operator investigation.

## Decision

Keep the existing module and two-minute cron call site. A single named catalog
contains the known consumer application paths. `/proc/<pid>/status` real uid 0
and `/proc/<pid>/exe` are the only selection inputs.

Known applications are signalled with SIGKILL, using the process group when the
pid leads that group. Signal failure is an alert finding rather than a warning
that permits a green run. An unknown uid-0 executable outside `/usr/bin`,
`/usr/sbin`, `/bin`, `/sbin`, `/usr/lib`, `/usr/libexec`, `/usr/local`, and
`/opt` is alert-only. Every finding emits the greppable
`###PMSS_ROOT_GUARD_ALERT` marker and makes the cron entrypoint exit non-zero;
clean runs emit no root-guard heartbeat.

## Consequences

- Positive: known consumer root processes are removed, and repeated or failed
  action is visible in the cron log and to exit-status monitoring.
- Positive: unknown non-standard root processes become investigation findings
  without broad destructive killing.
- Negative: operators may need to classify legitimate future system software
  when its executable is outside the standard path set.
- Follow-up: adding a supported consumer application requires one catalog entry
  and corresponding hermetic policy coverage.

## References

- GH #743
- ADR 0025: per-user web hosting is an intentional feature
- ADR 0034: install is not execution
- ADR 0035: command timeouts signal the process group
