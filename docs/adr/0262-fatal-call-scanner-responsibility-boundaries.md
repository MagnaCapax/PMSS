# ADR 0262: Fatal-call scanner responsibility boundaries

Date: 2026-11-10
Category: architecture

## Status
Accepted

## Context
The fatal-call scanner implements ADRs 0016 and 0017 for customer PHP and also
reports undefined operator calls. Its single library mixed CLI diagnostics,
file discovery, symbol indexing, token navigation and optional-function guards.
Both scan scopes maintained their own unresolved-call traversal, and enclosing
brace discovery repeated the backward delimiter matcher.

## Options Considered
- Keep the combined library: retains duplicated traversal and mixed ownership.
- Add independent scanners for each scope: repeats the existing guard policy.
- Retain one scanner and separate its existing responsibilities internally.

## Decision
Keep `customerContextFatalScan.php`, its global functions, constants and scanner
class API. Under `scripts/lib/testing/customerContext/`, `scanner.php` owns scope
selection, definition precedence and diagnostic projection. Private traits in
`files.php`, `tokens.php` and `guards.php` own discovery, token navigation/call
classification and guard recognition. They add no independent scanner objects,
configuration or policy modes.

Customer and operator scopes use one unresolved-call traversal. One backward
delimiter matcher handles both closing-delimiter matching and enclosing-brace
discovery through its initial nesting depth. Calls stream in token order; there
is no intermediate call list. The single-use bin discovery wrapper is removed.
Immediate call exclusions share one token-kind list.

## Consequences
- Four mechanisms disappear: the duplicate scan traversal, duplicate delimiter
  walker, bin forwarding wrapper and intermediate per-file call list.
- Includes define helpers only. Discovery order, duplicate file visits, symbol
  precedence, case handling, result field order and CLI bytes retain their
  contracts. Existing guard heuristics retain their accepted and rejected cases.
- The private traits share the scanner's token helpers; this is an internal
  source split, with no supported trait API or alternate implementation.
- `CustomerContextFatalScanTest.php` locks both result schemas, discovery and
  guard cases plus mixed CLI diagnostics. Execution belongs on the testing VM
  and CI; devbox verification is changed-file PHP syntax and symbol searches.

## References
- [ADR 0016](0016-customer-php-tree-separation-from-operator-scripts.md)
- [ADR 0017](0017-customer-tree-php-code-review-checklist.md)
- `scripts/lib/tests/development/CustomerContextFatalScanTest.php`
