#!/usr/bin/env bash
set -euo pipefail

# Syntax-only lint to catch parse errors under older PHP (e.g., 7.3 on Debian 10).
# Do not run dev tests here — they may rely on newer PHP features.

echo "[php-lint-compat] using $(php -r 'echo PHP_VERSION;')" >&2
# Lint all PHP files except dev/prod tests (which may target newer PHP).
# Use directory pruning to avoid traversing excluded trees entirely — this is
# more robust across shells/find variants and avoids corner cases with -path.
find . \
  -type d \( -path "./vendor" -o -path "./scripts/lib/tests" \) -prune -o \
  -type f -name "*.php" -print0 |
  xargs -0 -n1 php -l >/dev/null

echo "OK: PHP syntax check (compat)" >&2
