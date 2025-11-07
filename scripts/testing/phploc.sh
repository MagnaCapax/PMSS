#!/usr/bin/env bash
set -euo pipefail

# phploc runner (advisory). Gathers aggregate PHP metrics quickly.
# Prefers vendor/bin/phploc, falls back to tools/phploc.phar, then 'phploc' in PATH.
# Excludes third-party/frozen trees so metrics reflect first-party code.

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$(cd "$(dirname "$0")" && pwd)"

PHPLC=""
if [[ -x "$ROOT_DIR/vendor/bin/phploc" ]]; then
  PHPLC="$ROOT_DIR/vendor/bin/phploc"
elif [[ -f "$ROOT_DIR/tools/phploc.phar" ]]; then
  PHPLC="php $ROOT_DIR/tools/phploc.phar"
elif command -v phploc >/dev/null 2>&1; then
  PHPLC="phploc"
fi

if [[ -z "$PHPLC" ]]; then
  echo "[phploc] phploc not found. Options:" >&2
  echo "  - composer require --dev phploc/phploc" >&2
  echo "  - or: mkdir -p tools && curl -L -o tools/phploc.phar https://phar.phpunit.de/phploc.phar" >&2
  exit 1
fi

echo "[phploc] using: $PHPLC" >&2

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
eval "$PHPLC" "${EXCLUDES[@]}" "$ROOT_DIR"

