# ADR 0002: ADR Discoverability, Categories, and Minimal Metadata

Date: 2025-11-06
Status: Accepted
Category: architecture

## Context
- As the number of ADRs grows, keyword discovery by filename alone becomes unreliable. We want a light approach that preserves KISS/YAGNI while making relevant ADRs easy to find without README coupling.

## Decision
- Introduce a single required Category line in each ADR: one of `architecture`, `security`, `data`, or `domain`.
- Require a clear H1 title format: `# ADR NNNN: <Descriptive Title>`.
- Enforce modest minimums on title descriptiveness (≥ 3 words and ≥ 20 characters after the colon).
- Provide a small CLI helper `scripts/cli/adr-list.sh` to list and filter ADRs by category and keywords (slug/title/tags), avoiding README cross-links.
- Keep README free of direct ADR links (guardrail lint enforces this), ensuring ADRs remain discoverable via `docs/adr/` and the CLI helper.

## Categories
- architecture: structure, conventions, routing/envelopes, error/logging, controller↔model pairing, doctrine/lints
- security: authentication/signatures, nonces/timestamps, policy/ACL doctrines, unified error strategies
- data: persistence engines, idempotency and caches, schema normalization/TTLs
- domain: business invariants and flows specific to PMSS

## Consequences
- Authors add `Category:` once per ADR and ensure the title is reasonably descriptive.
- Contributors can quickly discover ADRs with `scripts/cli/adr-list.sh --category <cat> [keywords...]`.
- No central indexes or README links are required; discovery remains local and maintainable.

## Guardrails
- A doctrine lint enforces: H1 presence/format, minimal title length/words, Category presence and validity, and forbids README links to `docs/adr/`.
- Filename slugs remain advisory for now; we may introduce stricter slug checks if discovery becomes difficult.

## Rationale
- Keeps documentation light while making relevant decisions easy to find.
- Aligns with KISS/YAGNI/DRY by avoiding duplicated indices and heavyweight metadata.

## Validation
- Run `bash scripts/testing/doctrine-lint.sh` (or `scripts/testing/test-all.sh`) to apply lints.
- Use `scripts/cli/adr-list.sh --category data idempotency` to find relevant ADRs quickly.
