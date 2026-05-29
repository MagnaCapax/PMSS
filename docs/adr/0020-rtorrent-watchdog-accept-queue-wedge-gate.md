# ADR 0020: rTorrent watchdog accept-queue wedge gate

Date: 2026-05-30
Category: architecture

## Status
Accepted

## Context
`checkRtorrent.php` probes each user's rTorrent SCGI socket and restarts missing
instances. PMSS issue #431 added a deliberate liveness gate: if SCGI is
unresponsive but an rTorrent process still exists, the watchdog extends grace
instead of restarting. That protects transiently busy rTorrent processes during
resource pressure.

The same gate leaves a deadlocked-but-alive rTorrent wedged forever when the
SCGI listen socket stops accepting connections. In the observed failure class,
`ss -xln` shows the Unix socket `Recv-Q` at the listen backlog while the
process remains present. A human SIGKILL recovers the instance through the
existing executor restart path.

Restarting every alive-but-unresponsive rTorrent would recreate the false
restart risk #431 fixed. Restarting while the process is in uninterruptible I/O
sleep is also unsafe because the process may not die promptly and a fresh start
can trigger more disk work on an already saturated host.

## Options Considered
- Option A - Preserve the #431 behavior exactly. This keeps transient safety but
  leaves confirmed accept-queue wedges requiring manual recovery.
- Option B - Restart every alive process after SCGI grace expires. This recovers
  wedges but weakens the #431 liveness guard and risks restart loops during host
  I/O stalls.
- Option C - Restart only after a high-confidence discriminator: SCGI grace has
  expired, the process is alive and not in `D` state, and the SCGI accept queue
  is saturated for consecutive watchdog runs.

## Decision
Choose Option C.

The watchdog keeps the existing liveness gate as the default. It reads
`ps -o pid=,stat=,wchan=` for the candidate rTorrent PID and refuses the wedge
restart path when process state is unavailable or `STAT` contains `D`. It reads
`ss -xln` and treats only a matching Unix socket LISTEN row with numeric
`Recv-Q` and `Send-Q` as valid input. Restart occurs only after the queue has
reached the listen backlog for consecutive checks.

This extends ADR-0004's shell guardrails by keeping probes single-purpose and
ADR-0005's trust boundary policy by validating internal command output before
acting on it.

## Consequences
- Positive: confirmed SCGI accept-queue wedges can self-recover through the
  existing SIGTERM/SIGKILL rTorrent restart path.
- Positive: transient alive-but-unresponsive cases still extend grace rather
  than restarting immediately.
- Positive: D-state processes are excluded from this recovery path, avoiding an
  I/O-storm restart loop.
- Negative: a wedged process without a saturated accept queue still requires
  another discriminator or manual recovery.
- Negative: hosts without usable `ss` or `ps` output keep the conservative
  no-restart behavior for alive processes.

## References
- PMSS issue #604
- PMSS issue #431
- ADR-0004: Shell Command Guardrails for Destructive Operations
- ADR-0005: Trust Boundaries for Internal Tools and Script Output
