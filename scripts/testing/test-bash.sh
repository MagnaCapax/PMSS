#!/usr/bin/env bash
set -euo pipefail

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

echo "[bash-syntax]" >&2

pmss_testing_run_bash_file_tool "$ROOT_DIR" -n1 bash -n

if command -v shellcheck >/dev/null 2>&1; then
	echo "[shellcheck]" >&2
	# Exclude vendor and frozen skel/www content per AGENTS.md
	pmss_testing_run_bash_file_tool "$ROOT_DIR" shellcheck || true
else
	echo "(shellcheck not installed; skipping)" >&2
fi

echo "[bash trailing test return lint]" >&2
"$ROOT_DIR/scripts/testing/check-bash-trailing-test-return.sh"

echo "[timeout audit]" >&2
"$ROOT_DIR/scripts/testing/test-timeout-audit.sh"

if command -v shfmt >/dev/null 2>&1; then
	echo "[shfmt check]" >&2
	# Check only filtered files, not entire tree
	pmss_testing_run_bash_file_tool "$ROOT_DIR" shfmt -d || true
else
	echo "(shfmt not installed; skipping)" >&2
fi

echo "OK: bash checks"
