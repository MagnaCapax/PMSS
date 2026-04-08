#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
# shellcheck disable=SC1091
source "$HERE/lib/codex-common.sh"

codex_enable_debug PMSS_AGENTIC_DEBUG "agentic"
codex_set_error_trap "agentic"

ASSIST_DIR="$HERE/assistants"
default_agent="${PMSS_AGENTIC_DEFAULT_AGENT:-codex}"

agent=""
exec_cmd=""
verbose=0
declare -a passthrough=()
declare -a extra_context=()
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
	case "$1" in
	--agent | --agent=*)
		codex_parse_option_value agent "$1" "${2:-}" "--agent" 1
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--exec | --exec=*)
		codex_parse_option_value exec_cmd "$1" "${2:-}" "--exec" 1
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--verbose)
		verbose=1
		passthrough+=("$1")
		shift || true
		;;
	--yolo | -y)
		exec_extra_args+=("$1")
		shift || true
		;;
	--approval-mode)
		codex_append_option_pair exec_extra_args "$1" "${2:-}"
		shift 2 || true
		;;
	--ask-for-approval | -a)
		codex_append_option_pair exec_extra_args "$1" "${2:-}"
		shift 2 || true
		;;
	--allowed-tools)
		codex_append_option_pair exec_extra_args "$1" "${2:-}"
		shift 2 || true
		;;
	--permission-mode)
		codex_append_option_pair exec_extra_args "$1" "${2:-}"
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
		passthrough+=("$1")
		shift || true
		;;
	esac
done

agent="$(codex_default_agent "$agent" "$default_agent")"

exec_cmd="$(codex_resolve_exec_cmd "$ASSIST_DIR" "$agent" "$exec_cmd")" || exit $?

if [[ "${#exec_extra_args[@]}" -gt 0 ]]; then
	# Append extra assistant CLI args (shell-escaped) to the exec string.
	codex_append_exec_extra_args exec_cmd "$agent" "${exec_extra_args[@]}"
fi

if [[ -f "$ROOT/AGENTS.${agent}.md" ]]; then
	extra_context+=(--context "$ROOT/AGENTS.${agent}.md")
fi
if [[ -f "$ROOT/AGENTS.${agent}.local.md" ]]; then
	extra_context+=(--context "$ROOT/AGENTS.${agent}.local.md")
fi

if [[ "$verbose" == "1" ]]; then
	echo "[agentic] agent: $agent" >&1
fi

cmd=(bash "$HERE/codex-run.sh" run --prompt-file "$HERE/prompts/codex.txt")
[[ -n "$exec_cmd" ]] && cmd+=(--exec "$exec_cmd")
cmd+=("${extra_context[@]}")
cmd+=("${passthrough[@]}")
exec "${cmd[@]}"
