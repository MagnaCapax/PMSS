#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Usage:
#   development/codex.sh
#   development/codex.sh --prompt "Do X"
#   development/codex.sh --exec 'codex --sandbox workspace-write --ask-for-approval untrusted'

bash "$HERE/agentic.sh" --agent=codex "$@"
