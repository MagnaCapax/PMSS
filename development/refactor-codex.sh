#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_REFACTOR_CODEX_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_REFACTOR_CODEX_DEBUG "refactor-codex"
codex_set_error_trap "refactor-codex"

echo "[refactor-codex] start: assembling refactor context and invoking assistant" >&1

# refactor-codex.sh — Collect refactor candidate context (best-effort), then launch
# a coding assistant with the strict refactor prompt.
#
# Usage:
#   development/refactor-codex.sh
#   development/refactor-codex.sh --commits 25
#   development/refactor-codex.sh --target scripts/lib/update
#   development/refactor-codex.sh --prompt "Refactor X (behaviour-preserving)"
#   development/refactor-codex.sh --exec 'codex'
#   development/refactor-codex.sh --dry-run

TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-refactor-codex-XXXXXXXX")"
COMMITS_SUMMARY="$OUTDIR/commits-summary.txt"
COMMITS_FILES="$OUTDIR/commits-files.txt"
CANDIDATES="$OUTDIR/candidate-files.txt"
LOC_LOG="$OUTDIR/loc-snapshot.txt"
PHPLC_LOG="$OUTDIR/phploc-snapshot.txt"

commits=10
target=""
exec_cmd=""
custom_prompt=""
dry_run=0

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
	--dry-run)
		dry_run=1
		shift || true
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
if [[ -x "$ROOT/development/loc.sh" ]]; then
	echo "[refactor-codex] generating LOC snapshot via development/loc.sh" >&1
	"$ROOT/development/loc.sh" >"$LOC_LOG" 2>&1 || true
fi
if [[ -x "$ROOT/scripts/testing/phploc.sh" ]]; then
	echo "[refactor-codex] generating phploc snapshot via scripts/testing/phploc.sh" >&1
	bash "$ROOT/scripts/testing/phploc.sh" >"$PHPLC_LOG" 2>&1 || true
fi

codex_args=(run --prompt-file "$HERE/prompts/refactor.txt" --outdir "$OUTDIR")
[[ -s "$COMMITS_SUMMARY" ]] && codex_args+=(--context "$COMMITS_SUMMARY")
[[ -s "$COMMITS_FILES" ]] && codex_args+=(--context "$COMMITS_FILES")
[[ -s "$CANDIDATES" ]] && codex_args+=(--context "$CANDIDATES")
[[ -s "$LOC_LOG" ]] && codex_args+=(--context "$LOC_LOG")
[[ -s "$PHPLC_LOG" ]] && codex_args+=(--context "$PHPLC_LOG")
[[ "$dry_run" == "1" ]] && codex_args+=(--dry-run)
[[ -n "$custom_prompt" ]] && codex_args+=(--prompt "$custom_prompt")
[[ -n "$exec_cmd" ]] && codex_args+=(--exec "$exec_cmd")

bash "$HERE/codex-run.sh" "${codex_args[@]}"

echo "[refactor-codex] done" >&1
