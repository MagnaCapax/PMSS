#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

bash "$HERE/agentic-refactor.sh" --agent=codex "$@"
