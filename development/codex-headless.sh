#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

# Usage:
#   development/codex-headless.sh
#   development/codex-headless.sh --prompt "Do X"
#   development/codex-headless.sh --autocommit

# Compatibility shim for the headless Codex agentic path.
bash "$HERE/agentic.sh" --agent=codex "$@"
