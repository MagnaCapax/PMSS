#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

exec bash "$HERE/agentic-refactor.sh" --agent=codex "$@"
