# ADR 0168: Storage notice observation and presentation boundaries

Date: 2026-10-24
Category: architecture

## Status
Accepted

## Context
The customer storage helper mixed mount/RAID observations, PSI and public
ioping reads, three HTML renderers, and notice selection. Its RAID activity
text parser and notice renderer duplicated operator-side implementations.
The development suite normally loaded the operator copy first, hiding which
implementation standalone customer PHP actually used.

## Options Considered
- Keep separate renderers: changes and escaping rules can diverge.
- Load operator libraries from customer PHP: violates ADR 0016.
- Bundle the pure shared presentation in the customer tree, with separate
  observation and pressure modules behind the existing facade.

## Decision
Keep `storageHealthNotice.php` as the stable customer entrypoint and RAID-first
notice selector. Extract customer RAID observations, pressure evaluation, and
pressure HTML into focused sibling modules. Both contexts use the single
`storageHealthRaidDisplay.php` activity parser and renderer.

The operator loads the fixed root-owned skeleton path, following the existing
`mediaStackRecoveryCommand.php` bridge. It refuses a missing/symlinked helper
or, when running as root, a helper or containing directory with foreign
ownership or group/world write permission. It never loads an account's copy.
Customer includes stay within the customer tree. User maintenance refreshes
all dependencies before the facade; the facade checks complete delivery before
loading any notice API, following `statsHelpers.php`.

## Consequences
Two duplicated implementations disappear without new policy options. Snapshot
tests lock complete RAID notice bytes and activity fields for standalone operator,
standalone customer, and combined loading. Existing tests retain the pressure
thresholds, freshness checks, RAID precedence, and operator JSONL contracts.
Mount/RAID observations retain each context's existing readers and validation.
The operator now depends on the shipped skeleton presentation helper as well
as its own libraries; a missing or unsafe dependency fails visibly.

## Security Review
PHP includes evaluate code in the caller's context. A replaced helper could
therefore execute as root; the fixed skeleton path and existing ownership/mode
policy bound that exposure. The shared file uses only pure parsing and escaped
HTML, with no includes, filesystem access, commands, credentials, or listeners.
Primary/service credential separation and secret-log/file permissions are
unaffected. Review of PHP's CVE history included CGI argument injection
(CVE-2024-4577 and CVE-2024-8926); this change adds no CGI arguments or request
dispatch. Negative snapshots include HTML metacharacters and invalid UTF-8.

## References
- [Customer PHP separation](0016-customer-php-tree-separation-from-operator-scripts.md)
- [Standing storage status](0073-customer-readable-public-projection-dir-and-standing-storage-status.md)
- [PHP include semantics](https://www.php.net/manual/en/function.include.php)
- [PHP security change history](https://www.php.net/ChangeLog-8.php)
