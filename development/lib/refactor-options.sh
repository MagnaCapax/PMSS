#!/usr/bin/env bash
# Refactor-specific CLI options and per-run artifact/claim paths.
# shellcheck disable=SC2034,SC2154

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
	*)
		if [[ "$1" == --* ]]; then
			codex_cli_help_or_error_exit "$1" agentic-refactor usage "unknown option: $1" \
				"[agentic-refactor] hint: pass assistant CLI args after '--', e.g.:" \
				"  development/agentic-refactor.sh --agent=gemini -- --approval-mode yolo"
		else
			codex_cli_help_or_error_exit "$1" agentic-refactor usage "unknown option: $1"
		fi
		;;
	esac
done

if ! [[ "$commits" =~ ^[0-9]+$ ]] || [[ "$commits" -le 0 ]]; then
	echo "[agentic-refactor] invalid --commits value: $commits" >&2
	exit 2
fi

codex_prepare_agent_exec_command "$ASSIST_DIR" "$default_agent" agent exec_cmd exec_extra_args || exit $?
