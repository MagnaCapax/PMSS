#!/usr/bin/env bash
set -euo pipefail
# Heuristic scanner for untrusted external data (pass-through).
# Use --ignore to mute signals that are expected for a source.
# shellcheck disable=SC2317
usage() { echo "Usage: externalDataCheck.sh [--ignore NAME] [--strict] [--warn-only]" >&2; }
# shellcheck disable=SC2317
external_data_die() {
	echo "[externalDataCheck] $*" >&2
	exit 2
}

# Config flags and ignored signal list.
ignore=()
strict=0
warn_only=0
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$script_dir/externalDataArgs.sh"
external_data_parse_check_args "$@"

# shellcheck disable=SC2317
ignored() {
	local n
	for n in "${ignore[@]:-}"; do [[ "$n" == "$1" ]] && return 0; done
	return 1
}
signals=()
score=0
# shellcheck disable=SC2317
add_signal() {
	local n="$1" w="$2"
	ignored "$n" && return
	signals+=("$n")
	score=$((score + w))
}

# shellcheck disable=SC1091
source "$script_dir/externalDataPatterns.sh"

# Strip control chars before analysis/output.
clean="$(cat | tr -d '\000-\010\013\014\016-\037')"
if [[ -z "$clean" ]]; then
	echo "[externalDataCheck] score=0 level=low signals=none" >&2
	printf '%s' "$clean"
	exit 0
fi

# Detect structured/programmatic patterns and score the input.
external_data_score_input "$clean"

# Score thresholds: low <3, medium 3-5, high >=6.
level="low"
if ((score >= 6)); then level="high"; elif ((score >= 3)); then level="medium"; fi
signal_list="none"
[[ ${#signals[@]} -gt 0 ]] && signal_list=$(
	IFS=,
	echo "${signals[*]}"
)

echo "[externalDataCheck] score=$score level=$level signals=$signal_list" >&2

# Exit non-zero for high risk (or medium+ in strict mode).
rc=0
((score >= 6)) && rc=1
((strict == 1 && score >= 3)) && rc=1
((warn_only == 1)) && rc=0

printf '%s' "$clean"
exit "$rc"
