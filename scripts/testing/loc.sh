#!/usr/bin/env bash
set -euo pipefail

# LOC summary for PMSS that excludes third-party payloads so totals reflect only
# in-repo code we maintain.
ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
EXCLUDE_RELATIVE=(
	"etc/skel/www"
	"var/www"
	"scripts/lib/devristo"
)

declare -a TRACKED_FILES=()
mapfile -d '' TRACKED_FILES < <(git -C "$ROOT_DIR" ls-files -z)

declare -A CATEGORY_LABEL=(
	[scripts_php]="Scripts PHP"
	[scripts_bash]="Scripts Bash"
	[scripts_other]="Scripts other"
	[tests]="Tests"
	[top_level_bash]="Top-level Bash"
	[root_docs]="Root docs"
	[docs_adr]="Docs ADR"
	[docs_other]="Docs other"
	[automation]="Automation"
	[config_etc]="Config etc"
	[root_config]="Root config"
)
CATEGORY_ORDER=(
	scripts_php
	scripts_bash
	scripts_other
	tests
	top_level_bash
	root_docs
	docs_adr
	docs_other
	automation
	config_etc
	root_config
)

declare -A CATEGORY_LINES=()
for key in "${CATEGORY_ORDER[@]}"; do
	CATEGORY_LINES["$key"]=0
done

TOTAL_LINES=0
declare -a ACCOUNTED_FILES=()

# select_category maps a tracked file path to a reporting bucket so no file is
# double-counted and unhandled paths fall back to root_config.
select_category() {
	local path="$1"

	if [[ "$path" == scripts/* ]]; then
		if [[ "$path" == *.php ]]; then
			echo "scripts_php"
			return
		fi
		if [[ "$path" == *.sh ]]; then
			echo "scripts_bash"
			return
		fi
		echo "scripts_other"
		return
	fi

	if [[ "$path" == tests/* ]]; then
		echo "tests"
		return
	fi

	if [[ "$path" == docs/adr/* ]]; then
		echo "docs_adr"
		return
	fi
	if [[ "$path" == docs/* ]]; then
		echo "docs_other"
		return
	fi

	if [[ "$path" == .github/* ]]; then
		echo "automation"
		return
	fi

	if [[ "$path" == etc/* ]]; then
		echo "config_etc"
		return
	fi

	if [[ "$path" == *.sh && "$path" != */* ]]; then
		echo "top_level_bash"
		return
	fi
	if [[ "$path" == *.md && "$path" != */* ]]; then
		echo "root_docs"
		return
	fi

	echo "root_config"
}

for relative in "${TRACKED_FILES[@]}"; do
	skip=0
	for rel in "${EXCLUDE_RELATIVE[@]}"; do
		if [[ "$relative" == "$rel"* ]]; then
			skip=1
			break
		fi
	done
	if ((skip)); then
		continue
	fi

	file="$ROOT_DIR/$relative"
	if [[ ! -f "$file" ]]; then
		continue
	fi

	lines=$(wc -l <"$file")

	category=$(select_category "$relative")

	CATEGORY_LINES["$category"]=$((CATEGORY_LINES["$category"] + lines))
	TOTAL_LINES=$((TOTAL_LINES + lines))
	ACCOUNTED_FILES+=("$file")
done

TRACKED_TOTAL=0
if ((${#ACCOUNTED_FILES[@]})); then
	TRACKED_TOTAL=$(wc -l "${ACCOUNTED_FILES[@]}" | tail -n1 | awk '{print $1}')
fi

echo "PMSS lines of code (excluding third-party trees)"
echo "-------------------------------------------"
for key in "${CATEGORY_ORDER[@]}"; do
	printf "%-20s %7d\n" "${CATEGORY_LABEL[$key]}:" "${CATEGORY_LINES[$key]}"
done
echo "-------------------------------------------"
printf "%-20s %7d\n" "Accounted total:" "$TOTAL_LINES"
printf "%-20s %7d\n" "Tracked total:" "$TRACKED_TOTAL"
if ((TOTAL_LINES != TRACKED_TOTAL)); then
	printf "%-20s %7d\n" "Variance:" "$((TOTAL_LINES - TRACKED_TOTAL))"
fi
