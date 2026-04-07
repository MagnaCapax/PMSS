#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Compatibility shim for manual Codex sessions: keep this interactive even
# though the generic codex agent profile defaults to headless `codex exec`.
exec bash "$HERE/agentic.sh" --agent=codex --exec codex "$@"
