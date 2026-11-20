# ADR 0314: The lighttpd watchdog restarts a php-cgi pool left on a replaced PHP binary

Date: 2026-11-20
Category: architecture

## Status
Accepted

## Context

A PHP package upgrade replaces `/usr/bin/php-cgi8.x` on disk. A running per-account
php-cgi pool keeps executing the old, deleted binary until its lighttpd restarts, and
pool children fork from that parent, so recycling a child does not load the new one.

The lighttpd watchdog restarts a pool only for socket failure, a dead process, persistent
upstream 502s, or a config newer than the process. A binary swap is none of these. An
account is restarted after an upgrade only when the same update also rewrites its config.
An account whose config is unchanged keeps the old binary. One such pool was observed
after an update that upgraded PHP, while the other accounts on that host were restarted.

The same gap existed for rootless Docker. ADR 0286 records why the updater alone is not
enough: upgrades outside the update flow, and drift left by an earlier run, are missed.

## Options Considered

- Record PHP package versions in the update package phase and restart every account
  afterwards, as the Docker flag does. Rejected as the only fix: it misses upgrades made
  outside the update flow and drift that already exists.
- Restart every account's web stack at the end of each update. Rejected: it interrupts
  every account on every update, also when PHP did not change.
- Detect the drift in the existing lighttpd watchdog and restart only the affected
  pool. Chosen, following ADR 0286.

## Decision

`checkLighttpdInstances.php` adds one restart reason: the account's php-cgi pool runs a
binary that was replaced on disk. `pmssLighttpdWatchdogPhpCgiBinaryReplaced()` checks the
account's exact-name php-cgi PIDs with `pmssProcessExeDeleted()`. A PID counts only when
`/proc/<pid>/exe` names a path under `/usr/bin/php-cgi` followed by ` (deleted)`, the
process shares the host mount namespace, and `/proc/<pid>` is owned by the account, read
before and after the links. A process inside an account container, a binary outside
`/usr/bin`, another owner or a reused PID therefore never requests a restart. Anything
unreadable means no restart.

The restart uses the existing path (space check, TERM then KILL, start, listener
verification). It is bounded to one restart per account per installed binary: the
binary's mtime is recorded in the runtime directory beside the socket-failure markers
before the restart, and a marker that cannot be written refuses the restart. A restart
that does not heal is left to the existing socket and liveness checks, so the new reason
cannot loop. The check is skipped when the config-newer reason already restarts the pool.

## Consequences

- After a PHP upgrade by any route, every account pool picks up the new binary within
  one watchdog pass. The update's own post-check runs the same watchdog.
- Each affected account sees one short web restart per PHP upgrade, the same as a
  config-rewrite restart.
- `pmssProcessExeDeleted()` is a general helper. The two older deleted-executable checks
  (rTorrent identity, the SSH compatibility service) can move to it later; they are not
  changed here.
