# ADR 0164: Managed file writer module boundaries

Date: 2026-10-23
Category: architecture

## Status

Accepted

## Context

The shared `lighttpd/userFileWrite.php` had grown to 690 lines. Path predicates,
exclusive staging, inode verification, metadata, account execution, append, and
serialized publication shared one source file. Callers already use the same
facade, so those responsibilities can be separated without changing their calls.

## Options Considered

- Keep the monolithic implementation. This leaves unrelated operations coupled
  during review and fault injection.
- Introduce writer objects and migrate callers. Rejected: new interfaces and
  dispatch machinery are unnecessary for the existing procedural contracts.
- Keep the facade and load focused internal modules. Chosen.

## Decision

Keep `userFileWrite.php` as the only supported include entry point. One ordered
module list loads path/directory preparation, verified handles/staging, metadata,
replacement/append, account execution, and serialized-target orchestration.
Modules contain the existing implementations; no alternate writers remain.

Remove the unconsumed ownership-dispatch helper: its sole caller already knows
it is handling a descriptor path outside the account-home branch. Keep its
root-only ownership check at that call site. Inline the single-use failure-label
helper, and report serialized write failures once after either writer backend.
Keep immutable restoration in the existing `finally` and retain partial success.

Fault-injection fixtures load the same module list into their namespace through
the existing test helper. A characterization matrix locks ordered callbacks,
invalid target labels, continuation after failures, empty target lists, binary
serialized bytes, and final modes. Existing staging-swap, short-write, exception,
metadata, and account-boundary tests continue to exercise the facade.

## Security Review

ADRs 0121 and 0142 remain authoritative. The known Deluge WebUI file-read and
limited-write findings show why account configuration must remain isolated from
root authority. This decomposition retains account execution, private root
staging, single-link inode checks, and metadata through opened descriptors.
Exclusive-create modes retain the behavior documented by PHP's `fopen` manual.

No credential values, credential separation, payload logging, file modes,
listeners, or web routes change. Exposure of a service config remains bounded
by that service's existing account identity and permissions. Verification must
retain the existing refusal, short-write, cleanup, and ownership assertions;
tests run on the development sandbox, never the control node.

## Consequences

The facade and all six modules must be delivered together, as with other modular
operator libraries. Internal modules are not independent include entry points.
Existing consumers retain their signatures, return values, and diagnostic text.
Two internal helper concepts and a duplicated failure branch disappear; the
module registry is the only additional dispatch concept. Runtime source shrinks.

## References

- [ADR 0121](0121-root-operations-and-account-controlled-objects.md)
- [ADR 0142](0142-shared-user-file-writer-acts-on-the-opened-inode.md)
- [PHP fopen modes](https://www.php.net/manual/en/function.fopen.php)
- [Deluge WebUI findings](https://securitylab.github.com/advisories/GHSL-2024-188_GHSL-2024-191_Deluge/)
