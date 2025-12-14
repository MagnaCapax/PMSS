# PMSS Codex CLI Helpers (`development/`)

This directory contains small wrapper scripts that assemble **strict-rails prompts**
and feed them to an assistant CLI (typically `codex`).

These wrappers exist because assistants do not reliably auto-discover PMSS guard rails
(`AGENTS.md`, workflow docs, ADRs) unless we inject them into the initial session context.

## Layout

- `development/codex-run.sh`
  - Shared runner used by the wrappers below.
  - Loads a prompt file (or `--prompt` override), renders a **uniform context header**, appends `.codex-prompt`,
    and invokes the assistant executable.
  - Supports `--dry-run` to write the assembled prompt but skip invocation.

- `development/codex.sh`
  - Starts a new general-purpose Codex session for PMSS work.
  - Thin wrapper around `development/codex-run.sh`.

- `development/refactor-codex.sh`
  - Refactor-oriented session: collects local candidate context (recent commits + LOC/phploc snapshots),
    then launches Codex with the strict refactor rails prompt.
  - Delegates prompt rendering + invocation to `development/codex-run.sh`.

- `development/ci-codex.sh`
  - CI triage session: fetches latest GitHub Actions run logs/artifacts via `gh`,
    then launches Codex with the CI rails prompt.
  - Delegates prompt rendering + invocation to `development/codex-run.sh`.

- `development/prompts/*.txt`
  - The canonical default prompts for the wrappers above.
  - These prompts are intentionally explicit about PMSS invariants and do-not-touch rules.
  - `development/prompts/context-header.txt` + `development/prompts/context-footer.txt` are the shared “rails context” blocks appended to all sessions (plus any extra per-run context files).

- `development/lib/codex-common.sh`
  - Shared, dependency-free helpers sourced by the wrapper scripts.

## How prompts are assembled

All wrappers ultimately call `development/codex-run.sh run`, which builds a prompt that looks like:

- the selected base prompt text (`development/prompts/*.txt` unless overridden by `--prompt`)
- a **uniform** “Context to open” list (core rails + relevant artifacts)
- optional local notes appended from `.codex-prompt`

This keeps every session consistent: regardless of whether you start from CI triage or a refactor run,
the assistant is told to open the same core rails before touching code.

## Local operator notes

Two local-only (gitignored) files exist for per-machine or per-operator notes:

- `AGENTS.local.md`
  - Host-specific rails/instructions for *any* agent work in this repo.
  - This is referenced by `AGENTS.md` and is expected to live at repo root.
  - Gitignored (local only).

- `.codex-prompt`
  - Optional Codex-only notes appended to the end of prompts produced by these wrappers.
  - Useful for local environment quirks, personal workflow, temporary guard rails, etc.
  - Gitignored (local only).

## Usage

General session:

```bash
development/codex.sh
```

Direct runner usage (advanced):

```bash
development/codex-run.sh run --prompt-file development/prompts/codex.txt
development/codex-run.sh run --prompt-file development/prompts/refactor.txt --context /tmp/some-context.txt
development/codex-run.sh run --prompt "Do X, but follow PMSS rails"
development/codex-run.sh run --prompt-file development/prompts/codex.txt --dry-run
```

Refactor session:

```bash
development/refactor-codex.sh
```

CI triage session (requires `gh auth login`):

```bash
development/ci-codex.sh
```

Override the top-level prompt text (keeps the same rails baseline):

```bash
development/codex.sh --prompt "Do X in Y"
development/refactor-codex.sh --prompt "Refactor Z (behaviour-preserving)"
development/ci-codex.sh --prompt "Fix the failing CI job"
```

## Safety and conventions

- These wrappers are designed to keep PMSS “rails” front-and-center:
  - Read `AGENTS.md` + `AGENTS.local.md` + relevant docs/ADRs.
  - Keep PHP compatibility at 7.3.
  - Never touch frozen trees (notably `etc/skel/www`).
- The prompts should evolve, but avoid churn:
  - Don’t reorder or rewrap prompts unless it reduces duplication or fixes correctness.
  - Prefer changing the prompt text files under `development/prompts/` over embedding long heredocs in scripts.

## Unattended usage and safety

These scripts are designed to be safe to run unattended as *launchers*, but they do not prevent the assistant
from making edits. The safety model is:

- The rails are injected into the assistant’s starting context.
- The prompts demand invariant declarations, danger audits, and verification steps.
- The repository doctrine still applies: stability over perfection, minimal diffs, no unsafe shell, etc.

If you want a “dry-run / proposal-only” workflow, handle that at the assistant level (prompt / tool config),
not by changing these launchers to refuse edits.

## Future: expanding `development/`

This directory is intentionally small and dependency-free. If we later relocate or
absorb additional developer tooling (for example `scripts/testing/`), treat it as
a deliberate migration:

- Inventory call sites (`rg -n "scripts/testing/"` and `rg -n "development/"`).
- Update docs that reference these paths.
- Consider a compatibility bridge (thin wrappers left behind at the old paths)
  so operator habits don’t break.
- Land the move as one coherent change with a clear commit message.

## ADR browsing note

Some lints discourage linking directly to the ADR directory from README files.
Use `development/adr-list.sh` to browse ADRs by keyword/category.
