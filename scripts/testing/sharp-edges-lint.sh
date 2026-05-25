#!/usr/bin/env bash
set -euo pipefail

# sharp-edges-lint.sh — flag raw destructive commands used without wrappers
#
# Intent:
# - Detect potentially destructive primitives used directly:
#   * rm -rf, mv, chmod -R, chown -R, chgrp -R
# - For PHP: allow when the command string is passed via runStep(...,
#   "<cmd>") — these are already captured by our logging/JSON wrappers.
# - For shell: flag direct uses; this is advisory in mixed repos but can be
#   made strict when enabled via PMSS_LINT_SHARP_STRICT=1.
#
# Exclusions:
# - tests/, vendor/, etc/skel/, scripts/lib/devristo/

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
STRICT="${PMSS_LINT_SHARP_STRICT:-1}"
PATTERN='rm[[:space:]]+-rf|chmod[[:space:]]+-R|chown[[:space:]]+-R|chgrp[[:space:]]+-R|\bmv[[:space:]]'
PHP_WRAPPER_REGEX='(runStep|runUserStep|runSoft|runFatal|runCommand|pmssUserLifecycleStep|run)\s*\('
VIOL=0
FATAL_VIOL=0

phpAllowlistedFile() {
	case "$1" in
	# Command arrays passed to wrappers; line-level scan cannot see wrapper context.
	"$ROOT_DIR/scripts/terminateUser.php") return 0 ;;
	"$ROOT_DIR/scripts/util/setupPermissions.php") return 0 ;;
	"$ROOT_DIR/scripts/util/configureOpenvpn.php") return 0 ;;
	"$ROOT_DIR/scripts/lib/update/apps/iprange.php") return 0 ;;

	# Legacy direct shell_exec/backticks or informational strings.
	"$ROOT_DIR/scripts/util/checkRutorrentPlugins.php") return 0 ;;
	"$ROOT_DIR/scripts/lib/update/apps/vnstat.php") return 0 ;;
	"$ROOT_DIR/scripts/lib/update/apps/rclone.php") return 0 ;;
	"$ROOT_DIR/scripts/recreateUser.php") return 0 ;;
	esac
	return 1
}

shellAllowlistedLine() {
	local file="$1"
	local text="$2"
	case "$file" in
	"$ROOT_DIR/install.sh")
		# Bootstrap temp cleanup and fstab atomic writes.
		# shellcheck disable=SC2016  # Reason: literal dollar-sign match; expansion intentionally avoided.
		if grep -Eq 'mv[[:space:]]+"\$tmpfile"[[:space:]]+(/etc/fstab|"\$file")' <<<"$text"; then
			return 0
		fi
		if grep -Eq 'rm[[:space:]]+-rf[[:space:]]+PMSS(\\*|[[:space:];]|$)' <<<"$text"; then
			return 0
		fi
		;;
	esac
	return 1
}

scanMatches() {
	local file="$1"
	grep -nE "$PATTERN" "$file" | cut -d: -f1-2 --output-delimiter=': ' || true
}

reportViolation() {
	local kind="$1" file="$2" raw="$3"
	echo "${kind} sharp edge: $file: ${raw}" >&2
	VIOL=$((VIOL + 1))
}

reportFatal() {
	local file="$1" raw="$2"
	echo "FATAL sharp edge: $file: ${raw}" >&2
	FATAL_VIOL=$((FATAL_VIOL + 1))
}

reportFatalMatches() {
	local file="$1" regex="$2" raw
	while IFS= read -r raw; do
		reportFatal "$file" "$raw"
	done < <(grep -nE "$regex" "$file" | cut -d: -f1-2 --output-delimiter=': ' || true)
}

phpScan() {
	local file raw line text
	while IFS= read -r -d '' file; do
		case "$file" in
		*/scripts/lib/tests/*) continue ;;
		esac
		if phpAllowlistedFile "$file"; then
			continue
		fi
		# grep candidate lines
		while IFS= read -r raw; do
			line="${raw%%:*}"
			text="${raw#*: }"
			if grep -Eq '^[[:space:]]*(//|#|/\*|\*)' <<<"$text"; then
				continue
			fi
			# ignore when inside runStep command string
			if grep -Eq "$PHP_WRAPPER_REGEX" <<<"$text"; then
				continue
			fi
			reportViolation "PHP" "$file" "${line}: ${text}"
		done < <(scanMatches "$file")
	done < <(pmss_testing_find_first_party_php_files "$ROOT_DIR")
}

shScan() {
	local file raw line text
	while IFS= read -r -d '' file; do
		case "$file" in
		*/development/* | */etc/skel/* | */scripts/testing/sharp-edges-lint.sh) continue ;;
		esac
		while IFS= read -r raw; do
			line="${raw%%:*}"
			text="${raw#*: }"
			if grep -Eq '^[[:space:]]*(#)' <<<"$text"; then
				continue
			fi
			if shellAllowlistedLine "$file" "$text"; then
				continue
			fi
			reportViolation "Shell" "$file" "${line}: ${text}"
		done < <(scanMatches "$file")
	done < <(pmss_testing_find_bash_files "$ROOT_DIR")
}

# Fatal patterns – always fail CI regardless of STRICT mode.
# These represent catastrophic primitives that must never appear:
# - rm -rf / (root) or rm -rf /home (full home tree)
# - rm -rf /home/$var (dynamic home targets without safeguards)
# - rm -rf $var (unquoted variable argument in shell/strings)
fatalScan() {
	local file regex
	local fatalRegexes=(
		# rm -rf / (root) — spaces or end-of-line after slash
		'rm[[:space:]]+-rf[[:space:]]+/([[:space:];]|$)'

		# rm -rf /home (full home tree)
		'rm[[:space:]]+-rf[[:space:]]+/home([[:space:];]|$)'

		# rm -rf /home/ (full home tree with trailing slash or wildcard)
		'rm[[:space:]]+-rf[[:space:]]+/home/([[:space:];]|$|\*)'

		# rm -rf "/home" or "/home/..." (quoted home path)
		'rm[[:space:]]+-rf[[:space:]]+\"/home'

		# rm -rf /home/$var (dynamic home path without explicit quoting helper)
		'rm[[:space:]]+-rf[[:space:]]+/home/\$[A-Za-z_][A-Za-z0-9_]*'

		# rm -rf $var (unquoted variable argument)
		'rm[[:space:]]+-rf[[:space:]]+\$[A-Za-z_][A-Za-z0-9_]*([[:space:];]|$)'

		# rm -rf $HOME or paths derived directly from $HOME
		'rm[[:space:]]+-rf[[:space:]]+[$]HOME([/[:space:];]|$)'

		# rm -rf ~ or ~/...
		'rm[[:space:]]+-rf[[:space:]]+~([/[:space:];]|$)'
	)
	while IFS= read -r -d '' file; do
		for regex in "${fatalRegexes[@]}"; do
			reportFatalMatches "$file" "$regex"
		done
	done < <(find "$ROOT_DIR" -type f \
		-not -path "*/.git/*" \
		-not -path "*/vendor/*" \
		-not -path "*/scripts/lib/tests/*" \
		-not -path "*/scripts/lib/devristo/*" \
		-not -path "*/etc/skel/*" \
		-not -path "*/docs/*" \
		-not -path "*/scripts/testing/sharp-edges-lint.sh" -print0)
}

phpScan
shScan
fatalScan

if [[ $FATAL_VIOL -gt 0 ]]; then
	echo "sharp-edges lint: $FATAL_VIOL fatal issue(s) found" >&2
	exit 1
fi

pmss_testing_count_lint_finish "$VIOL" "sharp-edges lint: $VIOL issue(s) found" "sharp-edges lint: OK (advisory)" "$STRICT"
