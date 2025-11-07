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

# Advisory: simple cyclomatic-like complexity for Bash scripts
# This is a crude proxy (sum of control-flow tokens) for trend/compare, not absolutes.
echo
echo "Advisory complexity (Bash only)"
echo "-------------------------------"

declare -a BASH_FILES=()
for rel in "${TRACKED_FILES[@]}"; do
  # Exclude third-party trees and non-.sh files
  skip=0
  for ex in "${EXCLUDE_RELATIVE[@]}"; do
    if [[ "$rel" == "$ex"* ]]; then skip=1; break; fi
  done
  [[ $skip -eq 1 ]] && continue
  [[ "$rel" == *.sh ]] || continue
  BASH_FILES+=("$rel")
done

total_complex=0
declare -a COMPLEX_ROWS=()
for rel in "${BASH_FILES[@]}"; do
  file="$ROOT_DIR/$rel"
  # Basic counts
  total=$(wc -l <"$file")
  blank=$(grep -c -E '^\s*$' "$file" || true)
  comments=$(grep -c -E '^\s*#' "$file" || true)
  code=$(( total - blank - comments ))
  # Control-flow tokens (very rough)
  set +o pipefail
  ifc=$(grep -o -E '(^|;|\s)if\b' "$file" | wc -l | tr -d ' ' || true)
  elifc=$(grep -o -E '\belif\b' "$file" | wc -l | tr -d ' ' || true)
  forc=$(grep -o -E '(^|;|\s)for\b' "$file" | wc -l | tr -d ' ' || true)
  whilec=$(grep -o -E '(^|;|\s)while\b' "$file" | wc -l | tr -d ' ' || true)
  untilc=$(grep -o -E '(^|;|\s)until\b' "$file" | wc -l | tr -d ' ' || true)
  casec=$(grep -o -E '(^|;|\s)case\b' "$file" | wc -l | tr -d ' ' || true)
  andor=$(grep -o -E '&&|\|\|' "$file" | wc -l | tr -d ' ' || true)
  funcc=$(grep -o -E '^[a-zA-Z_][a-zA-Z0-9_]*\s*\(\)\s*\{' "$file" | wc -l | tr -d ' ' || true)
  set -o pipefail
  complex=$(( ifc + elifc + forc + whilec + untilc + casec + andor + funcc ))
  total_complex=$(( total_complex + complex ))
  density=0
  if (( code > 0 )); then
    # scaled by 100 code LOC
    density=$(( (complex * 100 + code/2) / code ))
  fi
  COMPLEX_ROWS+=("$rel|code=$code|complex=$complex|density=$density")
done

echo "Bash files analyzed: ${#BASH_FILES[@]}"
echo "Aggregate complexity: $total_complex"

# Top 8 by density (complexity per 100 code LOC)
printf "%s\n" "${COMPLEX_ROWS[@]}" | \
  awk -F'[|]' '{
    # parse fields like code=### etc.
    code=$2; sub(/code=/, "", code);
    complex=$3; sub(/complex=/, "", complex);
    density=$4; sub(/density=/, "", density);
    printf "%05d %s %s %s %s\n", density, $1, $2, $3, $4;
  }' | sort -r | head -n 8 | \
  awk '{printf "%-60s %s %s %s/100loc\n", $2, $3, $4, $5}' || true

# Advisory: simple heuristic complexity for PHP files (dependency-free)
echo
echo "Advisory complexity (PHP heuristic)"
echo "-----------------------------------"

declare -a PHP_FILES=()
for rel in "${TRACKED_FILES[@]}"; do
  skip=0
  for ex in "${EXCLUDE_RELATIVE[@]}"; do
    if [[ "$rel" == "$ex"* ]]; then skip=1; break; fi
  done
  [[ $skip -eq 1 ]] && continue
  [[ "$rel" == *.php ]] || continue
  PHP_FILES+=("$rel")
done

php_total_complex=0
declare -a PHP_COMPLEX_ROWS=()
for rel in "${PHP_FILES[@]}"; do
  file="$ROOT_DIR/$rel"
  total=$(wc -l <"$file")
  # Count blanks and simple comment lines (// or /* ... starts or * continuation)
  blank=$(grep -c -E '^\s*$' "$file" || true)
  comments=$(grep -c -E '^\s*(//|/\*|\*)' "$file" || true)
  code=$(( total - blank - comments ))
  set +o pipefail
  ifc=$(grep -o -E '\bif\b' "$file" | wc -l | tr -d ' ' || true)
  elseifc=$(grep -o -E '\belseif\b' "$file" | wc -l | tr -d ' ' || true)
  forlc=$(grep -o -E '\bfor\b' "$file" | wc -l | tr -d ' ' || true)
  foreachc=$(grep -o -E '\bforeach\b' "$file" | wc -l | tr -d ' ' || true)
  whilec=$(grep -o -E '\bwhile\b' "$file" | wc -l | tr -d ' ' || true)
  switchc=$(grep -o -E '\bswitch\b' "$file" | wc -l | tr -d ' ' || true)
  casec=$(grep -o -E '\bcase\b' "$file" | wc -l | tr -d ' ' || true)
  andor=$(grep -o -E '&&|\|\|' "$file" | wc -l | tr -d ' ' || true)
  funcc=$(grep -o -E '\bfunction\b' "$file" | wc -l | tr -d ' ' || true)
  set -o pipefail
  php_complex=$(( ifc + elseifc + forlc + foreachc + whilec + switchc + casec + andor + funcc ))
  php_total_complex=$(( php_total_complex + php_complex ))
  density=0
  if (( code > 0 )); then
    density=$(( (php_complex * 100 + code/2) / code ))
  fi
  PHP_COMPLEX_ROWS+=("$rel|code=$code|complex=$php_complex|density=$density")
done

echo "PHP files analyzed: ${#PHP_FILES[@]}"
echo "Aggregate complexity: $php_total_complex"

printf "%s\n" "${PHP_COMPLEX_ROWS[@]}" | \
  awk -F'[|]' '{
    code=$2; sub(/code=/, "", code);
    complex=$3; sub(/complex=/, "", complex);
    density=$4; sub(/density=/, "", density);
    printf "%05d %s %s %s %s\n", density, $1, $2, $3, $4;
  }' | sort -r | head -n 10 | \
  awk '{printf "%-60s %s %s %s/100loc\n", $2, $3, $4, $5}' || true

echo
echo "Note: Complexity sections are advisory only. Review top hotspots periodically and add TODOs for simplification/refactor when time permits."
