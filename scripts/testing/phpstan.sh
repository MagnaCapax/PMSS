#!/usr/bin/env bash
set -euo pipefail

# phpstan runner for PMSS. Honors:
# - PMSS_PHPSTAN_BIN: override path to phpstan (default: vendor/bin/phpstan or phpstan in PATH)
# - PHPSTAN_DISABLE_PARALLEL=1 to disable parallelization in constrained envs
# - ALLOW_TOOL_SKIP=1 to skip when phpstan is unavailable

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

PHAR_BIN="${PMSS_PHPSTAN_BIN:-}"
if [[ -z "$PHAR_BIN" ]]; then
  if [[ -x "$ROOT_DIR/vendor/bin/phpstan" ]]; then
    PHAR_BIN="$ROOT_DIR/vendor/bin/phpstan"
  elif command -v phpstan >/dev/null 2>&1; then
    PHAR_BIN="phpstan"
  else
    if [[ "${ALLOW_TOOL_SKIP:-0}" == "1" ]]; then
      echo "phpstan not found; skipping (ALLOW_TOOL_SKIP=1)" >&2
      exit 0
    fi
    echo "phpstan not found; install it or set ALLOW_TOOL_SKIP=1" >&2
    exit 127
  fi
fi

ARGS=( "analyse" "--no-progress" )
[[ "${PHPSTAN_DISABLE_PARALLEL:-0}" == "1" ]] && ARGS+=("--no-parallel")

if [[ -f "$ROOT_DIR/phpstan.neon.dist" ]]; then
  ARGS+=( "-c" "$ROOT_DIR/phpstan.neon.dist" )
fi

# Forward any extra arguments to allow CI/local overrides (e.g., --level=3)
exec "$PHAR_BIN" "${ARGS[@]}" "$@"
