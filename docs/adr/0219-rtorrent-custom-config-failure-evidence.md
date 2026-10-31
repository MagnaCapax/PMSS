# ADR 0219: Evidence before counting rTorrent custom-config failures

Date: 2026-10-31
Category: domain

## Status
Accepted

## Context
`startRtorrent` counts failed starts before quarantining `.rtorrent.rc.custom`.
A screen socket-directory error can prevent rTorrent from starting at all,
yet repeated retries previously quarantined the unchanged custom rc file.

## Options Considered
- Count all failed starts: environmental failures can quarantine healthy files.
- Match only known screen errors: other prelaunch failures remain misclassified.
- Check launch results and require an observed rTorrent process before counting.

## Decision
Use both the bounded launch command's result and the existing PID probes.
Failed or timed-out commands, screen ownership/directory diagnostics on either
output channel, and failed legacy session cleanup stop before config accounting.
Log the command error as the cause. If no rTorrent PID appears during startup,
log that missing evidence and leave the custom config and counter untouched.

An observed process that fails the existing survival check still advances the
same counter and quarantines at four failures. A healthy launch still clears
the counter. Keep all username, path, ownership, symlink and marker-write guards,
startup deadlines, launch commands and watchdog timeout backoff intact.

## Consequences
- Repeated prelaunch failures preserve the config and existing failure count.
- Observation does not prove a config defect. Very fast crashes between PID
  probes cannot be counted, including config errors that exit before observation.
- No host repair or migration is performed. Screen-directory repair remains
  separate; the launcher reports its failure instead of moving customer config.
- Hermetic entry-point fixtures cover both launch modes, output channels,
  retries, absent PIDs, observed crashes, stable starts and cleanup failure.
  Local verification is changed-file PHP syntax only; the lane and CI run tests
  on a testing machine. No live service or destructive dry run is performed.

## References
- Refs #1130; related #308
- [Process facade](0077-rtorrent-process-facade-and-snapshot-flow.md)
- [Script contracts](../contracts.md#utilities-script-contracts)
