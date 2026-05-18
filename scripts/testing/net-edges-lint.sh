#!/usr/bin/env bash
set -euo pipefail

# net-edges-lint.sh — advisory check for raw network calls without wrappers
# Flags: curl, wget (and basic sockets: nc, telnet) used directly.
# Exclusions: vendor/, tests/, etc/skel/, scripts/lib/devristo/
# For PHP: allow when used inside runStep(..., "curl ...") etc.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
STRICT="${PMSS_LINT_NET_STRICT:-0}"
PATTERN='curl\b|wget\b|\bnc\b|telnet\b'
VIOL=0

scanMatches() {
	local file="$1"
	grep -nE "$PATTERN" "$file" | cut -d: -f1-2 --output-delimiter=': ' || true
}

phpScan() {
	local file raw
	while IFS= read -r -d '' file; do
		case "$file" in
		*/scripts/lib/tests/*) continue ;;
		esac
		while IFS= read -r raw; do
			# Permit when inside runStep command string
			if grep -Eq "runStep\s*\(.*(curl|wget|nc|telnet).*\)" <<<"$raw"; then
				continue
			fi
			echo "PHP net edge: $file: ${raw}" >&2
			VIOL=$((VIOL + 1))
		done < <(scanMatches "$file")
	done < <(pmss_testing_find_first_party_php_files "$ROOT_DIR")
}

shScan() {
	local file raw
	while IFS= read -r -d '' file; do
		case "$file" in
		*/etc/skel/* | */scripts/testing/net-edges-lint.sh) continue ;;
		esac
		while IFS= read -r raw; do
			echo "Shell net edge: $file: ${raw}" >&2
			VIOL=$((VIOL + 1))
		done < <(scanMatches "$file")
	done < <(pmss_testing_find_bash_files "$ROOT_DIR")
}

phpScan
shScan

pmss_testing_count_lint_finish "$VIOL" "net-edges lint: $VIOL issue(s) found" "net-edges lint: OK (advisory)" "$STRICT"
