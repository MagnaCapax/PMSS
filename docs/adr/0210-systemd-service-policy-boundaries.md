# ADR 0210: Systemd service policy boundaries

Date: 2026-10-29
Category: architecture

## Status
Accepted

## Context

The systemd service helper combines cron containment, managed restart drop-ins,
disabled-service policy, boot activation, weekly TRIM and resolver recovery.
Updater, cron and setup utilities already depend on its include and public names.
Separating responsibilities must preserve those contracts and all safety checks.

## Options Considered

- Keep the mixed module: preserves callers but couples unrelated policies.
- Introduce a general service-policy interpreter: adds a configuration schema and
  obscures the deliberately different recovery and skip rules.
- Keep the include and move cohesive policies into definition-only modules.

## Decision

Keep cron containment and the shared managed restart writer in `systemd.php`.
Move disabled-service traversal, boot activation and bounded spool cleanup to
`systemd/guard.php`, TRIM policy to `systemd/timer.php`, and Unbound diagnosis and
purging to `systemd/resolver.php`. Load them through the original include; retain
every public helper and its signature. No module executes maintenance on load.

Remove four internal representations or separate paths: the mutable optional
action map, the derived spool membership map, resolver substring offsets,
and separate timer skip logging/return blocks. The module split introduces no
runtime policy schema. Retain cron's existing CPU-count clamp and quota formula.

## Consequences

- Consumers keep their include paths, function names, diagnostics and ordering.
- Stops, disables and masks still use the shared checked, fail-soft action helper.
  Package purge precedes autoremove and the three allowlisted spool cleanups.
- Cron PAM containment, metadata-preserving publication, drop-in path checks,
  permissions and service/account boundaries retain their existing implementation.
- Timer states retain exact matching, including enabling unrecognized states.
  Unbound keeps raw property keys, first-`=` splitting, last-value precedence,
  value trimming and its existing integer casts; it does not adopt the stricter
  shared systemd property parser during this refactor.
- Three additional source files must accompany the existing include when deployed.
  Normal updates stage the complete scripts tree before phase 2.
- The new characterization matrix and existing service tests run on the testing
  machine. Devbox verification is PHP syntax checking of changed files only.

## References

- ADR 0023: Cron service TasksMax guard
- ADR 0074: Weekly TRIM via the distro timer
- ADR 0093: ProFTPD recovery through a systemd restart policy
- `scripts/lib/tests/development/systemdServiceBoundariesTest.php`
