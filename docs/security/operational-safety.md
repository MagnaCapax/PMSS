# Operational Safety (PMSS)

PMSS manages multi-tenant hosts on bare metal. Scripts must favor safety and reversible actions.

## Core Practices
- Idempotence: reruns converge to the same state. Avoid stateful side-effects without guards.
- Dry-Runs: honor `--dry-run` and environment flags (e.g., `PMSS_DRY_RUN=1`) to log intent without changes.
- Backups Before Mutations: for critical files (sources, fstab, sshd), write backups and restore on failure.
- Confirmation Barriers: destructive steps (partitioning, formatting, wiping) require explicit operator intent and additional checks.
- Least Privilege: restrict scope of changes; prefer per-user operations when possible over global effects.

## Multi-Tenant Considerations
- Preserve per-user isolation: avoid shared writable locations across tenants; enforce permissions.
- Rate-limit/serialize operations that impact shared resources (IO, CPU) to avoid noisy neighbor effects.
- Avoid leaking tenant data via logs; redact usernames and paths unless necessary for diagnosis.

## Recovery Patterns
- Use `runStep()` wrappers to capture stdout/stderr with rc and duration.
- On failure, log concise remediation hints and proceed when safe.
- Provide quick exit paths when system prerequisites are unmet (e.g., unknown distro codenames → skip repo rewrite).

## Execution Modes
- Development tests must be hermetic and never touch the real filesystem or network.
- Production probes are read-only and validate presence/versions.

## Change Management
- For high-risk changes, add an ADR and plan dry-run rehearsals with JSON/profile logs attached to the PR.
- Document any rollback steps in the PR and link relevant runbooks.

