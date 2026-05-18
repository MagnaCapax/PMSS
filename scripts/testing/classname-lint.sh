#!/usr/bin/env bash
set -euo pipefail
# classname-lint.sh — advisory class/file name consistency check
# Rules:
# - For tests: each *Test.php must declare a class whose name matches the filename (sans .php), case-insensitively.
#   Integration tests are script-style and excluded.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
VIOL=0

check_tests() {
	local file base base_lc class_lc match=0
	while IFS= read -r -d '' file; do
		base="$(basename "$file" .php)"
		base_lc="$(printf '%s' "$base" | tr '[:upper:]' '[:lower:]')"
		mapfile -t classes < <(grep -Eo '^[[:space:]]*(abstract[[:space:]]+|final[[:space:]]+)?class[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' "$file" | awk '{print $NF}' || true)
		match=0
		for c in "${classes[@]}"; do
			class_lc="$(printf '%s' "$c" | tr '[:upper:]' '[:lower:]')"
			if [[ "$class_lc" == "$base_lc" ]]; then
				match=1
				break
			fi
		done
		if [[ $match -eq 0 ]]; then
			echo "class/file mismatch (tests): $file -> [${classes[*]:-none}] (expected $base)" >&2
			VIOL=$((VIOL + 1))
		fi
	done < <(find "$ROOT_DIR/scripts/lib/tests/development" "$ROOT_DIR/scripts/lib/tests/production" -type f -name "*Test.php" -print0)
}

check_tests

pmss_testing_count_lint_finish "$VIOL" "classname lint: $VIOL mismatch(es)" "classname lint: OK"
