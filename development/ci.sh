#!/usr/bin/env bash
set -euo pipefail

echo "[ci] starting CI prompt assembly…" >&1

# ci.sh — assemble a CI-focused prompt and optionally invoke the assistant.
# Usage: development/ci.sh [--job NAME] [--prompt TEXT] [--exec CMD] [--dry-run]

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
cd "$ROOT"
# shellcheck disable=SC1091
source "$HERE/lib/codex-common.sh"

job=""
exec_cmd=""
custom_prompt=""
dry_run=0

while [[ $# -gt 0 ]]; do
	case "$1" in
	--job)
		codex_parse_option_value job "$1" "${2:-}" "--job"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--exec)
		codex_parse_option_value exec_cmd "$1" "${2:-}" "--exec"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--prompt)
		codex_parse_option_value custom_prompt "$1" "${2:-}" "--prompt"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--dry-run)
		dry_run=1
		shift || true
		;;
	-h | --help)
		sed -n '1,40p' "$0"
		exit 0
		;;
	*)
		echo "[ci] unknown option: $1" >&2
		exit 2
		;;
	esac
done

args=()
[[ -n "$job" ]] && args+=(--job "$job")
[[ -n "$custom_prompt" ]] && args+=(--prompt "$custom_prompt")
[[ -n "$exec_cmd" ]] && args+=(--exec "$exec_cmd")
[[ "$dry_run" == "1" ]] && args+=(--dry-run)

set +e
bash "$HERE/codex-ci.sh" "${args[@]}"
rc=$?
set -e
echo "[ci] codex-ci.sh exited with rc=$rc" >&1
exit $rc
