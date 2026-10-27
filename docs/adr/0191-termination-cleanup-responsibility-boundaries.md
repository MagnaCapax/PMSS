# ADR 0191: Termination cleanup responsibility boundaries

Date: 2026-10-27
Category: architecture
Status: Accepted

## Context

`user/terminationCleanup.php` combined shell-step planning, direct filesystem
mutation, rTorrent port discovery, marker retirement and completion checks.
File and empty-directory removals repeated their audit lifecycle. Port discovery
maintained separate stored-key and legacy-pattern catalogs and passed an
intermediate port map between discovery helpers.

## Options Considered

- Keep the mixed module: preserves behavior but leaves duplicate cleanup flows.
- Add a general cleanup framework: adds configuration and execution concepts.
- Keep the facade and separate its existing responsibilities, sharing only the
  repeated removal lifecycle and the ordered port catalog.

## Decision

The facade loads `termination/commands.php`, `filesystem.php`, `ports.php` and
`completion.php`. Commands retain their bytes and guards; filesystem cleanup
shares one reject/no-op/scan/dry-run/remove/audit flow. One port discovery
controller reads stored values before filling gaps from legacy configuration.
The socket steps are built directly in their sole initial-removal consumer.

## Consequences

There is one implementation of each cleanup flow. Existing entry points used
by the termination script retain their signatures, results and audit fields.
No authentication, credential, permission or public exposure policy changes.
The synchronous purge, route-before-port ordering and first-failure completion
rules remain governed by ADRs 0062 and 0115.

The behavior lock checks 24 removal decisions and exact audit payloads, plus
port-validation boundaries. Existing tests retain command, purge, completion,
stored-port precedence and legacy fallback coverage; source assertions follow
the modules that now own those operations. Tests run only on testing machines.
