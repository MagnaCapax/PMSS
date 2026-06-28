#!/usr/bin/env bash
set -euo pipefail

# exec-bit-lint.sh — verify PHP executable bits match shebang usage
#
# Rules:
# - PHP files with a php shebang must be executable.
# - PHP files without a shebang must not be executable.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

fail=0

while IFS= read -r -d '' file; do
	first_line="$(head -n1 "$file")"
	if [[ "$first_line" == '#!'*php* && ! -x "$file" ]]; then
		echo "[exec-lint] missing exec bit for php shebang: $file  (fix: chmod +x $file)" >&2
		fail=1
	elif [[ "$first_line" != '#!'*php* && -x "$file" ]]; then
		echo "[exec-lint] unexpected exec bit (no php shebang): $file  (fix: chmod -x $file)" >&2
		fail=1
	fi
done < <(pmss_testing_find_first_party_php_files "$ROOT_DIR")

pmss_testing_count_lint_finish "$fail" "exec-bit-lint: $fail issue(s) found" "exec-bit-lint: OK"
