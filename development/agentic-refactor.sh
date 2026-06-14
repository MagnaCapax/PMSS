#!/usr/bin/env bash
# shellcheck disable=SC2154
set -euo pipefail
set -o errtrace

# shellcheck disable=SC1091
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/codex-common.sh"
codex_agentic_bootstrap_self "PMSS_REFACTOR_CODEX_DEBUG" "agentic-refactor"

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

OUTDIR="$(codex_make_temp_workspace pmss-refactor-codex)"

CLAIMS_DIR="${PMSS_REFACTOR_CLAIMS_DIR:-/tmp/pmss-refactor-claims}"
declare -a PMSS_REFACTOR_CLAIMED=()
trap 'codex_scope_claim_release "$CLAIMS_DIR" "${PMSS_REFACTOR_CLAIMED[@]}"' EXIT
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
	if codex_parse_value_option_map "$1" "${2:-}" commits --commits target --target custom_prompt --prompt cooling_files --cooling-files; then
		shift "$CODEX_PARSE_SHIFT" || true
		continue
	fi
	case "$1" in
	--)
		shift || true
		if [[ $# -gt 0 ]]; then
			exec_extra_args+=("$@")
		fi
		break
		;;
	-h | --help)
		codex_usage_exit usage
		;;
	*)
		if [[ "$1" == --* ]]; then
			codex_cli_error_exit agentic-refactor "unknown option: $1" \
				"[agentic-refactor] hint: pass assistant CLI args after '--', e.g.:" \
				"  development/agentic-refactor.sh --agent=gemini -- --approval-mode yolo"
		else
			codex_cli_error_exit agentic-refactor "unknown option: $1"
		fi
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

# Architectural modes need whole-codebase candidates, not only recent churn.
# Feed the largest maintained runtime PHP files into the candidate set.
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

if [[ "$dry_run" != "1" && -s "$CANDIDATES" ]]; then
	PMSS_REFACTOR_ORIG_COUNT=0
	PMSS_REFACTOR_CLAIMED_COUNT=0
	codex_scope_claim_filter_candidates "$CLAIMS_DIR" "$CANDIDATES" PMSS_REFACTOR_CLAIMED PMSS_REFACTOR_CLAIMED_COUNT PMSS_REFACTOR_ORIG_COUNT
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
