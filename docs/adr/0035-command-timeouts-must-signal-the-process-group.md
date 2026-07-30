# ADR 0035: Command timeouts must signal the process group, not only the direct child

Date: 2026-07-30
Category: security

## Status
Accepted

## Context

ADR 0034 removed the Servarr version probe that produced the 2026-07-03 root compromise, and
recorded this as its explicit follow-up: the timeout layer itself abandons children.

`pmssCommandTimeoutTerminate()` escalated with `proc_terminate($process, 15)` then
`proc_terminate($process, 9)`. `proc_terminate()` signals only the DIRECT child. A grandchild
that daemonizes survives, reparents to PID 1, and keeps running with the caller's privilege —
root, under `update.php`. `pmssCommandBashInvocation()` returned a bare
`/bin/bash -lc <cmd>`, so the child shared the caller's process group and nothing in the capture
path ever signalled that group.

This is the CLASS, not one module's bug. `arr.php` was the instance that got exploited; the
same abandonment applies to every timeout-wrapped command in the tree, including the six
remaining `pmssAppVersionProbeMatch()` version probes (ttyd, syncthing, filebot, deluge, node,
rclone) and `rtorrent -h`, all of which execute an installed third-party binary as root.

Reproduced before fixing, through PMSS's own `pmssCommandCapture()` with a 2s deadline: a
backgrounded grandchild survived the timeout (rc=124, grandchild still alive). Two candidate
remedies were tested and rejected on evidence:

- **`setsid --wait`** (attempted and reverted upstream, commits 2f3c33ba → 996d7ab6). Bare
  `setsid` discards the child's exit status (`setsid sh -c 'exit 42'` → rc 0 vs `--wait` → 42).
  With `--wait`, `setsid` itself keeps the CALLER's process group while the group leader is one
  level deeper, so the killer's direct child is further from the target, not closer.
- **`timeout --foreground`**. `--foreground` suppresses the `setpgid()` and the group signal —
  it disables precisely the behaviour needed. Measured: grandchild SURVIVED with `--foreground`,
  killed without it.

A third detail decided the shape. `proc_open()` runs a string command through `/bin/sh -c`, so
the direct child is that shell, still inside the caller's process group. Only with an `exec `
prefix does the wrapper become the direct child and its own group leader (verified
`pgrp == pid`).

## Options Considered

- **A — Coreutils `timeout` owns the deadline.** Simple, but `timeout` reports 124/137, which
  are indistinguishable from a command legitimately returning 124 or being OOM-killed (137).
  `timed_out` would be lost, and with it the `[TIMEOUT]` operator line and the timeout-fire
  JSONL. A failsafe that reduces observability is what turned a visible hang into a silent root
  daemon in the first place; repeating that trade is the original mistake.
- **B — PHP keeps the deadline; `posix_kill(-$pid, …)` signals the group.** Correct only if the
  child leads its own group. Nothing in the tree made it do so, and an ungated negative pid
  would signal `update.php`'s own group.
- **C — B, with `exec timeout` creating the group and a leadership gate on the signal.** Chosen.

## Decision

**C.**

1. `pmssCommandProcessGroupWrap()` prefixes piped invocations with
   `exec /usr/bin/timeout --kill-after=<killAfter>s <deadline + grace>s`. `exec` makes `timeout`
   the direct child; `timeout` calls `setpgid()` on itself, so the command runs in its own
   process group. `--foreground` must never be added.

2. PHP remains the timeout DECIDER. The coreutils deadline is deliberately LATER
   (`PMSS_COMMAND_TIMEOUT_BACKSTOP_GRACE_SECONDS`) so the PHP watchdog always wins the race and
   `timed_out`, the `[TIMEOUT]` line and the timeout-fire JSONL keep firing unchanged. Coreutils
   only reaps the group when the PHP parent dies before its own deadline. Observability is
   preserved by construction, not by luck.

3. `pmssCommandTimeoutTerminate()` signals the GROUP at both the SIGTERM and the SIGKILL step,
   gated on `posix_getpgid($pid) === $pid`. Proving the child leads the group makes
   "update.php signals its own group" unreachable rather than unlikely; when the gate fails the
   code degrades to today's `proc_terminate()` behaviour. The gate rejects plain `bash`,
   `setsid --wait`, and a non-`exec` `timeout` — all measured.

   The group escalation is load-bearing, not belt-and-braces. With a coreutils `--kill-after`
   longer than the PHP wait, a SIGTERM-ignoring grandchild SURVIVED under `proc_terminate` alone
   and was killed by the group signal. Relying on coreutils' internal escalation timing would
   make correctness depend on an arithmetic relation between two constants.

4. Two behaviours are preserved explicitly, not incidentally:
   - `timeoutSec <= 0` adds no wrapper. `userTransfer.php` sets `PMSS_COMMAND_TIMEOUT=0` because
     a multi-day customer migration must never be bounded. Unbounded stays unbounded.
   - A missing `timeout` binary adds no wrapper. Otherwise every PMSS command would fail as
     `exec: not found` fleet-wide.

5. The inherited-TTY capture path is deliberately NOT wrapped. A separate process group is not
   the terminal's foreground group, so a child reading the TTY would stop on SIGTTIN. That path
   only engages for an operator-supervised run, where an orphan is visible; the piped path is
   the unattended one where the incident happened.

6. The apt/dpkg timeout carve-out is deleted. `pmssCommandTimeoutSeconds()` raised apt commands
   to `PMSS_COMMAND_TIMEOUT_APT_DEFAULT`, a constant equal to `PMSS_COMMAND_TIMEOUT_DEFAULT`, so
   it computed `max(1200, 1200)` and only acted as a floor under an env override that LOWERED
   the deadline. No in-tree caller lowers it for an apt command. One deadline now covers every
   command class; a per-class carve-out is the shape the incident's "fix" already took once.

**Relation to ADR 0034.** ADR 0034's generalised rule permits a probe of a potentially
daemonizing program only if "it must run in its own process group and be terminated with
`kill -- -PGID` on timeout". That escape clause was unsatisfiable when written; this ADR makes
it true. The remaining `pmssAppVersionProbeMatch()` probes are therefore bounded rather than
abandoned. They are still executions of a third-party binary as root and still the weaker
option — metadata detection remains preferred for any NEW installer — but converting them was
deliberately not bundled here: `rtorrent` is compiled from source, so a metadata marker absent
on existing hosts would trigger a from-source recompile across the fleet on the first update.

## Consequences

- Positive: every timeout-wrapped command in the tree now bounds the CHILD, not only the
  parent's wait. Worst case for a daemonizing grandchild goes from unbounded to the deadline
  plus the kill-after window.
- Positive: the fix is in the shared command layer, so it covers the version probes,
  `userTransfer.php`, `runtime.php` and `update/users/permissions.php` without touching them.
- Positive: exit-code fidelity, stdout/stderr capture, bounded output buffering, the structured
  result shape and the timeout-fire log are unchanged. Verified for rc 0/42/127.
- Negative, accepted and NOT overclaimed: a group signal cannot reach a grandchild that has
  deliberately left the group by calling `setsid` itself. Measured: that variant still survives.
  No group-kill mechanism can close it — the only complete defence is not executing the binary,
  which is ADR 0034's primary decision. The variant that produced the 2026-07-03 compromise
  orphaned by parent death, not by `setsid`, and IS covered.
- Negative: commands now run one process deeper (`timeout` → `bash` → command) on the piped
  path.
- Applied to a second surface under the same rule: `systemStatus.php`'s probe runner executed
  sixteen installed binaries as root (`rtorrent -h`, `openvpn --version`, `flexget --version`,
  `pyload --version`, …) through an unbounded `shell_exec`, outside the app-installer tree the
  timeout audit covered. It now goes through `pmssCommandCapture()`, so those probes are both
  bounded and group-killed. Measured pre-fix: the old closure ran a 20s command to completion.
  The audit's line-scoped rule could never have caught it — the runner factory and the probe
  specs are on different lines — so the enforcement is a behavioural test, not a lint.
- The dev suite did not cover this behaviour: it reported 2577 tests / 0 failures both with and
  without the earlier `setsid` attempt. `commandTimeoutProcessGroupTest.php` reproduces the
  orphan and fails on the pre-fix tree, so the regression is now detectable.

## References
- ADR 0034 — install is not execution; names this change as its follow-up.
- GH #526, #527 — the original hang and the timeout "fix" that bounded the parent only.
- `scripts/lib/runtime/commands.php`, `scripts/lib/runtime.php`
- `scripts/lib/tests/development/commandTimeoutProcessGroupTest.php`
- Commits 2f3c33ba (reverted `setsid --wait` attempt), 996d7ab6 (its revert).
