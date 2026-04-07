#!/usr/bin/env bash
set -euo pipefail

echo "[ci] starting CI prompt assembly…" >&1

HERE="$(cd "$(dirname "$0")" && pwd)"
set +e
bash "$HERE/codex-ci.sh" "$@"
rc=$?
set -e
echo "[ci] codex-ci.sh exited with rc=$rc" >&1
exit $rc
