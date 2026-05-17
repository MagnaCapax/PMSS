#!/usr/bin/env bash
set -euo pipefail

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
echo "[php-lint]" >&2
pmss_testing_find_php_files "$ROOT_DIR" \
  | xargs -0 -n1 php -l >/dev/null

echo "[customer-php-tree-isolation]" >&2
ROOT_DIR="$ROOT_DIR" bash "$ROOT_DIR/scripts/testing/customer-php-tree-isolation.sh"

echo "[dev-tests]" >&2
php "$ROOT_DIR/scripts/lib/tests/development/Runner.php"

echo "OK: PHP lint + customer-tree isolation + dev tests" >&2
