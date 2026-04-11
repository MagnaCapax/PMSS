#!/usr/bin/env bash
set -euo pipefail
# phploc runner (advisory). Gathers aggregate PHP metrics quickly.
# Prefers vendor/bin/phploc, falls back to tools/phploc.phar, then 'phploc' in PATH.
# Excludes third-party/frozen trees so metrics reflect first-party code.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

PHPLC_BIN=()
if [[ -x "$ROOT_DIR/vendor/bin/phploc" ]]; then
  PHPLC_BIN=("$ROOT_DIR/vendor/bin/phploc")
elif [[ -f "$ROOT_DIR/tools/phploc.phar" ]]; then
  PHPLC_BIN=(php "$ROOT_DIR/tools/phploc.phar")
elif command -v phploc >/dev/null 2>&1; then
  PHPLC_BIN=(phploc)
fi

if [[ ${#PHPLC_BIN[@]} -eq 0 ]]; then
  echo "[phploc] phploc not found. Options:" >&2
  echo "  - composer require --dev phploc/phploc" >&2
  echo "  - or: mkdir -p tools && curl -L -o tools/phploc.phar https://phar.phpunit.de/phploc.phar" >&2
  exit 1
fi

echo "[phploc] using: ${PHPLC_BIN[*]}" >&2

# Exclusions to match LOC script intent
EXCLUDES=(
  --exclude vendor
  --exclude etc/skel/www
  --exclude var/www
  --exclude scripts/lib/devristo
)

# Run phploc over the whole repo root (fast) with our excludes
# phploc outputs a readable summary by default; we just print it through.
set -x
"${PHPLC_BIN[@]}" "${EXCLUDES[@]}" "$ROOT_DIR"
