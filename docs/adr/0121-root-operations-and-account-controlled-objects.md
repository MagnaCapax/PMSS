# ADR 0121: Root acts only on root-owned objects; account-owned objects are handled as the account

Date: 2026-10-19
Category: architecture

## Status

Accepted

## Context

PMSS maintains both system-wide installations and per-account state. Ownership
and write access determine which identity can control a filesystem object,
including when a path is outside an account's home directory. A consistent rule
is needed for every root step that encounters such objects.

## Options Considered

- Let root handle small account-file operations after checking path strings.
  Rejected because the account can change the object or its parents later.
- Keep root work on root-owned objects and run account-file work as the account.
  Chosen because it keeps authority aligned with object control.

## Decision

1. Any filesystem object owned or writable by an account (any non-root UID) is
   account-controlled. This includes everything under `/home/<account>` and any
   tree outside `/home` whose files carry an account UID.
2. Root never executes, sources, interprets, loads modules or plugins from, or
   places on `PATH` an account-controlled object.
3. Root never writes, chowns, chmods, renames, copies into, or deletes by path
   inside an account-controlled tree. That work runs as the account, using
   `pmssBuildUserShellCommand()` where appropriate. Alternatively, root may
   move the whole tree into a root-only directory it just created and remove
   it with the descriptor-based GNU `rm -rf --one-file-system`; root must not
   use a path-based PHP walk for this removal.
4. Root never trusts a path or value read from an account-controlled file
   without containment. Use `pmssPathWithinResolvedRoot()` for resolved-root
   path containment where applicable, together with the checks required by
   the operation.
5. Anything root installs from an archive or package is root-owned regardless
   of ownership metadata in the archive. Extract with `--no-same-owner` and
   `--no-same-permissions` under a fixed umask. On update, verify ownership of
   an existing install and refresh it if ownership differs; do not chown the
   existing tree in place.
6. No matter how small the step, the same rule applies to a one-line chown, a
   cache write, a version probe, a config validation, or a convenience `PATH`
   entry.

## Consequences

- Reviewers reject root steps over account-controlled objects.
- Existing violations are corrected as they are found.
- Account-owned work uses the account identity; system-wide installed trees
  retain root ownership on refresh.

## Related ADRs

- ADR 0005: validation at trust boundaries for internal tool output.
- ADR 0041: managed and account-owned web-root state.
- ADR 0046: provisioned in-home file ownership classes.
- ADR 0071: account owner repair and subordinate ID ownership.
- ADR 0092: account data movers and checked filesystem objects.
