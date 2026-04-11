#!/usr/bin/env bash
set -euo pipefail
# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
CONFIG_PATH="$ROOT_DIR/phpstan.update.neon.dist"

if [[ ! -f "$CONFIG_PATH" ]]; then
  echo "phpstan-update advisory: missing config $CONFIG_PATH" >&2
  exit 1
fi

set +e
ALLOW_TOOL_SKIP="${ALLOW_TOOL_SKIP:-1}" PHPSTAN_DISABLE_PARALLEL=1 \
  bash "$ROOT_DIR/scripts/testing/phpstan.sh" -c "$CONFIG_PATH" scripts/lib/update
rc=$?
set -e

if [[ "$rc" -eq 0 ]]; then
  echo "phpstan-update advisory: OK"
  exit 0
fi

if [[ "$rc" -eq 127 ]]; then
  echo "phpstan-update advisory: skipped (phpstan unavailable)"
  exit 0
fi

echo "phpstan-update advisory: findings detected (non-blocking)"
exit 0
