#!/usr/bin/env bash
set -euo pipefail

# Lints for PHP short open tags "<?" that are not "<?php" or "<?="
# Applies to all tracked *.php files (vendor excluded by git ls-files).

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
pmss_testing_load_tracked_php_scan "short-open-tag-lint"

fail=0
for rel in "${PHP_FILES[@]}"; do
	file="$ROOT_DIR/$rel"
	# Use PCRE to detect short tags that are not followed by php or =
	# Pattern: '<?' not followed by 'php' or '=' (case-insensitive for safety)
	if matches=$(rg -n -P "(?i)<\?(?!php|=|xml)" --color=never "$file" 2>/dev/null); then
		if [[ -n "$matches" && $fail -eq 0 ]]; then
			echo "[short-open-tag-lint] Found disallowed short open tags:" >&2
		fi
		if [[ -n "$matches" ]]; then
			while IFS= read -r match; do
				echo "$rel:$match" >&2
			done <<<"$matches"
			fail=1
		fi
	fi
done

pmss_testing_count_lint_finish "$fail" "[short-open-tag-lint] ERROR: Replace '<?' with '<?php' (short tags are disabled by default)." "[short-open-tag-lint] OK: no disallowed short open tags detected" >&2
