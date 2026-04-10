#!/usr/bin/env bash
set -euo pipefail

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"; ROOT_DIR="$(pmss_testing_root_dir)"

echo "[bash-syntax]" >&2

pmss_testing_find_bash_files "$ROOT_DIR" | xargs -0 -I{} bash -n {}

if command -v shellcheck >/dev/null 2>&1; then
  echo "[shellcheck]" >&2
  # Exclude vendor and frozen skel/www content per AGENTS.md
  pmss_testing_find_bash_files "$ROOT_DIR" | xargs -0 shellcheck || true
else
  echo "(shellcheck not installed; skipping)" >&2
fi

if command -v shfmt >/dev/null 2>&1; then
  echo "[shfmt check]" >&2
  # Check only filtered files, not entire tree
  pmss_testing_find_bash_files "$ROOT_DIR" | xargs -0 shfmt -d || true
else
  echo "(shfmt not installed; skipping)" >&2
fi

echo "OK: bash checks"
