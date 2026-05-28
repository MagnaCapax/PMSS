#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck disable=SC1091
source "$HERE/lib/codex-common.sh"
codex_agentic_bootstrap "$HERE" "PMSS_REFACTOR_CODEX_DEBUG" "agentic-refactor"

echo "[agentic-refactor] start: assembling refactor context and invoking assistant" >&1

usage() {
	cat <<EOF
Usage:
  development/agentic-refactor.sh [options] [-- <assistant args>]

Purpose:
  Collect refactor context (commits, candidate files, LOC snapshots) and launch
  the refactor prompt via codex-run.

Options:
  --commits N     Number of recent commits to scan (default: ${commits})
  --target PATH   Substring filter for candidate files (best-effort)
  --agent NAME    Assistant profile (default: ${default_agent})
  --exec CMD      Override assistant command line
  --prompt TEXT   Override the default refactor prompt text
  --dry-run       Skip git/loc/phploc collection; show planned actions only
  --autocommit    Enable autocommit rules in the prompt (operator-approved)
  --cooling-files PATH  Files to exclude from refactoring (cooling period)
  -h, --help      Show this help

Assistant CLI args (appended to the exec command):
  --yolo, -y                     Convenience flag (maps to claude danger)
  --approval-mode MODE           Assistant-specific approval mode
  --ask-for-approval POLICY      Codex approval policy (untrusted/on-failure/on-request/never)
  --allowed-tools LIST           Assistant-specific allowed tools list
  --permission-mode MODE         Assistant-specific permission mode
  --dangerously-skip-permissions Pass through as-is

Outputs:
  A temp workspace under \$TMPDIR with commit summaries, candidates, and prompt.

Environment:
  PMSS_AGENTIC_DEFAULT_AGENT  Default agent when --agent is omitted
  PMSS_REFACTOR_CODEX_DEBUG=1 Enable bash -x tracing

Examples:
  development/agentic-refactor.sh --commits 25
  development/agentic-refactor.sh --target scripts/lib/update
  development/agentic-refactor.sh --prompt "Refactor X (behavior-preserving)"
  development/agentic-refactor.sh --agent codex --dry-run
  development/agentic-refactor.sh --agent gemini -- --approval-mode yolo
EOF
}

ASSIST_DIR="$HERE/assistants"
default_agent="${PMSS_AGENTIC_DEFAULT_AGENT:-codex}"
OUTDIR="$(codex_make_temp_workspace pmss-refactor-codex)"

# Parallel-instance scope lock (operator directive 2026-05-28).
# Prevents the rebase-race that lost PMSS commit 584e6499 (6 files / 856 LOC)
# to f02777f1 (3 files / 347 LOC) by ensuring two instances never pick the
# same candidate file. Each instance atomically claims candidate files via
# mkdir (O_CREAT|O_EXCL semantics) in PMSS_REFACTOR_CLAIMS_DIR. Failed claims
# = file already owned by another instance = skip. Stale claims (>8h, codex
# session timeout) are cleaned at start. On exit, this instance's claims are
# released so the next launch sees a clean slate.
CLAIMS_DIR="${PMSS_REFACTOR_CLAIMS_DIR:-/tmp/pmss-refactor-claims}"
mkdir -p "$CLAIMS_DIR" 2>/dev/null || true
# Cleanup stale claims older than 8h (codex-run timeout). Self-heals after
# crashed sessions without manual intervention.
find "$CLAIMS_DIR" -mindepth 1 -maxdepth 1 -mmin +480 -type d -exec rm -rf {} + 2>/dev/null || true
# Track what THIS instance claims for cleanup on exit. Bash EXIT trap.
declare -a PMSS_REFACTOR_CLAIMED=()
pmss_refactor_release_claims() {
	local f
	for f in "${PMSS_REFACTOR_CLAIMED[@]}"; do
		rmdir "$CLAIMS_DIR/$f" 2>/dev/null || true
	done
}
trap pmss_refactor_release_claims EXIT
COMMITS_SUMMARY="$OUTDIR/commits-summary.txt"
COMMITS_FILES="$OUTDIR/commits-files.txt"
CANDIDATES="$OUTDIR/candidate-files.txt"
LOC_LOG="$OUTDIR/loc-snapshot.txt"
PHPLC_LOG="$OUTDIR/phploc-snapshot.txt"

commits=10
target=""
agent=""
exec_cmd=""
declare -a exec_extra_args=()
custom_prompt=""
dry_run=0
autocommit=0
cooling_files=""
declare -a remaining_args=()

codex_parse_launcher_common_args agent exec_cmd dry_run autocommit remaining_args 0 exec_extra_args commits target prompt cooling-files -- "$@"
set -- "${remaining_args[@]}"
while [[ $# -gt 0 ]]; do
	case "$1" in
	--commits)
		codex_parse_option_value commits "$1" "${2:-}" "--commits"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--target)
		codex_parse_option_value target "$1" "${2:-}" "--target"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--prompt)
		codex_parse_option_value custom_prompt "$1" "${2:-}" "--prompt"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--cooling-files)
		cooling_files=${2:-}
		shift 2 || true
		;;
	--)
		shift || true
		if [[ $# -gt 0 ]]; then
			exec_extra_args+=("$@")
		fi
		break
		;;
	-h | --help)
		usage
		exit 0
		;;
	*)
		if [[ "$1" == --* ]]; then
			echo "[agentic-refactor] unknown option: $1" >&2
			echo "[agentic-refactor] hint: pass assistant CLI args after '--', e.g.:" >&2
			echo "  development/agentic-refactor.sh --agent=gemini -- --approval-mode yolo" >&2
		else
			echo "[agentic-refactor] unknown option: $1" >&2
		fi
		exit 2
		;;
	esac
done

if ! [[ "$commits" =~ ^[0-9]+$ ]] || [[ "$commits" -le 0 ]]; then
	echo "[agentic-refactor] invalid --commits value: $commits" >&2
	exit 2
fi

codex_prepare_agent_exec "$ASSIST_DIR" "$default_agent" agent exec_cmd || exit $?

if [[ "${#exec_extra_args[@]}" -gt 0 ]]; then
	# Append extra assistant CLI args (shell-escaped) to the exec string.
	# This keeps agent selection stable while allowing per-run tuning like:
	#   ... -- --approval-mode yolo
	codex_append_exec_extra_args exec_cmd "$agent" "${exec_extra_args[@]}"
fi

# In dry-run mode, avoid running git/phploc/gh/etc. Only show what would run and
# let codex-run.sh print the final assistant invocation.
if [[ "$dry_run" == "1" ]]; then
	echo "[agentic-refactor] dry-run: skipping git/loc/phploc collection" >&1
	echo "[agentic-refactor] dry-run: would run (best-effort):" >&1
	echo "  git -C '$ROOT' log -n '$commits' --pretty=format:'%h %s'" >&1
	echo "  git -C '$ROOT' log -n '$commits' --name-only --pretty=format:'--- %H' | awk ... | sort -u" >&1
	echo "  '$ROOT/development/loc.sh' > '$LOC_LOG'" >&1
	echo "  bash '$ROOT/scripts/testing/phploc.sh' > '$PHPLC_LOG'" >&1
fi

echo "[agentic-refactor] output directory: $OUTDIR" >&1

# Gather recent commits and touched files (best-effort).
if [[ "$dry_run" != "1" ]] && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "[agentic-refactor] collecting last $commits commits…" >&1
	git -C "$ROOT" log -n "$commits" --pretty=format:'%h %s' >"$COMMITS_SUMMARY" || true
	git -C "$ROOT" log -n "$commits" --name-only --pretty=format:'--- %H' |
		awk '/^--- / { next } NF { print }' |
		sort -u >"$COMMITS_FILES" || true
else
	if [[ "$dry_run" == "1" ]]; then
		echo "[agentic-refactor] dry-run: skipping commit context collection" >&1
	else
		echo "[agentic-refactor] not inside a git repository; skipping commit context" >&1
	fi
fi

# Build a candidate file list from recent commits, optionally narrowed by target.
: >"$CANDIDATES"
if [[ "$dry_run" != "1" && -s "$COMMITS_FILES" ]]; then
	if [[ -n "$target" ]]; then
		awk -v t="$target" 'index($0, t) > 0' "$COMMITS_FILES" >"$CANDIDATES" || true
		if [[ ! -s "$CANDIDATES" ]]; then
			cp "$COMMITS_FILES" "$CANDIDATES"
		fi
	else
		cp "$COMMITS_FILES" "$CANDIDATES"
	fi
fi

# Whole-codebase candidates for architectural modes (decompose / dry-consolidate).
# These modes must attack the BIGGEST/oldest files, not just recent-commit churn. The
# "Target the WHOLE codebase" prompt line alone was a text gate that did nothing — codex
# only ever saw the recent-commit candidate list, so behemoths (environment.php, runtime.php,
# update.php, ...) were never offered. This MECHANICALLY feeds the largest runtime PHP files.
if [[ "$dry_run" != "1" ]] && printf '%s' "$custom_prompt" | grep -qE 'refactor\((decompose|dry)\):'; then
	WHOLE_N="${PMSS_REFACTOR_WHOLE_N:-30}"
	{ git -C "$ROOT" ls-files '*.php' 2>/dev/null |
		grep -E '^(scripts|etc/skel/www)/' |
		grep -vE '/(tests|testing|rutorrent|devristo)/' |
		grep -vE '^etc/skel/www/filemanager\.php$' |
		while IFS= read -r f; do
			[[ -f "$ROOT/$f" ]] && printf '%s %s\n' "$(wc -l <"$ROOT/$f" 2>/dev/null || echo 0)" "$f"
		done |
		sort -rn | head -n "$WHOLE_N" | awk '{print $2}'; } >>"$CANDIDATES" || true
	sort -u "$CANDIDATES" -o "$CANDIDATES" 2>/dev/null || true
	echo "[agentic-refactor] whole-codebase mode: added up to $WHOLE_N largest runtime PHP files to candidates" >&1
fi

# Parallel-instance scope claim: filter candidate list to files this instance
# can claim atomically. Files already claimed by parallel instances are
# skipped, ensuring each candidate file belongs to AT MOST ONE active session.
if [[ "$dry_run" != "1" && -s "$CANDIDATES" ]]; then
	PMSS_REFACTOR_FILTERED="$OUTDIR/candidate-files.filtered.txt"
	PMSS_REFACTOR_ORIG_COUNT=$(wc -l <"$CANDIDATES" | tr -d ' ')
	: >"$PMSS_REFACTOR_FILTERED"
	while IFS= read -r pmss_refactor_f; do
		[[ -n "$pmss_refactor_f" ]] || continue
		# Path-safe claim key (no slashes).
		pmss_refactor_key=${pmss_refactor_f//\//_}
		if mkdir "$CLAIMS_DIR/$pmss_refactor_key" 2>/dev/null; then
			printf '%s\n' "$pmss_refactor_f" >>"$PMSS_REFACTOR_FILTERED"
			PMSS_REFACTOR_CLAIMED+=("$pmss_refactor_key")
		fi
	done <"$CANDIDATES"
	mv "$PMSS_REFACTOR_FILTERED" "$CANDIDATES"
	PMSS_REFACTOR_CLAIMED_COUNT=$(wc -l <"$CANDIDATES" | tr -d ' ')
	echo "[agentic-refactor] scope-claim: claimed $PMSS_REFACTOR_CLAIMED_COUNT of $PMSS_REFACTOR_ORIG_COUNT candidates (others held by parallel instances)" >&1
fi

# Ensure advisory complexity snapshots exist (best-effort).
if [[ "$dry_run" != "1" && -x "$ROOT/development/loc.sh" ]]; then
	echo "[agentic-refactor] generating LOC snapshot via development/loc.sh" >&1
	"$ROOT/development/loc.sh" >"$LOC_LOG" 2>&1 || true
fi
if [[ "$dry_run" != "1" && -x "$ROOT/scripts/testing/phploc.sh" ]]; then
	echo "[agentic-refactor] generating phploc snapshot via scripts/testing/phploc.sh" >&1
	bash "$ROOT/scripts/testing/phploc.sh" >"$PHPLC_LOG" 2>&1 || true
fi

# Build cooling period context if files were specified (Joukahainen Round 8 defense)
COOLING_CTX="$OUTDIR/cooling-period.txt"
if [[ -n "$cooling_files" && -s "$cooling_files" ]]; then
	{
		echo "REFACTOR COOLING PERIOD (BINDING)"
		echo "The following files were committed by the issues pass in this cycle."
		echo "DO NOT refactor these files. They need stability before refactoring."
		echo "This prevents a two-stage attack where issues pass introduces code and"
		echo "refactor pass accidentally removes security checks embedded in complexity."
		echo ""
		cat "$cooling_files"
	} >"$COOLING_CTX"
	echo "[agentic-refactor] cooling period: excluding $(wc -l <"$cooling_files" | tr -d ' ') file(s) from refactoring" >&1
fi

codex_context_args=()
[[ -s "$COMMITS_SUMMARY" ]] && codex_context_args+=(--context "$COMMITS_SUMMARY")
[[ -s "$COMMITS_FILES" ]] && codex_context_args+=(--context "$COMMITS_FILES")
[[ -s "$CANDIDATES" ]] && codex_context_args+=(--context "$CANDIDATES")
[[ -s "$LOC_LOG" ]] && codex_context_args+=(--context "$LOC_LOG")
[[ -s "$PHPLC_LOG" ]] && codex_context_args+=(--context "$PHPLC_LOG")
[[ -s "$COOLING_CTX" ]] && codex_context_args+=(--context "$COOLING_CTX")
codex_run_prompt "$HERE" "$HERE/prompts/refactor.txt" "$OUTDIR" "$ROOT" "$agent" "$exec_cmd" "$dry_run" "$autocommit" "$custom_prompt" 1 "${codex_context_args[@]}"

echo "[agentic-refactor] done" >&1
