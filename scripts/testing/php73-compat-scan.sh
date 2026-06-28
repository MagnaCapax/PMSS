#!/usr/bin/env bash
set -euo pipefail
# php73-compat-scan.sh — static scan for common PHP 7.4+/8.0+ syntax in runtime code
# This complements php -l under PHP 7.3 by catching patterns proactively.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

pmss_testing_cd_root_dir "$ROOT_DIR"

echo "[php73-compat-scan]" >&2

# Build ripgrep or grep command
if command -v rg >/dev/null 2>&1; then
	SEARCHER=(rg -n -H --hidden --no-ignore --glob '*.php' --glob '!vendor/**' --glob '!scripts/lib/tests/**')
else
	SEARCHER=(grep -RIn -E --exclude-dir=vendor --exclude-dir=tests --include='*.php')
fi

fail=0

scan() {
	local pattern="$1" label="$2" out
	out=$("${SEARCHER[@]}" "$pattern" scripts etc/skel/www || true)
	if [[ -n "$out" ]]; then
		echo "[FAIL] $label found:" >&2
		echo "$out" >&2
		fail=1
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
scan "(^|[[:space:](=,;?])match\\s*\\(" "match expression (PHP 8.0)"
scan ":\\s*(mixed|static)\\b" "Return type mixed/static (PHP 8.0)"
scan "function[^(]*\(([^)]*\|[^)]*)\)" "Union types in parameters (PHP 8.0)"

pmss_testing_count_lint_finish "$fail" "php73-compat-scan: incompatible constructs detected" "OK: php73-compat-scan" >&2
