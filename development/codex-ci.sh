#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

bash "$HERE/agentic-ci.sh" --agent=codex "$@"
