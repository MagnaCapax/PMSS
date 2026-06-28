#!/usr/bin/env bash
set -euo pipefail

# Verify tracked PHP files include a PHP open tag so plain-text files do not
# slip through CI undetected.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
pmss_testing_load_tracked_php_scan "open-tag-lint"

fail=0
for rel in "${PHP_FILES[@]}"; do
	# Allow empty placeholders; otherwise require a PHP open tag.
	if [[ -s "$ROOT_DIR/$rel" ]] && ! grep -q -E '<\?php|<\?=' "$ROOT_DIR/$rel"; then
		echo "[open-tag-lint] $rel: missing '<?php' or '<?=' tag" >&2
		fail=1
	fi
done

pmss_testing_count_lint_finish "$fail" "[open-tag-lint] ERROR: PHP files must contain a PHP open tag" "[open-tag-lint] OK" >&2
