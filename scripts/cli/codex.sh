#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
source "$ROOT/scripts/cli/lib/codex-common.sh"

# Optional debug: PMSS_CODEX_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_CODEX_DEBUG "codex"

codex_set_error_trap "codex"

echo "[codex] start: assembling strict-rails context and invoking assistant" >&1

# codex.sh — Build a strict-rails PMSS prompt for Codex CLI (or compatible).
#
# Usage:
#   scripts/cli/codex.sh                      # build prompt and invoke codex
#   scripts/cli/codex.sh --prompt "..."       # override top-level goal/prompt
#   scripts/cli/codex.sh --exec 'codex'       # choose assistant executable (default: codex)
#
# Local prompt extension (optional, ignored by git):
#   - If $ROOT/.codex-prompt exists, it is appended to the prompt under "Local Operator Notes".

TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-codex-XXXXXXXX")"
PROMPT="$OUTDIR/prompt.txt"

custom_prompt=""
exec_cmd="codex"

while [[ $# -gt 0 ]]; do
	case "$1" in
	--prompt)
		custom_prompt=${2:-}
		shift 2 || true
		;;
	--exec)
		exec_cmd=${2:-}
		shift 2 || true
		;;
	-h | --help)
		sed -n '1,80p' "$0"
		exit 0
		;;
	*)
		echo "[codex] unknown option: $1" >&2
		exit 2
		;;
	esac
done

DEFAULT_PROMPT="$(cat "$ROOT/scripts/cli/prompts/codex.txt")"

prompt_text=${custom_prompt:-$DEFAULT_PROMPT}

{
	echo "$prompt_text"
	echo
	echo "Context to open (paths in this workspace):"
	echo " - AGENTS.md"
	echo " - AGENTS.local.md"
	echo " - docs/architecture.md"
	echo " - docs/update.md"
	echo " - docs/install.md"
	echo " - docs/refactoring.md"
	echo " - docs/contracts.md"
	echo " - docs/adr/"

	if [[ -f "$ROOT/.codex-prompt" ]]; then
		echo
		echo "Local Operator Notes (.codex-prompt):"
		cat "$ROOT/.codex-prompt"
	fi

	echo
	echo "Do not inline these; read them directly from disk."
} >"$PROMPT"

prompt_bytes=$(wc -c <"$PROMPT" | tr -d ' ')
prompt_lines=$(wc -l <"$PROMPT" | tr -d ' ')
echo "[codex] prompt written: $PROMPT (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

if ! command -v "$exec_cmd" >/dev/null 2>&1; then
	echo "[codex] assistant executable not found: $exec_cmd" >&2
	echo "[codex] run manually with codex installed, for example:" >&2
	echo "  codex \"\$(cat '$PROMPT')\"" >&2
	exit 127
fi

echo "[codex] invoking: $exec_cmd [prompt-string]" >&1
"$exec_cmd" "$(cat "$PROMPT")"
