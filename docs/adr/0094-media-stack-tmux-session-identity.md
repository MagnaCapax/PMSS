# ADR 0094: Verify media-stack tmux session identity

Date: 2026-10-14
Category: security

## Status

Accepted

## Context

The installer, customer Apps panel, and root watchdog treated a bare tmux
session name as proof that it ran the corresponding media app. An account owner
could use the same name for an unrelated shell; the panel would report that
shell as the app and Stop would kill it.

## Options Considered

- Rename live sessions into a PMSS namespace: would need a migration and could
  interrupt existing account workflows during an update.
- Verify the session's launch command: preserves existing names and tests the
  fact needed before reporting or killing a session. Selected.

## Decision

Only a single-pane tmux session whose pane launch command contains the expected
installer app directory is treated as a media app. Apply this check to the
installer's control and cleanup paths, the customer's live status, and the root
watchdog. A same-named session that fails the check is left untouched. The
installer reports a collision instead of launching another session with that
name. No live session is renamed.

## Consequences

- Existing installer and alias sessions retain their names and remain usable.
- A customer who replaced an installer session's pane or added another pane must
  manage that session manually; the panel cannot safely claim it.
- Session launch strings are an ownership hint within one account, not a
  cross-account security boundary. Account owners can still manage their own
  sessions directly with tmux.

## References

- PMSS issue #1051
- ADR 0088: customer Apps controls
