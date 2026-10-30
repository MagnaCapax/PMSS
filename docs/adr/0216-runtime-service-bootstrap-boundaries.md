# ADR 0216: Runtime service bootstrap boundaries

Date: 2026-10-30
Category: architecture

## Status
Accepted

## Context

The phase-2 service bootstrap combines hostname overrides, SSH text transforms,
guarded config publication, SSH recovery priorities, inherited OOM-score repair
and runtime-template orchestration. Updater and development consumers depend on
its include and public helper names. The standalone phase-1 updater must remain
independent of these modules.

## Options Considered

- Keep the mixed file: preserves callers but couples independent responsibilities.
- Add a generic service-policy registry: adds a schema around deliberately
  different hostname, SSH and process policies.
- Keep the facade and separate definition-only modules behind it.

## Decision

Keep dependencies and `pmssApplyRuntimeTemplates()` in `services/bootstrap.php`.
Move installer hostname convergence to `hostname.php`, SSH transforms and guarded
publication to `sshdConfig.php`, SSH drop-in/revalidation policy to `sshd.php`, and
OOM-score repair to `oomImmunity.php`. Preserve every public name and signature.
The facade loads all modules before callers execute maintenance.

Remove three independent pieces of internal machinery: the SSH directive-presence
map, duplicated self/tenant write/readback accounting, and nested initial-success
handling around fallback validation. Rendered lines determine missing directives;
one local repair callback updates the existing counters; initial validation returns
immediately on success and failures follow one retry path.

## Consequences

- Include paths, commands, diagnostics, return values and execution order stay
  stable. In particular, self OOM repair completes before process enumeration.
- Duplicate SSH directives remain duplicate replacements; absent lists append
  in their historical order. CRLF normalization and trailing newlines are preserved.
- Missing SSH executables still refuse fallback and restart. Failed reads/writes
  still reach the existing revalidation step; failed revalidation refuses restart.
- Managed-path guards, backups, root ownership, permissions, process selection,
  algorithm lists and authentication settings retain their policies. These helpers
  handle no account passwords or service credentials and add no logging of secrets,
  listener, public name, DNS record, certificate or customer web endpoint.
- Four definition-only source modules must accompany the facade. Phase 2 already
  runs after the complete repository tree is staged. No alternate implementation
  remains in the runtime tree.
- Characterization covers byte output, warning order, retries, read/write traces,
  failed writes and failed readbacks. Existing tests retain the dry-run command
  sequence, guarded file publication and process-selection matrix. Only syntax
  checks run on the devbox; execution belongs to the testing VM and CI.

## Security Review

OpenSSH's published history includes a pre-authentication resource-exhaustion
issue (CVE-2025-26466), the July 2024 pre-authentication signal race with potential
root code execution, and forwarding-policy errors. These illustrate the host-wide
blast radius of SSH listener/configuration changes. This decomposition preserves
the existing command and validation gates and all template content. It does not
read or store a primary or service credential; the account/service credential
separation and secret-file permissions are outside the changed flows. Existing
config backup and root-owned publication calls are retained verbatim.

## References

- [OpenSSH security history](https://www.openssh.org/security.html)
- ADR 0036: PMSS-owned config generation
- ADR 0210: Systemd service policy boundaries
- `scripts/lib/tests/development/serviceBootstrapCharacterizationTest.php`
- `scripts/lib/tests/development/updateServicesRuntimeTest.php`
