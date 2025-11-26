#!/usr/bin/env bash
set -euo pipefail

# Verify tracked PHP files include a PHP open tag so plain-text files do not
# slip through CI undetected.

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
mapfile -d '' PHP_FILES < <(git -C "$ROOT_DIR" ls-files -z '*.php')

echo "[open-tag-lint] scanning ${#PHP_FILES[@]} PHP files" >&2

fail=0
for rel in "${PHP_FILES[@]}"; do
  file="$ROOT_DIR/$rel"

  if grep -q -E '<\?php|<\?=' "$file"; then
    continue
  fi

  # Allow empty placeholders; otherwise require a PHP open tag.
  if [[ ! -s "$file" ]]; then
    continue
  fi

  echo "[open-tag-lint] $rel: missing '<?php' or '<?=' tag" >&2
  fail=1
done

if (( fail )); then
  echo "[open-tag-lint] ERROR: PHP files must contain a PHP open tag" >&2
  exit 1
fi

echo "[open-tag-lint] OK" >&2
