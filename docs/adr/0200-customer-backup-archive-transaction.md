# ADR 0200: Customer backup archive transaction

Date: 2026-10-29
Category: architecture

## Status
Accepted

## Context
Scheduled configuration backups and pre-restore snapshots create the same fixed
allowlist tar stream through separate name-selection and publication flows.
The restore tar reader also repeats padded-block consumption for metadata and
skipped file bodies. These copies obscure the few intentional policy differences.
Customer helpers must remain inside the existing guiv delivery set (ADR 0022).

## Options Considered
- Keep independent archive transactions and padded readers.
- Add a new archive module, requiring coordinated external delivery changes.
- Share the transactions within the existing delivered parts, retaining the
  public entry points and explicit scheduled/snapshot policy.

## Decision
Keep archive discovery, command construction, scheduling, restore orchestration,
and result projection at their existing boundaries. The Backup part owns one
name-selection loop and one stream publication transaction for both callers.
The RestoreTar part owns one padded-block read loop, capturing only metadata.
GNU long-name and PAX metadata share their bounded-read dispatch, and member
type classification reuses the existing regular-file/directory predicate.

Preserve scheduled `xb` opens, part-file collision checks, private modes,
nonempty output, final-name checks and final verification. Preserve snapshot
`wb` opens, part-file handling, empty-output acceptance and rename behavior.
These are existing policies, not new switches exposed to the panel or cron.

## Consequences
No parallel archive transaction or padded-reader implementation remains.
The download, scheduled result records, restore error text, operation order,
archive names, 100-name limit and public helper names retain their contracts.
The helper inventory gains shared transaction/block readers; delivery lists do not change.
Characterization covers publication failures and exact side-effect traces,
name collisions, padding, truncation and the metadata size boundary. Tests run
only on testing machines; existing backup/restore integration tests remain.

Stream lifetimes use `finally`: archive output closes after setup/copy exceptions,
and a started pipe is reaped even if closing the output throws. Tar inspection
closes its gzip stream on normal returns, malformed input and read exceptions.
Exception identity, publication order and partial-file handling remain unchanged;
isolated fixtures cover both exceptions and errors without launching tar.

Archives can contain private tracker URLs and separate application credentials;
exposure therefore reaches the affected account's applications. This change
does not generate, store or log primary account credentials, alter credential
separation, file permissions, authentication, routes or listeners. Existing
path/allowlist/link guards stay intact. PHP's documented extraction semantics
and its TAR parser entry-injection advisory make preserving those guards
necessary; this refactor does not replace or relax them.

## References
- [Customer backup contracts](../contracts.md#customer-torrent-state-backup--etcskelwwwscriptsincbackupphp-and-welcomephp)
- [Customer delivery boundary](0022-guiv-delivered-customer-files-must-be-self-contained.md)
- [PharData extraction semantics](https://www.php.net/phardata.extractto.php)
- [PHP TAR entry-injection advisory, CVE-2026-6103](https://github.com/php/php-src/security/advisories/GHSA-j3wh-g957-2m85)
