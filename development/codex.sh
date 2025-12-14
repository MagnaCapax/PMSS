#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Usage:
#   development/codex.sh
#   development/codex.sh --prompt "Do X"
#   development/codex.sh --exec codex

bash "$HERE/codex-run.sh" run --prompt-file "$HERE/prompts/codex.txt" "$@"
