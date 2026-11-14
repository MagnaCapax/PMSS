# ADR 0284: Nginx global setup responsibility boundaries

Date: 2026-11-14
Category: architecture

## Status

Accepted for the operator-approved architectural simplification run.

## Context

Global nginx setup mixed filesystem diagnostics, TLS selection, default-site
publication, account-UI convergence and the per-user render context. Two UI
branches repeated publication and cleanup with different prerequisites. Four
template variables were copied into the context, and certificate completeness
had a separate boolean although the first missing file already decides fallback.

## Options Considered

- Retain the monolith and duplicated UI convergence paths.
- Introduce a general configuration engine, expanding the abstraction surface.
- Keep the existing facade, separate its responsibilities and represent the two
  UI policies as ordered data behind one convergence loop.

## Decision

`setup.php` retains orchestration and its ordered context. `setupFilesystem.php`
owns existing warning/copy/write/directory/removal helpers. `setupTls.php` owns
existing-certificate selection and the hostname-validated self-signed command;
the facade still decides when that command runs. `setupDefaultSite.php` owns
listener repair, hostname/SSL substitution and backup/write/link ordering.
`setupAccountUi.php` owns one ordered table and convergence loop for the two
legacy UI defaults, followed by retired-default cleanup.

Every existing helper remains available through `setup.php`. Includes only
define helpers. The first enabled UI entry attempts shared-page installation;
even a failed attempt suppresses another attempt, matching the original first
entry's enabled flag. Prerequisites are evaluated afresh, in order, for each
entry. Each keeps its original binary requirement, port probe, builder, warning,
root-owned 0644 writer and guarded cleanup.

Certificate selection returns its original fallback on the first unavailable
required file. The default site receives the selected template verbatim; the
render context receives its trimmed form plus a newline when nonempty. Template
reads build the context directly. Original-case certificate paths, lower-case
subdomain names, false templates, regex-failure behavior and exception propagation
retain their contracts. No original implementation is kept alongside the modules.

## Consequences

- Runtime physical lines decrease from 247 to 220; nonblank, noncomment runtime
  lines decrease from 184 to 173. Three redundant mechanisms disappear: the
  parallel UI convergence path, certificate-completeness flag and separate
  template-variable/context representation.
- The new modules add include dependencies, all within the fully staged phase-2
  tree. The standalone bootstrap and customer PHP trees are unchanged.
- `NginxSetupFlowCharacterizationTest` locks context shape and operation traces,
  TLS and default-site bytes, link policies, UI gates, failed shared-page attempts,
  warnings and exception/error stopping points. Existing source-inspection tests
  follow the moved code, including marker and retired-route protections.
- Devbox verification is changed-file PHP syntax and reference inspection only.
  The lane runs characterization and related tests on the testing machine; no
  live service mutation, config generation or dry run executes on the devbox.

## Security review

The [nginx advisory history](https://nginx.org/en/security_advisories.html) includes
SSL session reuse (CVE-2025-23419) and resolver memory overwrite (CVE-2021-23017).
This review therefore treats TLS selection and generated route bytes as security
boundaries. No TLS protocol, session, resolver, authentication or proxy policy
changes. Certificate contents are neither read nor logged; only the existing
presence/readability checks move. Disclosure of a host TLS key would affect
that host's TLS identity, so key paths and the existing creation command remain
unchanged. No account or service credential is stored, copied or generated here.
Primary/service credential separation and secret permissions are untouched;
managed UI files remain root-owned 0644, and the users directory remains 0751.
No public name, certificate request, endpoint or listener is added. Existing host
marker, binary/port checks and retired-route cleanup remain covered by the locks.

## References

- [ADR 0150: No forced public identifiers](0150-no-forced-public-identifiers-only-the-end-user-turns-on-a-public-certificate-or-record.md)
- [Nginx contracts](../contracts.md#utilities-script-contracts)
