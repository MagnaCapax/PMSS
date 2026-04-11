#!/usr/bin/env bash
set -euo pipefail

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
echo "[php-lint]" >&2
pmss_testing_find_php_files "$ROOT_DIR" \
  | xargs -0 -n1 php -l >/dev/null

echo "[dev-tests]" >&2
php "$ROOT_DIR/scripts/lib/tests/development/Runner.php"

echo "OK: PHP lint + dev tests" >&2
