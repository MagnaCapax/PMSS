#!/usr/bin/env bash
set -euo pipefail

# php73-compat-scan.sh — static scan for common PHP 7.4+/8.0+ syntax in runtime code
# This complements php -l under PHP 7.3 by catching patterns proactively.

ROOT_DIR=$(cd "$(dirname "$0")/../.." && pwd)

cd "$ROOT_DIR"

echo "[php73-compat-scan]" >&2

paths=(
  scripts
)

# Build ripgrep or grep command
if command -v rg >/dev/null 2>&1; then
  IS_RG=1
  SEARCHER=(rg -n -H --hidden --no-ignore)
  EXCLUDES=(--glob '!vendor/**' --glob '!scripts/lib/tests/**' --glob '!scripts/testing/**' --glob '!etc/skel/www/**')
else
  IS_RG=0
  SEARCHER=(grep -RIn)
  EXCLUDES=(--exclude-dir=vendor --exclude-dir=scripts/lib/tests --exclude-dir=scripts/testing --exclude-dir=etc/skel/www)
fi

fail=0

scan() {
  local pattern="$1" label="$2"
  if [[ "$IS_RG" -eq 1 ]]; then
    out=$("${SEARCHER[@]}" ${EXCLUDES[@]} "$pattern" "${paths[@]}" || true)
  else
    out=$("${SEARCHER[@]}" ${EXCLUDES[@]} -E "$pattern" "${paths[@]}" || true)
  fi
  if [[ -n "${out:-}" ]]; then
    if [[ -n "$out" ]]; then
      echo "[FAIL] $label found:" >&2
      echo "$out" >&2
      fail=1
    else
      echo "[OK] $label not found" >&2
    fi
  fi
}

# Patterns to flag:
#  - Arrow functions (fn())
#  - Typed properties (visibility + type + $var)
#  - Nullsafe operator (?->)
#  - Null-coalescing assignment (??=)
#  - match() expression

scan "\\sfn\\s*\\(" "Arrow functions (PHP 7.4)"
scan "^\\s*(public|protected|private)\\s+[A-Za-z_\\\\?][A-Za-z0-9_\\\\|?]*\\s+\\\\\$[A-Za-z_]" "Typed properties (PHP 7.4)"
scan "\\?->" "Nullsafe operator (PHP 8.0)"
scan "\\?\\?=" "Null-coalescing assignment (PHP 7.4)"
scan "\\bmatch\\s*\\(" "match expression (PHP 8.0)"
scan ":\\s*(mixed|static)\\b" "Return type mixed/static (PHP 8.0)"
scan "function[^(]*\(([^)]*\|[^)]*)\)" "Union types in parameters (PHP 8.0)"

if [[ $fail -ne 0 ]]; then
  echo "php73-compat-scan: incompatible constructs detected" >&2
  exit 1
fi

echo "OK: php73-compat-scan" >&2
