#!/usr/bin/env bash
set -euo pipefail

# Quick LOC summary for PMSS; exclude third-party payloads so totals reflect
# only code we maintain.
ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
EXCLUDE_RELATIVE=(
	"etc/skel/www"
	"var/www"
	"scripts/lib/devristo"
)

TOTAL_LINES=0
GIT_TOTAL=0

declare -a EXCLUDE_ABS=()
for rel in "${EXCLUDE_RELATIVE[@]}"; do
	EXCLUDE_ABS+=("$ROOT_DIR/$rel")
done

# count_find prints the summed line count for files matching the supplied find
# expression while pruning third-party directories. Totals roll up so we can
# track how much code each category owns.
count_find() {
	local desc="$1"
	shift
	local dir="$1"
	shift

	if [[ ! -d "$dir" ]]; then
		printf "%-20s %7d\n" "${desc}:" 0
		return
	fi

	local files=()
	local find_cmd=(find "$dir")
	for abs in "${EXCLUDE_ABS[@]}"; do
		if [[ -d "$abs" && "$abs" == "$dir"* ]]; then
			find_cmd+=(-path "$abs" -prune -o)
		fi
	done
	find_cmd+=('(' "$@" -print0 ')')

	mapfile -d '' files < <("${find_cmd[@]}" 2>/dev/null || true)

	local total=0
	if ((${#files[@]})); then
		total=$(wc -l "${files[@]}" | tail -n1 | awk '{print $1}')
	fi

	printf "%-20s %7d\n" "${desc}:" "$total"
	TOTAL_LINES=$((TOTAL_LINES + total))
}

# git_total captures repository-wide line totals using tracked files only,
# applying the same exclusion list as the category counts.
git_total() {
	local desc="$1"
	shift
	local files=()

	while IFS= read -r -d '' relative; do
		local skip=0
		for rel in "${EXCLUDE_RELATIVE[@]}"; do
			if [[ "$relative" == "$rel"* ]]; then
				skip=1
				break
			fi
		done
		if ((skip)); then
			continue
		fi
		if [[ -f "$ROOT_DIR/$relative" ]]; then
			files+=("$ROOT_DIR/$relative")
		fi
	done < <(git -C "$ROOT_DIR" ls-files -z)

	local total=0
	if ((${#files[@]})); then
		total=$(wc -l "${files[@]}" | tail -n1 | awk '{print $1}')
	fi

	GIT_TOTAL="$total"
	printf "%-20s %7d\n" "${desc}:" "$total"
}

echo "PMSS lines of code (excluding third-party trees)"
echo "-------------------------------------------"

count_find "Scripts PHP" "$ROOT_DIR/scripts" -type f -name '*.php'
count_find "Scripts Bash" "$ROOT_DIR/scripts" -type f -name '*.sh'
count_find "Scripts other" "$ROOT_DIR/scripts" -type f ! -name '*.php' ! -name '*.sh'
count_find "Tests" "$ROOT_DIR/tests" -type f
count_find "Top-level Bash" "$ROOT_DIR" -maxdepth 1 -type f -name '*.sh'
count_find "Root docs" "$ROOT_DIR" -maxdepth 1 -type f -name '*.md'
count_find "Docs ADR" "$ROOT_DIR/docs/adr" -type f -name '*.md'
count_find "Docs other" "$ROOT_DIR/docs" -type f -name '*.md' ! -path "$ROOT_DIR/docs/adr/*"
count_find "Automation" "$ROOT_DIR/.github" -type f
count_find "Config etc" "$ROOT_DIR/etc" -type f
count_find "Root config" "$ROOT_DIR" -maxdepth 1 -type f ! -name '*.md' ! -name '*.sh'

echo "-------------------------------------------"
printf "%-20s %7d\n" "Accounted total:" "$TOTAL_LINES"
git_total "Tracked total"
if ((GIT_TOTAL > TOTAL_LINES)); then
	printf "%-20s %7d\n" "Other tracked:" "$((GIT_TOTAL - TOTAL_LINES))"
fi
if ((TOTAL_LINES > GIT_TOTAL)); then
	printf "%-20s %7d\n" "Overcount:" "$((TOTAL_LINES - GIT_TOTAL))"
fi
