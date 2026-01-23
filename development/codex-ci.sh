#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Usage:
#   development/codex-ci.sh [agentic-ci options]

bash "$HERE/agentic-ci.sh" --agent=codex "$@"
