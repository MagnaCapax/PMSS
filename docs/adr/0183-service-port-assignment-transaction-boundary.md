# ADR 0183: Service port assignment transaction boundary

Date: 2026-10-26
Category: architecture

## Status
Accepted

## Context
The service-port allocator duplicated preflight, lock acquisition, assignment
rechecks and lock release between the allocation library and the CLI's release
handler. Its legacy and managed reservation readers also duplicated root checks,
glob handling and port-set construction. CLI errors repeated the same status
translation across operations and preflight/recheck failures.

## Options Considered
- Split files without consolidating the repeated protocols. This leaves two
  mutation lifetimes and two reservation traversal implementations to maintain.
- Share the transaction and traversal boundaries, retaining the existing
  operation-specific allocation, deletion and reporting policies.

## Decision
Keep `scripts/lib/portManager.php` as the stable facade. Load CLI presentation
from `portManager/cli.php` and the shared mutation lifetime from
`portManager/assignment.php`. The transaction preflights before taking the lock,
then passes the rechecked context and status to one operation callback under
the lock. A failed recheck is delivered as a null context. `finally` releases
the lock on success, failure and exceptions.

Allocation remains in `selection.php`; release changes only the current locked
assignment through `pmssPortManagerReleaseAssignment()`. CLI release outcomes
are still emitted inside the lock; allocation outcomes are emitted after the
library returns. One status table projects failures to the existing stderr and
audit messages. Read-only view keeps its distinct unaudited malformed-value error.

Both reservation namespaces use one scan/recheck/decode controller in `state.php`.
Their glob depth and port decoding remain separate inputs. A missing legacy
namespace is valid, while missing managed state or an incomplete scan blocks
allocation. Merging sets retains legacy-first insertion order and duplicate
ports retain their original position.

## Consequences
Public helper signatures, CLI bytes and exit codes, service validation, range,
random allocation, preferred-port handling, both bind checks and all existing
assignment-path and writer guards remain unchanged. Release still removes a
safe malformed assignment without parsing it. No state migration or alternate
implementation is needed.

`PortManagerCharacterizationTest` locks command channels, audit contents and
ordering, raced assignment creation/removal, failure precedence, exception
cleanup and reservation-set order. Its command dependencies are in-memory
fixtures; filesystem cases use runner-owned temporary directories. The existing
lock-race fixture loads the moved modules so its injected lock remains effective.

## Validation
Changed PHP files pass syntax lint. The PHP 7.3 compatibility scan, runtime PHP
syntax sweep, Bash checks, docblock lint, doctrine lint and customer-context
fatal scan pass. Whole-tree Bash/PHP sweeps use a tracked-source snapshot with
the new files included: the checkout also contains ignored snapshots whose
older scripts otherwise enter recursive file discovery. No checker was changed.
Existing function names are retained, and the removed interim helper name has
no references. Runtime files decrease from 359 to 349 physical lines; the two
mutation lifetimes and two reservation traversals each become one.

Tests run only on testing machines. The sandbox runner returned exit 69 for both
requested PHP 7.3 and PHP 8 interpreters before executing the suite; the publishing
lane must perform the baseline comparison. Local verification is static only.

## References
- [Architecture overview](../architecture.md)
- [Kernel port reservations](0026-kernel-ephemeral-port-reservation.md)
