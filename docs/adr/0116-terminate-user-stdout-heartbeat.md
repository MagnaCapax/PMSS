# ADR 0116: Keep termination stdout alive during silent steps

Date: 2026-10-18
Category: architecture
Status: Accepted

## Context

The remote termination caller stops reading after 15 seconds without stdout. Home checks and synchronous purge may be silent for longer, while the caller uses the last non-empty line as the completion result (ADR 0115).

## Options Considered

- Print progress from each step. This changes step output and still cannot cover a blocked PHP call.
- Use PHP timers or signal handlers. They do not reliably run while PHP is blocked inside a C call.
- Run a small child process sharing stdout, and emit only empty lines while the parent lives.

## Decision

Start a shell heartbeat after the termination script loads its helpers when stdout is not a TTY. The child writes an empty line every five seconds and checks the parent's PID each interval. Stop and reap it at shutdown, and stop it explicitly before printing the completion line. The same behavior applies to dry runs; interactive prompts and output remain unchanged.

## Consequences

- Remote readers receive bytes through long silent steps and can continue to read the existing final result.
- Empty lines are added to non-interactive stdout; callers that select the last non-empty line retain their contract.
- The child exits within one interval after the parent dies and adds no runtime dependency beyond `/bin/sh` and `sleep`.
