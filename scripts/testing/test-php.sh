#!/usr/bin/env bash
set -euo pipefail

echo "[php-lint]" >&2
find . -type f -name "*.php" -not -path "./vendor/*" -print0 \
  | xargs -0 -n1 php -l >/dev/null

echo "[dev-tests]" >&2
php scripts/lib/tests/development/Runner.php

echo "OK: PHP lint + dev tests" >&2
