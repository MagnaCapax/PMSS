#!/usr/bin/env bash
# shellcheck disable=SC2154
set -euo pipefail
set -o errtrace

# shellcheck disable=SC1091
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/codex-common.sh"
codex_agentic_bootstrap_self "PMSS_AGENTIC_DEBUG" "agentic"

agent=""
exec_cmd=""
verbose=0
declare -a passthrough=()
declare -a exec_extra_args=()

usage() {
	cat <<EOF
Usage:
  development/agentic.sh [--agent NAME] [--exec CMD] [--verbose] [--help] [-- <assistant args>]

Purpose:
  Run codex-run with the standard prompt and selected assistant.

Options:
  --agent NAME     Select assistant profile (default: ${default_agent})
  --exec CMD       Override the profile command line
  --verbose        Print selected agent
  -h, --help       Show this help

Pass-through to codex-run:
  --prompt-file PATH  Use a prompt file instead of the default
  --prompt TEXT       Use custom prompt text instead of a file
  --context PATH      Append extra context (repeatable)
  --outdir DIR        Write prompt + artifacts to DIR (default: temp dir)
  --dry-run           Build prompt and show the assistant command only
  --autocommit        Enable autocommit rules in the prompt (operator-approved)

Assistant CLI args (appended to the exec command):
  --yolo, -y                     Convenience flag (maps to claude danger)
  --approval-mode MODE           Assistant-specific approval mode
  --ask-for-approval POLICY      Codex approval policy (untrusted/on-failure/on-request/never)
  --allowed-tools LIST           Assistant-specific allowed tools list
  --permission-mode MODE         Assistant-specific permission mode
  --dangerously-skip-permissions Pass through as-is

Available agents with profiles:
EOF
	codex_list_agents "$ASSIST_DIR" | sed 's/^/  - /'
	cat <<'EOF'

Environment:
  PMSS_AGENTIC_DEFAULT_AGENT  Default agent when --agent is omitted
  PMSS_AGENTIC_DEBUG=1        Enable bash -x tracing

Examples:
  development/agentic.sh --agent=codex --dry-run
  development/agentic.sh --agent=claude --prompt "Summarize changes"
  development/agentic.sh --exec "codex" --dry-run
  development/agentic.sh --agent=gemini -- --approval-mode yolo
  development/agentic.sh --agent=gemini --approval-mode yolo
EOF
}

while [[ $# -gt 0 ]]; do
	if codex_parse_launcher_option agent exec_cmd '' '' "$1" "${2:-}" 1 exec_extra_args; then
		shift "$CODEX_PARSE_SHIFT" || true
		continue
	fi
	case "$1" in
	--verbose)
		verbose=1
		passthrough+=("$1")
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
		codex_cli_help_or_error_exit "$1" agentic usage "unknown option: $1"
		;;
	*)
		passthrough+=("$1")
		shift || true
		;;
	esac
done

codex_prepare_agent_exec_command "$ASSIST_DIR" "$default_agent" agent exec_cmd exec_extra_args || exit $?

if [[ "$verbose" == "1" ]]; then
	echo "[agentic] agent: $agent" >&1
fi

cmd=(bash "$HERE/codex-run.sh" run --prompt-file "$HERE/prompts/codex.txt")
codex_append_runner_args cmd "$ROOT" "$agent" "$exec_cmd" 0 0 '' 1
cmd+=("${passthrough[@]}")
exec "${cmd[@]}"
