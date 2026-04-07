#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Compatibility shim for the headless Codex agentic path.
exec bash "$HERE/agentic.sh" --agent=codex "$@"
