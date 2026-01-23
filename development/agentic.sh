#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
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

usage() {
	cat <<EOF
Usage:
  development/agentic.sh [--agent NAME] [--exec CMD] [--verbose] [--help] [-- <codex-run args>]

Options:
  --agent NAME     Select assistant profile (default: ${default_agent})
  --exec CMD       Override the profile command line
  --verbose        Print selected agent
  -h, --help       Show this help

Pass-through to codex-run:
  --prompt-file, --prompt, --context, --dry-run, --autocommit, --outdir

Available agents with profiles:
EOF
	agentic_list_agents | sed 's/^/  - /'
	cat <<'EOF'

Examples:
  development/agentic.sh --agent=codex --dry-run
  development/agentic.sh --agent=claude --prompt "Summarize changes"
  development/agentic.sh --exec "codex" --dry-run
EOF
}

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
	--exec)
		exec_cmd=${2:-}
		shift 2 || true
		;;
	--exec=*)
		exec_cmd=${1#--exec=}
		shift || true
		;;
	--verbose)
		verbose=1
		passthrough+=("$1")
		shift || true
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
