#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
# shellcheck disable=SC1091
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
#   development/agentic-refactor.sh --exec 'codex --sandbox workspace-write --ask-for-approval untrusted'
#   development/agentic-refactor.sh --agent codex
#   development/agentic-refactor.sh --dry-run

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
declare -a exec_extra_args=()
custom_prompt=""
dry_run=0
autocommit=0

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
	--yolo | -y)
		exec_extra_args+=("$1")
		shift || true
		;;
	--approval-mode)
		exec_extra_args+=("$1" "${2:-}")
		shift 2 || true
		;;
	--ask-for-approval | -a)
		exec_extra_args+=("$1" "${2:-}")
		shift 2 || true
		;;
	--allowed-tools)
		exec_extra_args+=("$1" "${2:-}")
		shift 2 || true
		;;
	--permission-mode)
		exec_extra_args+=("$1" "${2:-}")
		shift 2 || true
		;;
	--dangerously-skip-permissions)
		exec_extra_args+=("$1")
		shift || true
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

if [[ -z "$agent" ]]; then
	agent="$default_agent"
fi

exec_cmd="$(codex_resolve_exec_cmd "$ASSIST_DIR" "$agent" "$exec_cmd")" || exit $?

if [[ "${#exec_extra_args[@]}" -gt 0 ]]; then
	# Append extra assistant CLI args (shell-escaped) to the exec string.
	# This keeps agent selection stable while allowing per-run tuning like:
	#   ... -- --approval-mode yolo
	normalized_exec_extra_args=()
	while IFS= read -r line; do
		[[ -n "$line" ]] || continue
		normalized_exec_extra_args+=("$line")
	done < <(codex_normalize_exec_extra_args "$agent" "${exec_extra_args[@]}")
	for exec_extra_arg in "${normalized_exec_extra_args[@]}"; do
		printf -v exec_extra_q '%q' "$exec_extra_arg"
		exec_cmd+=" $exec_extra_q"
	done
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

# Ensure advisory complexity snapshots exist (best-effort).
if [[ "$dry_run" != "1" && -x "$ROOT/development/loc.sh" ]]; then
	echo "[agentic-refactor] generating LOC snapshot via development/loc.sh" >&1
	"$ROOT/development/loc.sh" >"$LOC_LOG" 2>&1 || true
fi
if [[ "$dry_run" != "1" && -x "$ROOT/scripts/testing/phploc.sh" ]]; then
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
