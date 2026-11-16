# ADR 0286: Rootless Docker watchdog version drift

Date: 2026-11-15
Category: architecture

## Status
Accepted

## Context
An account's rootless daemon can survive a Docker package upgrade while its
on-disk shims change. Process existence alone cannot detect this version split
(#872). Updater package-change recovery already restarts daemons, but upgrades
outside that flow and drift left from an earlier run still need detection.

## Options Considered
- Leave detection to the updater: misses existing drift and external upgrades.
- Restart every mismatched daemon: interrupts running account containers.
- Probe in the existing watchdog and restart only positively observed idle
  daemons: chosen to preserve active workloads without adding a timer.

## Decision
After the existing parked-account and Docker-enabled gates, resolve the account
through the same positive-UID validation as `userDocker.php`. A missing socket
retains the normal start path. Otherwise query the server version through the
explicit account Unix socket, compare it with `/usr/bin/dockerd --version`, and
on mismatch query `docker ps --quiet`. Run probes as the account with shared
five-second process-group deadlines and the existing 15-minute timeout backoff.

Only a successful empty container listing selects `userDocker.php restart`.
Log the validated versions and restart/defer decision. Failed or malformed
version probes and busy or unverified container state retain the start path;
no timeout alone authorizes a restart. The existing checked shutdown supplies
TERM, deadline-based KILL escalation and verification before startup (ADR 0154).

## Consequences
Idle version drift converges through the existing five-minute watchdog. Busy
accounts defer recovery until a later idle pass. Container state is a snapshot;
an account can launch a container between the idle probe and shutdown. Hung
daemons with unknown container state are reported but not forcibly recovered.
Containerd-only drift without an Engine version change is outside this detector.
No credentials, listeners, cron entries or account settings are introduced.

`UserDockerWatchdogDriftTest` supplies hermetic command/account observations;
execution belongs on a testing machine. Rollback removes the probe invocation
and its helper, restoring unconditional watchdog starts.

## References
- Issue #872; related liveness investigation #718.
- ADRs 0027 (manager-independent Docker), 0035 (group deadlines), 0154 (lifecycle).
