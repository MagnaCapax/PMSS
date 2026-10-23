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
	local bin
	while IFS= read -r -d '' bin; do
		if command -v rg >/dev/null 2>&1; then
			out+=$(rg -n -H --no-ignore "$pattern" "$bin" || true)
		else
			out+=$(grep -nHE "$pattern" "$bin" || true)
		fi
	done < <(find etc/skel/bin -maxdepth 1 -type f \( -name '*.php' -o -exec grep -qE '^#!/usr/bin/(env )?php$' {} \; \) -print0)
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
#  - proc_open 'redirect'/'null' descriptors (PHP 7.4; php -l cannot see them)

scan "\\sfn\\s*\\(" "Arrow functions (PHP 7.4)"
scan "^\\s*(public|protected|private)\\s+[A-Za-z_\\\\?][A-Za-z0-9_\\\\|?]*\\s+\\\\\$[A-Za-z_]" "Typed properties (PHP 7.4)"
scan "\\?->" "Nullsafe operator (PHP 8.0)"
scan "\\?\\?=" "Null-coalescing assignment (PHP 7.4)"
scan "(^|[[:space:](=,;?])match\\s*\\(" "match expression (PHP 8.0)"
scan ":\\s*(mixed|static)\\b" "Return type mixed/static (PHP 8.0)"
scan "function[^(]*\(([^)]*\|[^)]*)\)" "Union types in parameters (PHP 8.0)"
q="['\"]"
scan "((^|[^]_)[:alnum:]])\\[|array[[:space:]]*\\()[[:space:]]*${q}redirect${q}[[:space:]]*,[[:space:]]*[0-9]" "proc_open redirect descriptor (PHP 7.4)"
scan "((^|[^]_)[:alnum:]])\\[|array[[:space:]]*\\()[[:space:]]*${q}null${q}[[:space:]]*[])]" "proc_open null descriptor (PHP 7.4)"

pmss_testing_count_lint_finish "$fail" "php73-compat-scan: incompatible constructs detected" "OK: php73-compat-scan" >&2
