#!/usr/bin/env bash
set -euo pipefail

echo "[ci] starting CI prompt assembly…" >&1

# ci.sh — one-command helper
#
# Defaults:
#  - Downloads artifacts from the latest CI run to a temp workspace
#  - Includes the 'build' job logs (if present)
#  - Assembles a QC/QA-focused prompt honoring repository rails
#  - If --exec is provided, pipes to your assistant CLI (e.g., Codex)
#
# Usage:
#  development/ci.sh                 # build prompt; prints location
#  development/ci.sh --exec 'codex --sandbox workspace-write --ask-for-approval untrusted'
#  development/ci.sh --job smoke     # include smoke logs instead of build
#  development/ci.sh --prompt "..."   # custom prompt
#  development/ci.sh --dry-run        # assemble prompt only; do not invoke assistant

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
cd "$ROOT"

job=""
exec_cmd=""
custom_prompt=""
dry_run=0

while [[ $# -gt 0 ]]; do
	case "$1" in
	--job)
		job=${2:-}
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
		sed -n '1,80p' "$0"
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
