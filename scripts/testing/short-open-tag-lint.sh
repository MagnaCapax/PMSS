#!/usr/bin/env bash
set -euo pipefail

# Lints for PHP short open tags "<?" that are not "<?php" or "<?="
# Applies to all tracked *.php files (vendor excluded by git ls-files).

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"; ROOT_DIR="$(pmss_testing_root_dir)"
mapfile -d '' PHP_FILES < <(pmss_testing_list_tracked_php_files "$ROOT_DIR")

echo "[short-open-tag-lint] scanning ${#PHP_FILES[@]} PHP files" >&2

fail=0
for rel in "${PHP_FILES[@]}"; do
  file="$ROOT_DIR/$rel"
  # Use PCRE to detect short tags that are not followed by php or =
  # Pattern: '<?' not followed by 'php' or '=' (case-insensitive for safety)
  if rg -n -P "(?i)<\?(?!php|=|xml)" --color=never "$file" >/tmp/shorttag.$$ 2>/dev/null; then
    if [[ -s /tmp/shorttag.$$ ]]; then
      if (( fail == 0 )); then
        echo "[short-open-tag-lint] Found disallowed short open tags:" >&2
      fi
      cat /tmp/shorttag.$$ | sed "s|^|$rel:|" >&2
      fail=1
    fi
  fi
  rm -f /tmp/shorttag.$$ || true
done

if (( fail )); then
  echo "[short-open-tag-lint] ERROR: Replace '<?' with '<?php' (short tags are disabled by default)." >&2
  exit 1
fi

echo "[short-open-tag-lint] OK: no disallowed short open tags detected" >&2
