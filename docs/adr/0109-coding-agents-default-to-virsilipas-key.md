# ADR 0109: Coding agents default to the user's Virsilipas key

Date: 2026-10-17
Category: architecture
Status: Accepted

## Context

The operator requested on 2026-10-17 that "the codex etc. on every user
account should default to using virsilipas ... wiki page shows how to change
it". ADR 0090 established the account-owned `~/.virsilipasKey` file. The
system Codex installer previously left an existing `/usr/local/bin/codex`
untouched, so a new default would not reach existing nodes. The pinned
`rust-v0.93.0` binary has been checked live with Virsilipas's chat API; newer
pins can reject that API form.

## Options considered

- Rewrite each user's Codex configuration or login: changes account-owned
  choices and makes rollback difficult.
- Teach the binary installer to generate per-user files: couples provisioning
  to account state and misses later key changes.
- Place a thin system launcher ahead of the pinned binary: reads current
  account state for each invocation and leaves home files untouched. Chosen.

## Decision

The installer keeps a checksum-verified, versioned binary under
`/opt/pmss/ai-tools/codex/`. It installs a self-contained PHP launcher under
`/opt/pmss/ai-tools/` and a shell stub at `/usr/local/bin/codex`. The source
launcher lives in `scripts/lib/update/apps/templates/`; only the installed
copy runs as a customer, and it never loads `/scripts`
(ADR 0016). The installer writes the binary first, the launcher second, and
atomically replaces the stub last. Content comparison upgrades the old layout
and converges future updates. The old-kernel sandbox fallback remains.

The launcher selects the account-owned key only when it is a regular,
non-symlink file owned by the invoking UID, with a nonempty control-free key
and a restricted HTTPS endpoint. Root, explicit opt-out, an existing login,
an existing environment credential or base URL, and explicit top-level or
command-line model choices all take precedence. It passes the key only through
the child environment and emits a short notice only on an interactive stderr.
It never writes to an account home. The decision is pure and separately tested
from collection and execution.

The installer has an explicit list of pins verified to accept
`wire_api="chat"`, and it refuses to install a pinned tag that is not on
that list. A tag joins the list only after a live Virsilipas check. The list
contains `rust-v0.93.0`.

## Security and consequences

The key remains an account-owned service credential, separate from the
account's primary password. A leaked key can spend that account's credits;
the launcher does not expose it in arguments, logs, or the notice. The
launcher adds no listener, port, web endpoint, or broader file permissions.
Its binary and launcher are root-owned, with 0755 and 0644 modes respectively;
the stub is root-owned 0755. On an unexpected read or parse failure, it runs
the pinned binary without injected settings. Account settings remain intact.
The per-user OpenClaw installer follows the same default on a fresh install:
it uses a runtime environment reference to the account's `~/.virsilipasKey`
and preserves an existing OpenClaw provider choice.
The system Claude Code follows the same account-key default, preserving personal choices.

The published CLI advisory GHSA-w5fx-fh39-j5rw describes a sandbox path
bypass in releases through 0.38.0; the current pin is
later than its patched release. The launcher does not change sandbox policy
or command execution privileges. A compromised account still has that
account's shell and can read its own key, but cannot change root-owned
artifacts or another account's key.

Refs #1081; ADR 0016; ADR 0031; ADR 0090.
