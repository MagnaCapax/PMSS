# ADR 0004: Shell Command Guardrails for Destructive Operations

Date: 2025-12-10  
Category: architecture

## Status
Accepted

## Context

Several production incidents and near-misses revealed that our shell usage patterns
around destructive operations (e.g., `rm`, `quota` updates, per-user cleanup) were
too trusting of both input and environment:

- Historical scripts assumed that only "good" usernames and paths were ever
  passed to them. This assumption was falsified when `/scripts/lib/users.php`
  and related files disappeared mid-update, causing `/scripts/listUsers.php`
  to emit PHP fatal errors instead of a clean username list. Cron jobs such as
  `updateQuotas.php` treated those error lines (and the empty string) as
  usernames and constructed shell commands from them.
- Multi-command shell strings like:
  - `rm -rf /home/$user/.quota; quota -u $user -s >> /home/$user/.quota; chmod o+r /home/$user/.quota`
  packed deletion, quota refresh, and permission changes into a single command
  with `;` separators. When `$user` was empty or malformed, this produced
  attempts against `/home/.quota` and other unintended paths.
- Invariant checks (e.g., "is this really /home/<username>?") were missing
  before running destructive commands. The code relied solely on upstream
  assumptions about username validity and tree layout.

The combination of these behaviours created a class of bugs where:

- Broken or partial environments (mid-update, missing libs) caused trusted
  scripts to emit garbage.
- Callers blindly consumed that output and combined it with multi-command
  shell strings, amplifying the impact of otherwise recoverable failures.

We need explicit, code-level guardrails to constrain shell usage for destructive
operations regardless of how well-behaved upstream code is.

## Options Considered

- **Option A – Status quo (no additional guardrails).**
  - Pros: No changes to existing scripts.
  - Cons: Relies on assumptions that have already been proven false; fragile
    under mid-update conditions or partial failures; hard to reason about safety.

- **Option B – Lint-only enforcement of dangerous patterns.**
  - Pros: Sharp-edges lint already catches some risky primitives; can be
    tightened to fail on obviously catastrophic patterns.
  - Cons: Static lint cannot see all runtime combinations; does not prevent
    misuse of otherwise safe commands; does not enforce invariants at runtime.

- **Option C – Runtime guardrails + lint:**
  - Ban multi-command shell strings that mix `;` with untrusted data.
  - Require precondition checks (realpath invariants) before destructive ops
    that touch `/home` or other critical trees.
  - Keep and expand lint as a backstop for patterns that must never appear.

## Decision

We adopt **Option C**.

1. **No multi-command shell strings with `;` and untrusted bits.**

   - Scripts must not build shell strings that chain multiple commands with `;`
     (or `&&`, `||`) when any part of the command line includes data derived
     from usernames, filenames, or other untrusted sources (including output
     from `listUsers.php`).
   - Instead:
     - Execute one command at a time with a clear purpose.
     - Use `escapeshellarg()` or `pmssBuildCommand()` for every user-derived
       argument.
     - Perform file deletion, creation, and permission changes via PHP
       filesystem APIs whenever practical (`unlink()`, `file_put_contents()`,
       `chmod()`, etc.).

2. **Invariant checks before destructive operations.**

   - Before any destructive action that touches `/home/<username>` or similar
     critical paths (delete user directories, rewrite quota files, etc.),
     scripts must verify that the resolved path matches the expected prefix.
   - Example invariant:
     ```php
     $expectedHome = "/home/{$username}";
     $real = realpath($expectedHome);
     if ($real === false || strpos($real, $expectedHome) !== 0) {
         die("Refusing to operate on '{$real}' for user {$username}\n");
     }
     ```
   - If the invariant does not hold, the script must log the issue and abort
     that operation (or skip the user) rather than "trying anyway".
   - Note: this invariant intentionally rejects symlinked homes that resolve
     outside `/home/<username>`; if we ever support such layouts, this rule
     must be revisited or adapted accordingly.

3. **One shell primitive per call.**

   - When a shell is required (e.g., to invoke `quota`, `cp`, or `systemctl`),
     each call should perform one logical action:
     - No `rm ...; quota ...; chmod ...` in a single string.
     - Prefer `exec()` with a single command, capturing `rc` and output.
   - Complex workflows should be composed in PHP, not by chaining shell
     statements together.

4. **Lint enforcement as a backstop.**

   - `scripts/testing/sharp-edges-lint.sh` is extended and wired into CI to:
     - Hard-fail on catastrophic `rm -rf` patterns (e.g., targeting `/`,
       `/home`, `/home/$var`, `$HOME`, `~`) regardless of strictness flags.
   - Additional lint and tests may be added to flag `;`-chained shell strings
     that include `/home` or other critical prefixes when combined with
     untrusted input. The exact regex set may evolve over time as we refine
     coverage.

## Consequences

- **Positive:**
  - Reduces the blast radius of any future `listUsers.php` or filesystem
    issues; even if upstream emits garbage, invariants and single-command
    shells limit the damage.
  - Makes destructive flows more predictable and auditable (each step
    is a separate, logged call).
  - Aligns code with existing safety doctrine and sharp-edges lint.

- **Negative:**
  - Slightly more verbose code: more small PHP blocks and more explicit
    checks instead of compact shell one-liners.
  - Some legacy scripts require careful refactors to split multi-command
    strings without changing behaviour; this is additional engineering work.

- **Follow-ups:**
  - Extend sharp-edges lint to explicitly flag multi-command shell strings
    that include `/home` or `$HOME` alongside untrusted variables.
  - Incrementally refactor remaining scripts that still use `;` in command
    strings with user-derived data.
  - Document these rules in developer onboarding and code review checklists.

## References

- `scripts/cron/updateQuotas.php` refactor to single-command `quota` calls and
  PHP-based file operations.
- `scripts/terminateUser.php` invariant checks and split `userdel`/`groupdel`.
- `scripts/cron/userTrackerCleaner.php` backup refactor and lifecycle logging.
- `scripts/testing/sharp-edges-lint.sh` fatal patterns for `rm -rf`.
