#!/usr/bin/env bash
set -euo pipefail

# fix-exec-bits.sh — set executable bits on known CLI scripts

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

targets=(
  scripts/cli/*.sh
  scripts/testing/*.sh
)

for glob in "${targets[@]}"; do
  for f in $glob; do
    [[ -f "$f" ]] || continue
    chmod +x "$f" || true
  done
done

echo "[fix-exec-bits] set +x on CLI/test scripts" >&2

