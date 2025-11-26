#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
source "$ROOT/scripts/cli/lib/codex-common.sh"

# Optional debug: PMSS_REFACTOR_CODEX_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_REFACTOR_CODEX_DEBUG "refactor-codex"

codex_set_error_trap "refactor-codex"

echo "[refactor-codex] start: assembling refactor context and invoking assistant" >&1

# refactor-codex.sh — Analyze recent commits and complexity reports, then
# assemble a strict-rails refactor prompt for a coding assistant.
#
# Usage:
#   scripts/cli/refactor-codex.sh                            # assemble prompt into pmss-refactor-codex/prompt.txt
#   scripts/cli/refactor-codex.sh --commits 10               # include last 10 commits (default)
#   scripts/cli/refactor-codex.sh --target scripts/lib/update # narrow scope to a subtree
#   scripts/cli/refactor-codex.sh --prompt "text..."         # use custom high-level prompt text
#   scripts/cli/refactor-codex.sh --exec 'codex'             # send prompt to Codex CLI directly

TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-refactor-codex-XXXXXXXX")"
COMMITS_SUMMARY="$OUTDIR/commits-summary.txt"
COMMITS_FILES="$OUTDIR/commits-files.txt"
CANDIDATES="$OUTDIR/candidate-files.txt"
LOC_LOG="$OUTDIR/loc-snapshot.txt"
PHPLC_LOG="$OUTDIR/phploc-snapshot.txt"
PROMPT="$OUTDIR/prompt.txt"

commits=10
target=""
exec_cmd=""
custom_prompt=""

while [[ $# -gt 0 ]]; do
	case "$1" in
	--commits)
		commits=${2:-}
		shift 2 || true
		;;
	--target)
		target=${2:-}
		shift 2 || true
		;;
	--exec)
		exec_cmd=${2:-}
		shift 2 || true
		;;
	--prompt)
		custom_prompt=${2:-}
		shift 2 || true
		;;
	-h | --help)
		sed -n '1,120p' "$0"
		exit 0
		;;
	*)
		echo "[refactor-codex] unknown option: $1" >&2
		exit 2
		;;
	esac
done

if ! [[ "$commits" =~ ^[0-9]+$ ]] || [[ "$commits" -le 0 ]]; then
	echo "[refactor-codex] invalid --commits value: $commits" >&2
	exit 2
fi

echo "[refactor-codex] output directory: $OUTDIR" >&1

# Gather recent commits and touched files (best-effort).
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "[refactor-codex] collecting last $commits commits…" >&1
	git -C "$ROOT" log -n "$commits" --pretty=format:'%h %s' >"$COMMITS_SUMMARY" || true
	git -C "$ROOT" log -n "$commits" --name-only --pretty=format:'--- %H' \
		| awk '/^--- / { next } NF { print }' \
		| sort -u >"$COMMITS_FILES" || true
else
	echo "[refactor-codex] not inside a git repository; skipping commit context" >&1
fi

# Build a candidate file list from recent commits, optionally narrowed by target.
: >"$CANDIDATES"
if [[ -s "$COMMITS_FILES" ]]; then
	if [[ -n "$target" ]]; then
		awk -v t="$target" 'index($0, t) > 0' "$COMMITS_FILES" >"$CANDIDATES" || true
		if [[ ! -s "$CANDIDATES" ]]; then
			cp "$COMMITS_FILES" "$CANDIDATES"
		fi
	else
		cp "$COMMITS_FILES" "$CANDIDATES"
	fi
fi

# Ensure advisory complexity snapshots exist (best-effort).
if [[ -x "$ROOT/scripts/testing/loc.sh" ]]; then
	echo "[refactor-codex] generating LOC snapshot via scripts/testing/loc.sh" >&1
	bash "$ROOT/scripts/testing/loc.sh" >"$LOC_LOG" 2>&1 || true
fi
if [[ -x "$ROOT/scripts/testing/phploc.sh" ]]; then
	echo "[refactor-codex] generating phploc snapshot via scripts/testing/phploc.sh" >&1
	bash "$ROOT/scripts/testing/phploc.sh" >"$PHPLC_LOG" 2>&1 || true
fi

DEFAULT_PROMPT=$(
	cat <<'PMSSREFACTORPROMPT'
PMSS Refactor Assist — Strict Rails Mode

Goal: Apply small, behaviour-preserving refactors that simplify PMSS while keeping all existing safety and update rails intact.

Read first (do not proceed until read):
- AGENTS.md (rails / Constitution / engineering doctrine, including overwrite and refactor rules).
- docs/architecture.md (bootstrap/update topology, module responsibilities).
- docs/update.md (install.sh → scripts/update.php → scripts/util/update-step2.php flow and invariants).
- docs/refactoring.md (Linux kernel style guidance, ~150 LOC per file targets, when to split helpers).
- docs/adr/* (Accepted ADRs relevant to the target area).

Core engineering principles to honour (do not dilute them):
- KISS / DRY / YAGNI: keep implementations small and boring; reuse existing helpers; do not build abstractions or flags you do not need right now.
- Deletion-First: prefer removing dead or redundant code over adding new layers; "the best part is no part".
- Pit of Success: make the safe, correct path the default; never make dangerous paths easier or quieter.
- One flow, no special cases: keep a single explicit path per operation; avoid adding alternate modes unless an ADR requires it.
- Minimal edits: keep diffs small, coherent, and tightly scoped; avoid drive-by changes.
- No aliases: keep names and env keys consistent with existing ones; do not add synonyms.

Scope for this refactor:
- Focus on files touched in the last N commits and the candidate file list assembled below.
- Optionally narrow the scope via a target subtree (for example, scripts/lib/update or scripts/util).
- Use LOC/complexity snapshots to pick the highest-value small target within that set.
- Do not roam outside this scope unless strictly necessary to achieve DRY within the allowed change budget.

Hard rails (must follow all of these):
- No behaviour changes:
  - Do not change CLI flags, argument semantics, env variables, JSON field names, log formats, or exit codes.
  - Treat existing scripts under scripts/, util tools, and tests as behavioural contracts.
- No safety regressions:
  - Do not introduce new destructive operations or weaken existing guards around filesystem, packages, or users.
  - Never modify etc/skel/www or third-party/vendor trees, or the dpkg baseline files under scripts/lib/update/dpkg/selections*.txt.
  - Do not change updater topology or semantics: install.sh → scripts/update.php (bootstrap) → scripts/util/update-step2.php (orchestration).
- Minimal, local edits:
  - Prefer a single file or tight cluster of files in one subsystem.
  - Aim to touch at most ~3–5 files and keep total additions + deletions within a few hundred lines.
  - If a refactor cannot be completed safely within this budget, do not start it.
- Deletion and DRY as primary goals:
  - Remove unused helpers, dead code paths, or redundant wrappers when you can prove they are unreferenced.
  - Consolidate near-duplicate logic into small shared helpers instead of adding new flows.
  - Do not add new modes, flags, or configuration knobs as part of this refactor.
- No interface/layout changes:
  - Do not move or rename public entrypoints, config file locations, or JSON structures.
  - Only adjust docs to better describe existing behaviour; do not introduce new semantics.
- Tests and verification:
  - Do not relax or delete tests that encode behaviour.
  - You may add tests around code you simplify, but they must keep or tighten expectations.
  - Keep refactors hermetic: no new network calls or destructive actions in dev tests.

Refactor style guidance:
- Follow docs/refactoring.md:
  - When a file grows beyond roughly 150–200 lines of real code, consider splitting cohesive pieces into focused helpers.
  - Keep new files small and single-purpose; prefer many tiny modules over monoliths.
- Choose a small, high-value target:
  - Prefer a file or helper that appears in recent commits and shows high complexity or duplication.
  - Avoid broad, cross-cutting changes or sweeping renames in a single run.
- Look for:
  - Obvious duplication that can be replaced with a data table or a small helper.
  - Overly nested conditionals that can be simplified without changing logic.
  - Trivial wrappers that add no clarity; consider inlining them when safe.
- Cognitive load:
  - Optimise for readability under fatigue; fewer concepts and flows are better.

Workflow (do this now):
1) Read AGENTS.md, docs/architecture.md, docs/update.md, docs/refactoring.md, and relevant docs/adr/* files.
2) Inspect the recent commits, changed files, and complexity/LOC snapshots listed below.
3) Pick one small refactor or deletion opportunity that fits the rails above.
4) Implement the change with minimal edits:
   - Keep behaviour and outputs identical.
   - Prefer deletion and DRY improvements; avoid adding new features or modes.
   - Keep changes local to the chosen scope.
5) Run local verification:
   - php -l on each changed PHP file.
   - php scripts/lib/tests/development/Runner.php when touching scripts/lib PHP helpers or CLI PHP entrypoints.
   - scripts/testing/test-php.sh
   - scripts/testing/test-bash.sh
   - scripts/testing/php73-compat-scan.sh
6) Stage and commit with a clear, focused message before finishing. Do not create new branches or push from this flow.
7) Summarise in your response:
   - What you simplified or deleted.
   - Why it is safe and behaviour-preserving.
   - Which verification commands you ran.

Operate with SpaceX/Tesla-style discipline:
- Respect constraints and safety margins; do not chase cleverness.
- Favour small, iterative improvements that steadily reduce complexity and increase reliability.

Change budget for this run (hard limit):
- Limit this refactor to at most 3 files and 200 total changed lines (additions + deletions).
- If you reach this budget, stop and return the patch; do not start additional refactors in this iteration.
PMSSREFACTORPROMPT
)

	prompt_text=${custom_prompt:-$DEFAULT_PROMPT}
	{
		echo "$prompt_text"
		echo
		echo "Context to open (paths in this workspace):"
		if [[ -f "$COMMITS_SUMMARY" ]]; then
			echo " - $COMMITS_SUMMARY (recent commits summary)"
		fi
		if [[ -f "$COMMITS_FILES" ]]; then
			echo " - $COMMITS_FILES (files touched in the last $commits commits)"
		fi
		if [[ -f "$CANDIDATES" ]]; then
			echo " - $CANDIDATES (candidate files within scope)"
		fi
		if [[ -f "$LOC_LOG" ]]; then
			echo " - $LOC_LOG (LOC + Bash/PHP complexity snapshot)"
		fi
		if [[ -f "$PHPLC_LOG" ]]; then
			echo " - $PHPLC_LOG (phploc aggregate metrics)"
		fi
		echo
		echo "Do not inline these; read them directly from disk."
	} >"$PROMPT"

prompt_bytes=$(wc -c <"$PROMPT" | tr -d ' ')
prompt_lines=$(wc -l <"$PROMPT" | tr -d ' ')
echo "[refactor-codex] prompt written: $PROMPT (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

prompt_str=$(cat "$PROMPT")
if [[ -n "$exec_cmd" && "$exec_cmd" != "codex" ]]; then
	echo "[refactor-codex] unsupported --exec value ('$exec_cmd'); defaulting to 'codex'" >&1
fi
if command -v codex >/dev/null 2>&1; then
	echo "[refactor-codex] invoking: codex [prompt-string]" >&1
	codex "$prompt_str" || {
		echo "[refactor-codex] codex invocation failed. Run manually:" >&1
		echo "  codex \"\$(cat '$PROMPT')\"" >&1
		exit 1
	}
else
	echo "[refactor-codex] Codex CLI not found. Run manually:" >&1
	echo "  codex \"\$(cat '$PROMPT')\"" >&1
fi

# Auto-commit any changes created by the assistant (no branches, no push).
PMSS_REFACTOR_AUTOCOMMIT=${PMSS_REFACTOR_AUTOCOMMIT:-0}
PMSS_REFACTOR_MAX_FILES=${PMSS_REFACTOR_MAX_FILES:-10}
PMSS_REFACTOR_MAX_LINES=${PMSS_REFACTOR_MAX_LINES:-400}

if [[ "$PMSS_REFACTOR_AUTOCOMMIT" == "1" ]]; then
	if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		echo "[refactor-codex] auto-commit: checking for changes" >&1
		if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
			echo "[refactor-codex] auto-commit: running tests (php + bash + compat)" >&1
			if ! php "$ROOT/scripts/lib/tests/development/Runner.php"; then
				echo "[refactor-codex] auto-commit: dev tests failed; NOT committing changes" >&1
				exit 0
			fi
			if ! bash "$ROOT/scripts/testing/test-php.sh"; then
				echo "[refactor-codex] auto-commit: test-php.sh failed; NOT committing changes" >&1
				exit 0
			fi
			if ! bash "$ROOT/scripts/testing/test-bash.sh"; then
				echo "[refactor-codex] auto-commit: test-bash.sh failed; NOT committing changes" >&1
				exit 0
			fi
			if ! bash "$ROOT/scripts/testing/php73-compat-scan.sh"; then
				echo "[refactor-codex] auto-commit: php73-compat-scan failed; NOT committing changes" >&1
				exit 0
			fi

			shortstat=$(git -C "$ROOT" diff --shortstat HEAD 2>/dev/null || true)
			file_count=0
			line_count=0
			if [[ -n "$shortstat" ]]; then
				file_count=$(echo "$shortstat" | awk '{print $1+0}')
				line_count=$(echo "$shortstat" | awk '{
					ins=0; del=0;
					for (i=1; i<=NF; i++) {
						if ($(i+1) ~ /^insertions?\(\+\),?$/) ins=$i;
						if ($(i+1) ~ /^deletions?\(-\),?$/) del=$i;
					}
					print ins+del;
				}')
			fi

			if [[ "$file_count" -gt "$PMSS_REFACTOR_MAX_FILES" || "$line_count" -gt "$PMSS_REFACTOR_MAX_LINES" ]]; then
				echo "[refactor-codex] auto-commit: diff too large (files=$file_count, lines=$line_count; limits files=$PMSS_REFACTOR_MAX_FILES, lines=$PMSS_REFACTOR_MAX_LINES); NOT committing changes" >&1
			else
				msg="refactor-codex: apply assistant refactor"
				git -C "$ROOT" add -A
				if git -C "$ROOT" commit -m "$msg"; then
					echo "[refactor-codex] auto-commit: committed changes" >&1
				else
					echo "[refactor-codex] auto-commit: commit failed" >&1
				fi
			fi
		else
			echo "[refactor-codex] auto-commit: no changes to commit" >&1
		fi
	else
		echo "[refactor-codex] auto-commit: git not available or not inside a repo" >&1
	fi
else
	echo "[refactor-codex] auto-commit disabled (PMSS_REFACTOR_AUTOCOMMIT=0)" >&1
fi

echo "[refactor-codex] done" >&1
