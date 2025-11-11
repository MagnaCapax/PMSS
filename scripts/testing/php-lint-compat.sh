#!/usr/bin/env bash
set -euo pipefail

# Syntax-only lint to catch parse errors under older PHP (e.g., 7.3 on Debian 10).
# Do not run dev tests here — they may rely on newer PHP features.

echo "[php-lint-compat] using $(php -r 'echo PHP_VERSION;')" >&2
# Lint all PHP files except dev/prod tests (which may target newer PHP)
# and the frozen skel WWW tree (treated as read-only / externally managed).
find . -type f -name "*.php" \
  -not -path "./vendor/*" \
  -not -path "./scripts/lib/tests/*" \
  -not -path "./etc/skel/www/*" \
  -print0 | xargs -0 -n1 php -l >/dev/null

echo "OK: PHP syntax check (compat)" >&2
