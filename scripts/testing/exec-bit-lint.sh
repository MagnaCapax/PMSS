#!/usr/bin/env bash
set -euo pipefail

# exec-bit-lint.sh — verify PHP executable bits match shebang usage
#
# Rules:
# - PHP files with a php shebang must be executable.
# - PHP files without a shebang must not be executable.

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

fail=0

while IFS= read -r -d '' file; do
  first_line="$(head -n1 "$file")"
  if [[ "$first_line" == '#!'*php* ]]; then
    if [[ ! -x "$file" ]]; then
      echo "[exec-lint] missing exec bit for php shebang: $file  (fix: chmod +x $file)" >&2
      fail=1
    fi
  else
    if [[ -x "$file" ]]; then
      echo "[exec-lint] unexpected exec bit (no php shebang): $file  (fix: chmod -x $file)" >&2
      fail=1
    fi
  fi
done < <(find "$ROOT_DIR" -type f -name "*.php" \
  -not -path "*/.git/*" \
  -not -path "*/vendor/*" \
  -not -path "*/etc/skel/*" \
  -not -path "*/scripts/lib/devristo/*" -print0)

if [[ $fail -ne 0 ]]; then
  echo "exec-bit-lint: $fail issue(s) found" >&2
  exit 1
fi
echo "exec-bit-lint: OK"
