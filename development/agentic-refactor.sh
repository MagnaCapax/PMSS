#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_REFACTOR_CODEX_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_REFACTOR_CODEX_DEBUG "agentic-refactor"
codex_set_error_trap "agentic-refactor"

echo "[agentic-refactor] start: assembling refactor context and invoking assistant" >&1

# agentic-refactor.sh — Collect refactor candidate context (best-effort), then launch
# a coding assistant with the strict refactor prompt.
#
# Usage:
#   development/agentic-refactor.sh
#   development/agentic-refactor.sh --commits 25
#   development/agentic-refactor.sh --target scripts/lib/update
#   development/agentic-refactor.sh --prompt "Refactor X (behaviour-preserving)"
#   development/agentic-refactor.sh --exec 'codex'
#   development/agentic-refactor.sh --agent codex
#   development/agentic-refactor.sh --dry-run

TMP="${TMPDIR:-/tmp}"
ASSIST_DIR="$HERE/assistants"
default_agent="${PMSS_AGENTIC_DEFAULT_AGENT:-codex}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-refactor-codex-XXXXXXXX")"
COMMITS_SUMMARY="$OUTDIR/commits-summary.txt"
COMMITS_FILES="$OUTDIR/commits-files.txt"
CANDIDATES="$OUTDIR/candidate-files.txt"
LOC_LOG="$OUTDIR/loc-snapshot.txt"
PHPLC_LOG="$OUTDIR/phploc-snapshot.txt"

commits=10
target=""
agent=""
exec_cmd=""
custom_prompt=""
dry_run=0
autocommit=0

agentic_list_agents() {
	local f
	if compgen -G "$ASSIST_DIR/*" >/dev/null; then
		for f in "$ASSIST_DIR"/*; do
			[[ -f "$f" ]] || continue
			basename "$f"
		done
	fi
}

agentic_read_profile_cmd() {
	local profile="$1" line=""
	while IFS= read -r line || [[ -n "$line" ]]; do
		line="${line%$'\r'}"
		[[ -z "$line" ]] && continue
		[[ "$line" =~ ^[[:space:]]*# ]] && continue
		echo "$line"
		return 0
	done <"$profile"
	return 1
}

while [[ $# -gt 0 ]]; do
	case "$1" in
	--agent)
		agent=${2:-}
		shift 2 || true
		;;
	--agent=*)
		agent=${1#--agent=}
		shift || true
		;;
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
	--dry-run)
		dry_run=1
		shift || true
		;;
	--autocommit)
		autocommit=1
		shift || true
		;;
	-h | --help)
		sed -n '1,120p' "$0"
		exit 0
		;;
	*)
		echo "[agentic-refactor] unknown option: $1" >&2
		exit 2
		;;
	esac
done

if ! [[ "$commits" =~ ^[0-9]+$ ]] || [[ "$commits" -le 0 ]]; then
	echo "[agentic-refactor] invalid --commits value: $commits" >&2
	exit 2
fi

if [[ -z "$agent" ]]; then
	agent="$default_agent"
fi

if [[ -z "$exec_cmd" ]]; then
	profile="$ASSIST_DIR/$agent"
	if [[ -f "$profile" ]]; then
		exec_cmd="$(agentic_read_profile_cmd "$profile" || true)"
		if [[ -z "$exec_cmd" ]]; then
			echo "Error: Agent profile '$profile' has no command line." >&2
			exit 2
		fi
	elif command -v "$agent" >/dev/null 2>&1; then
		exec_cmd="$agent"
	else
		echo "Error: Agent '$agent' not available." >&2
		echo >&2
		echo "Available agents with profiles:" >&2
		agentic_list_agents | sed 's/^/  - /' >&2
		echo >&2
		echo "Or use --exec to specify a custom command." >&2
		exit 2
	fi
fi

echo "[agentic-refactor] output directory: $OUTDIR" >&1

# Gather recent commits and touched files (best-effort).
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "[agentic-refactor] collecting last $commits commits…" >&1
	git -C "$ROOT" log -n "$commits" --pretty=format:'%h %s' >"$COMMITS_SUMMARY" || true
	git -C "$ROOT" log -n "$commits" --name-only --pretty=format:'--- %H' \
		| awk '/^--- / { next } NF { print }' \
		| sort -u >"$COMMITS_FILES" || true
else
	echo "[agentic-refactor] not inside a git repository; skipping commit context" >&1
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
if [[ -x "$ROOT/development/loc.sh" ]]; then
	echo "[agentic-refactor] generating LOC snapshot via development/loc.sh" >&1
	"$ROOT/development/loc.sh" >"$LOC_LOG" 2>&1 || true
fi
if [[ -x "$ROOT/scripts/testing/phploc.sh" ]]; then
	echo "[agentic-refactor] generating phploc snapshot via scripts/testing/phploc.sh" >&1
	bash "$ROOT/scripts/testing/phploc.sh" >"$PHPLC_LOG" 2>&1 || true
fi

codex_args=(run --prompt-file "$HERE/prompts/refactor.txt" --outdir "$OUTDIR")
[[ -n "$exec_cmd" ]] && codex_args+=(--exec "$exec_cmd")
[[ -s "$COMMITS_SUMMARY" ]] && codex_args+=(--context "$COMMITS_SUMMARY")
[[ -s "$COMMITS_FILES" ]] && codex_args+=(--context "$COMMITS_FILES")
[[ -s "$CANDIDATES" ]] && codex_args+=(--context "$CANDIDATES")
[[ -s "$LOC_LOG" ]] && codex_args+=(--context "$LOC_LOG")
[[ -s "$PHPLC_LOG" ]] && codex_args+=(--context "$PHPLC_LOG")
[[ -f "$ROOT/AGENTS.${agent}.md" ]] && codex_args+=(--context "$ROOT/AGENTS.${agent}.md")
[[ -f "$ROOT/AGENTS.${agent}.local.md" ]] && codex_args+=(--context "$ROOT/AGENTS.${agent}.local.md")
[[ "$dry_run" == "1" ]] && codex_args+=(--dry-run)
[[ "$autocommit" == "1" ]] && codex_args+=(--autocommit)
[[ -n "$custom_prompt" ]] && codex_args+=(--prompt "$custom_prompt")

bash "$HERE/codex-run.sh" "${codex_args[@]}"

echo "[agentic-refactor] done" >&1
