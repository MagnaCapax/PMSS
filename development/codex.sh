#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Usage:
#   development/codex.sh
#   development/codex.sh --prompt "Do X"
#   development/codex.sh --exec 'codex --sandbox workspace-write --ask-for-approval untrusted'

# Compatibility shim for manual Codex sessions: keep this interactive even
# though the generic codex agent profile defaults to headless `codex exec`.
bash "$HERE/agentic.sh" --agent=codex --exec codex "$@"
