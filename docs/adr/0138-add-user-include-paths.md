# ADR 0138: Resolve addUser libraries from the script directory

Date: 2026-10-19
Category: architecture
Status: Accepted

## Context

`addUser.php` loaded its libraries from relative strings. PHP searches its
include path, which can include the caller's working directory. Root callers
invoke this command from different directories.

## Options considered

- Require callers to change directory before launch. This would add a caller
  precondition and leave the include lookup dependent on that directory.
- Anchor every include to `__DIR__`. Chosen because it follows the established
  pattern in other scripts and preserves the command interface.
- Change the process-wide include path. This would affect unrelated loads.

## Decision

All `addUser.php` includes use `__DIR__`. The static include checker rejects
bare relative include literals in runtime PHP under `scripts/`; tests are
excluded from this rule.

## Consequences

The command loads the same repository libraries regardless of the caller's
working directory. Its arguments and output remain unchanged.
