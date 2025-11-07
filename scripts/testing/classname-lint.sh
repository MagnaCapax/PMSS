#!/usr/bin/env bash
set -euo pipefail

# classname-lint.sh — advisory class/file name consistency check
# Rules:
# - For tests: each *Test.php must declare a class whose name matches the filename (sans .php)
# - For first-party libs: when a PHP file declares a class, ensure one of the declared class names matches the basename
# Exclusions: vendor/, etc/skel/, scripts/lib/devristo/

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
VIOL=0

check_tests() {
  local file base class
  while IFS= read -r -d '' file; do
    base="$(basename "$file" .php)"
    class=$(grep -Eo '^(final[[:space:]]+)?class[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' "$file" | awk '{print $NF}' | head -n1)
    if [[ -z "$class" || "$class" != "$base" ]]; then
      echo "class/file mismatch (tests): $file -> $class (expected $base)" >&2
      VIOL=$((VIOL+1))
    fi
  done < <(find "$ROOT_DIR/scripts/lib/tests" -type f -name "*Test.php" -print0)
}

check_libs() {
  local file base classes match=0
  while IFS= read -r -d '' file; do
    base="$(basename "$file" .php)"
    mapfile -t classes < <(grep -Eo '^(final[[:space:]]+)?class[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' "$file" | awk '{print $NF}')
    if [[ ${#classes[@]} -gt 0 ]]; then
      match=0
      for c in "${classes[@]}"; do
        if [[ "$c" == "$base" ]]; then match=1; break; fi
      done
      if [[ $match -eq 0 ]]; then
        echo "class/file mismatch: $file -> [${classes[*]:-none}] (expected $base)" >&2
        VIOL=$((VIOL+1))
      fi
    fi
  done < <(find "$ROOT_DIR/scripts/lib" -type f -name "*.php" \
           -not -path "*/tests/*" -not -path "*/devristo/*" -print0)
}

check_tests
check_libs

if [[ $VIOL -gt 0 ]]; then
  echo "classname lint: $VIOL mismatch(es)" >&2
  exit 1
fi
echo "classname lint: OK"

