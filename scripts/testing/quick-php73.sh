#!/usr/bin/env bash
set -euo pipefail

# quick-php73.sh — fast local PHP 7.3 compatibility smoke

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

echo "[quick-php73] syntax-only lint (use PHP 7.3 if available)" >&2
scripts/testing/php-lint-compat.sh || { echo "[quick-php73] php-lint-compat failed" >&2; exit 1; }

echo "[quick-php73] static scan for 7.4/8.0 syntax" >&2
scripts/testing/php73-compat-scan.sh || { echo "[quick-php73] php73-compat-scan failed" >&2; exit 1; }

echo "[quick-php73] OK" >&2

