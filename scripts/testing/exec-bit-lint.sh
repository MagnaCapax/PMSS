#!/usr/bin/env bash
set -euo pipefail

# exec-bit-lint.sh — verify required scripts are executable
#
# Default scope focuses on commonly executed CLIs. Expand as needed.

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

fail=0

must_exec=(
  scripts/cli/ci.sh
  scripts/cli/ci-logs.sh
  scripts/cli/ci-codex.sh
  scripts/cli/adr-list.sh
)

# Recursively find .php files in util and cron that should be executable
while IFS= read -r -d '' file; do
  must_exec+=("$file")
done < <(find scripts/util scripts/cron -type f -name "*.php" -print0)

for f in "${must_exec[@]}"; do
  if [[ ! -f "$f" ]]; then
    echo "[exec-lint] missing: $f" >&2; fail=1; continue
  fi
  if [[ ! -x "$f" ]]; then
    echo "[exec-lint] not executable: $f  (fix: chmod +x $f)" >&2
    fail=1
  fi
done

if [[ $fail -ne 0 ]]; then
  echo "exec-bit-lint: $fail issue(s) found" >&2
  exit 1
fi
echo "exec-bit-lint: OK"

