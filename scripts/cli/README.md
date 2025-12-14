# PMSS Codex CLI Helpers (`scripts/cli/`)

This directory contains small wrapper scripts that assemble **strict-rails prompts**
and feed them to an assistant CLI (typically `codex`).

These wrappers exist because assistants do not reliably auto-discover PMSS guard rails
(`AGENTS.md`, workflow docs, ADRs) unless we inject them into the initial session context.

## Layout

- `scripts/cli/codex.sh`
  - Starts a new general-purpose Codex session for PMSS work.
  - Uses `scripts/cli/prompts/codex.txt`.
  - Appends optional local notes from `/.codex-prompt` (ignored by git).

- `scripts/cli/refactor-codex.sh`
  - Refactor-oriented session: collects local candidate context (recent commits + LOC/phploc snapshots),
    then launches Codex with the strict refactor rails prompt.
  - Uses `scripts/cli/prompts/refactor.txt`.
  - Appends optional local notes from `/.codex-prompt`.

- `scripts/cli/ci-codex.sh`
  - CI triage session: fetches latest GitHub Actions run logs/artifacts via `gh`,
    then launches Codex with the CI rails prompt.
  - Uses `scripts/cli/prompts/ci.txt`.
  - Appends optional local notes from `/.codex-prompt`.

- `scripts/cli/prompts/*.txt`
  - The canonical default prompts for the wrappers above.
  - These prompts are intentionally explicit about PMSS invariants and do-not-touch rules.

- `scripts/cli/lib/codex-common.sh`
  - Shared, dependency-free helpers sourced by the wrapper scripts.

## Local operator notes

Two local-only (gitignored) files exist for per-machine or per-operator notes:

- `AGENTS.local.md`
  - Host-specific rails/instructions for *any* agent work in this repo.
  - This is referenced by `AGENTS.md` and is expected to live at repo root.
  - Gitignored (local only).

- `/.codex-prompt`
  - Optional Codex-only notes appended to the end of prompts produced by these wrappers.
  - Useful for local environment quirks, personal workflow, temporary guard rails, etc.
  - Gitignored (local only).

## Usage

General session:

```bash
scripts/cli/codex.sh
```

Refactor session:

```bash
scripts/cli/refactor-codex.sh
```

CI triage session (requires `gh auth login`):

```bash
scripts/cli/ci-codex.sh
```

Override the top-level prompt text (keeps the same rails baseline):

```bash
scripts/cli/codex.sh --prompt "Do X in Y"
scripts/cli/refactor-codex.sh --prompt "Refactor Z (behaviour-preserving)"
scripts/cli/ci-codex.sh --prompt "Fix the failing CI job"
```

## Safety and conventions

- These wrappers are designed to keep PMSS “rails” front-and-center:
  - Read `AGENTS.md` + `AGENTS.local.md` + relevant docs/ADRs.
  - Keep PHP compatibility at 7.3.
  - Never touch frozen trees (notably `etc/skel/www`).
- The prompts should evolve, but avoid churn:
  - Don’t reorder or rewrap prompts unless it reduces duplication or fixes correctness.
  - Prefer changing the prompt text files under `scripts/cli/prompts/` over embedding long heredocs in scripts.

## Future: relocating `scripts/cli/`

This directory is intentionally small and dependency-free, but its placement under
`scripts/` is debatable. If we ever move it (for example to a top-level `dev/`),
do it as a deliberate migration:

- Inventory call sites (`rg -n "scripts/cli/"`).
- Update docs that reference these paths.
- Consider a compatibility bridge (thin wrappers left behind at the old paths)
  so existing operator habits don’t break.
- Land the move as one coherent change with a clear commit message.

## ADR browsing note

Some lints discourage linking directly to the ADR directory from README files.
Use `scripts/cli/adr-list.sh` to browse ADRs by keyword/category.
