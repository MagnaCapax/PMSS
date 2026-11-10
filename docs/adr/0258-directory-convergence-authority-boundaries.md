# ADR 0258: Directory convergence authority boundaries

Date: 2026-11-10
Category: architecture

## Status
Accepted

## Context
`user/directories.php` combined public path validation, identity lookup, ancestor
authority selection, account-shell commands, managed directory creation and leaf
metadata repair. The owner and group repairs repeated the same conditional
update flow. These helpers run during provisioning and maintenance, so operation
order, diagnostics and the account/root boundary are compatibility requirements.

## Options Considered
- Keep the mixed file and separate owner/group repair branches.
- Introduce a general filesystem-operation framework or parallel directory API.
- Retain the public include, separate execution by authority, and share ordered
  ownership repair within the existing managed traversal.

## Decision
Keep `directories.php` as the public include, validator and authority router.
`directoryAccount.php` owns the unchanged account-scoped command helper.
`directoryManaged.php` owns segment creation and leaf metadata convergence after
the router has admitted that authority. Both modules only define functions;
loading the facade performs no directory operations.

Use one ordered UID/GID repair loop. Snapshot both IDs before changing either,
keep owner before group, attempt group repair even after successful owner repair,
and emit one ownership note if either operation succeeds. Combine nested mode
and identity-lookup guards without changing their evaluation order or predicates.
No original callable is removed or renamed, and no old implementation remains.

## Consequences
- Account paths keep their original commands, per-segment modes and sentinel;
  no account path gains root mutation authority.
- Path, symlink, identity, ownership and writable-ancestor checks remain intact.
  Creation retains its umask lifetime, and metadata failures remain fail-soft.
- Three focused files replace the mixed file: 256 runtime lines become 251.
  Two independent ownership repair flows become one. The facade is 133 lines,
  account execution 47, and managed traversal/metadata 71.
- The include gains two local module dependencies; callers keep the existing
  include path and signatures. Direct use of internal traversal is unsupported.

## Security Review
The relevant attack surfaces are malformed path bytes and symlink substitution
around privileged pathname operations. PHP's historical path-handling fixes
include CVE-2015-2348 and CVE-2015-4598; GNU chmod documents link-following risks.
Those histories motivate preserving all raw-byte, symlink and authority guards,
rather than generalizing the account and root mutation paths together.

No credentials are read, stored or logged by this change. Primary/service
credential separation is unaffected. Directory modes, account execution, warning
bytes and exposure paths remain unchanged; no endpoint, bind or port is added.
An authority regression could affect host files, so the characterization lock
explicitly covers foreign ancestors, account delegation and non-root execution.

## Validation
`DirectoryConvergenceCharacterizationTest` freezes identity and metadata read
order, every mode/owner/group success combination, missing/converged metadata,
authority refusals/delegation and original exceptions/errors. It exports the
public implementation beside memory-only probes and can also characterize the
unsplit implementation. `DirectoryAccountCharacterizationTest` freezes exact
command bytes, parent/leaf order, failures, home sentinels and rejected inputs.
`CreationUmaskSafetyTest` follows the managed creation function into its module;
its existing assertions remain unchanged. Existing directory tests retain their
filesystem and symlink coverage.

Only changed-file PHP syntax checks and static reference/diff review run on the
devbox. The testing VM and CI execute the behavior locks; no live directory
repair or provisioning dry run is performed here.

## References
- [Function contracts](../contracts.md)
- [Operational safety](../security/operational-safety.md)
- [PHP 5 changelog](https://www.php.net/ChangeLog-5.php)
- [GNU chmod behavior](https://www.gnu.org/software/coreutils/manual/html_node/chmod-invocation.html)
