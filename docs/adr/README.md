# Architecture Decision Records (ADR)

ADRs capture significant technical decisions with their context, options, the chosen path, and consequences. They provide durable traceability between requirements, code, and documentation.

- Location: `docs/adr/`
- One ADR, one subject. Cross‑reference related ADRs when necessary.
- When behavior, interfaces, or security posture change, author an ADR and ship it alongside code, tests, and docs.

## Workflow
1. Create a new ADR from the template: `docs/adr/0001-template.md` → `docs/adr/NNNN-title.md`.
2. Fill in context, options, decision, and consequences; prefer concise prose and links to issues/PRs.
3. Reference the ADR in the PR description and commit messages.
4. Update AGENTS.md or other docs if repository rails change.

## Numbering
Use zero‑padded, incrementing integers (e.g., `0002`, `0003`). Keep filenames stable after merge to preserve links.

## Scope Examples
- Updater behavior or interface changes
- Security posture updates and guardrails
- Observability/logging field standards
- Testing harness structure and CI gates

