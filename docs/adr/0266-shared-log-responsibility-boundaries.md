# ADR 0266: Shared log responsibility boundaries

Date: 2026-11-11
Category: architecture

## Status

Accepted

## Context

`scripts/lib/log.php` combined scalar text conversion, JSON codecs, JSON file
traversal, guarded append operations and the legacy logger. Consumers need the
same historical include and public functions, including isolated fault fixtures
that evaluate library source in a namespace.

## Options Considered

- Retain every responsibility in the legacy entrypoint.
- Introduce a replacement logger API and migrate callers.
- Retain the entrypoint and separate its existing responsibilities internally.

## Decision

Keep `log.php` as the dependency bootstrap and `logmsg()` compatibility entrypoint.
Load four focused modules: `log/text.php` for field normalization and timestamps,
`log/json.php` for codecs and payload emission, `log/jsonFiles.php` for file and
JSON Lines traversal, and `log/append.php` for guarded writes and mirroring.
These modules are components of the facade; existing consumers keep using it.
Every existing function has one implementation and retains its signature.

Scalar fields share one string cast after boolean and unsupported-type handling.
Path validation, shared-file validation and reader acquisition each retain their
checks in order while converging their refusal returns. Permission narrowing
uses the inode mode directly instead of caching a second permission value.

Empty JSON file reads retain their pre-decode guard: skipping decoding preserves
the caller's `json_last_error()` state as well as returning null.

## Consequences

- No caller migration, new logging mode, format, environment key or dependency.
- Three duplicate refusal paths and one duplicate conversion branch disappear;
  the cached permission projection also disappears. Runtime physical source
  decreases from 275 to 271 lines across the facade and four modules.
- `LogBoundariesCharacterizationTest` locks scalar representations, JSON bytes,
  UTF-8/error policy, JSON file acceptance, record order and handler exceptions.
  Existing acquisition, umask and short-write fault fixtures evaluate the moved
  modules in their original injection namespaces. Source-contract checks follow
  the functions to their new files without changing their assertions.
- File guards, permissions, complete-write checks, stream cleanup, EOF detection,
  fallback selection and console deduplication retain their existing behavior.
  No account/service credentials, secret logging or exposure paths change.
- Devbox verification is changed-file PHP syntax and symbol/reference review.
  Runtime tests and any operational dry run remain on the testing machine/CI.

## References

- [Logging contracts](../contracts.md#logging--json-events)
- [Shared file writer policy](0142-shared-user-file-writer-acts-on-the-opened-inode.md)
- [Runtime filesystem boundary](0167-runtime-filesystem-acquisition-boundary.md)
