#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Usage:
#   development/codex-refactor.sh [agentic-refactor options]

bash "$HERE/agentic-refactor.sh" --agent=codex "$@"
