# PMSS Codex CLI Helpers (`development/`)

This directory contains small wrapper scripts that assemble **strict-rails prompts**
and feed them to an assistant CLI (codex/claude/gemini).

These wrappers exist because assistants do not reliably auto-discover PMSS guard rails
(`AGENTS.md`, workflow docs, ADRs) unless we inject them into the initial session context.

## Layout

- `development/agentic.sh`
  - Main entrypoint for assistant sessions; choose `--agent` (defaults to `codex`).
  - Uses per-agent profiles under `development/assistants/`.

- `development/agentic-refactor.sh`
  - Refactor-oriented session (same flow as the legacy codex wrapper).
  - Accepts `--agent` and uses the strict refactor rails prompt.

- `development/agentic-ci.sh`
  - CI triage session (same flow as the legacy codex wrapper).
  - Accepts `--agent` and uses the CI rails prompt.

- `development/codex-run.sh`
  - Shared runner used by the wrappers below.
  - Loads a prompt file (or `--prompt` override), renders a **uniform context header**, appends `.codex-prompt`,
    and invokes the assistant executable.
  - Supports `--dry-run` to write the assembled prompt but skip invocation.

- `development/codex.sh`
  - Compatibility shim for `development/agentic.sh --agent=codex`.

- `development/codex-refactor.sh`
  - Compatibility shim for `development/agentic-refactor.sh --agent=codex`.

- `development/codex-ci.sh`
  - Compatibility shim for `development/agentic-ci.sh --agent=codex`.

- `development/assistants/*`
  - Per-agent profiles (plain text templates with a single command line).
  - First non-comment line is the exec template.
  - Default guidance: keep these as the bare binary name (e.g. `codex`, `claude`, `gemini`) and let `development/codex-run.sh` decide how to pass the prompt.
  - Advanced (supported, but not the default): placeholders may be used in a profile command line:
    - `##PROMPT##` (inline prompt arg)
    - `##PROMPT_FILE##` (path to prompt file)
    - `##PROMPT_STDIN##` (stdin redirection; varies by CLI and may break interactive modes)

- `development/prompts/*.txt`
  - The canonical default prompts for the wrappers above.
  - These prompts are intentionally explicit about PMSS invariants and do-not-touch rules.
  - Shared “Context to open” rails block is injected by `development/lib/codex-common.sh` for every session (plus any extra per-run context files).

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

- `AGENTS.<agent>.local.md`
  - Optional per-agent rails for the selected assistant.
  - Gitignored (local only).

- `.codex-prompt`
  - Optional Codex-only notes appended to the end of prompts produced by these wrappers.
  - Useful for local environment quirks, personal workflow, temporary guard rails, etc.
  - Gitignored (local only).

## Usage

General session:

```bash
development/agentic.sh
development/agentic.sh --agent claude
development/agentic.sh --agent gemini
development/agentic.sh --agent gemini -- --approval-mode yolo
```

## Gemini CLI notes

Gemini CLI respects `.gitignore` filtering by default. Since `AGENTS.local.md` and
`AGENTS.*.local.md` are intentionally gitignored, Gemini may refuse to read them
unless you explicitly allow them.

Create a local-only `.geminiignore` in the repo root (this file is gitignored by
PMSS on purpose so each developer can tune it):

```gitignore
!AGENTS.md
!AGENTS.*.md
!AGENTS.local.md
!AGENTS.*.local.md
```

Interactive mode: Gemini's `-i/--prompt-interactive` requires a real TTY. If you
see errors about interactive mode not being allowed when input is "piped", run
Gemini without `-i` or re-run from an interactive terminal session.

Approval prompts: Gemini defaults to prompting for tool approval. If you want to
keep approval mode `default` but avoid approving every trivial command, run
Gemini with `--allowed-tools ...` (tool names come from Gemini's approval UI),
or set that in your local `development/assistants/gemini` profile.

Claude permission prompts: Claude Code also prompts per session by default.
You can reduce prompt spam by passing `--permission-mode acceptEdits` and an
`--allowed-tools ...` list (see `claude --help` for tool syntax), or set that
in your local `development/assistants/claude` profile.

Direct runner usage (advanced):

```bash
development/codex-run.sh run --prompt-file development/prompts/codex.txt
development/codex-run.sh run --prompt-file development/prompts/refactor.txt --context /tmp/some-context.txt
development/codex-run.sh run --prompt "Do X, but follow PMSS rails"
development/codex-run.sh run --prompt-file development/prompts/codex.txt --dry-run
```

Refactor session:

```bash
development/agentic-refactor.sh
development/agentic-refactor.sh --agent gemini -- --approval-mode yolo
```

CI triage session (uses `gh auth login` when available; otherwise uses `curl` + `GITHUB_TOKEN`):

```bash
development/agentic-ci.sh
```

Override the top-level prompt text (keeps the same rails baseline):

```bash
development/agentic.sh --prompt "Do X in Y"
development/agentic-refactor.sh --prompt "Refactor Z (behaviour-preserving)"
development/agentic-ci.sh --prompt "Fix the failing CI job"
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
