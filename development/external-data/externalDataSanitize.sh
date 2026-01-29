#!/usr/bin/env bash
set -euo pipefail
# Sanitize external data for safe inclusion in prompts/logs.
# Wraps content and calls externalDataCheck.sh to flag risks.
# shellcheck disable=SC2317
usage() { echo "Usage: externalDataSanitize.sh [--label TEXT] [--encode] [--raw] [--ignore NAME] [--strict] [--warn-only]" >&2; }
# shellcheck disable=SC2317
external_data_die() {
	echo "[externalDataSanitize] $*" >&2
	exit 2
}
label=""
encode=1
raw=0
strict=0
warn_only=0
ignore=()
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$script_dir/externalDataArgs.sh"
external_data_parse_sanitize_args "$@"
# Read input once, strip control chars to keep logs safe.
clean="$(cat | tr -d '\000-\010\013\014\016-\037')"

# Run the checker to emit signals; propagate its exit code.
check_cmd=("$script_dir/externalDataCheck.sh")
for name in "${ignore[@]:-}"; do
	check_cmd+=(--ignore "$name")
done
((strict == 1)) && check_cmd+=(--strict)
((warn_only == 1)) && check_cmd+=(--warn-only)

set +e
checked="$(printf '%s' "$clean" | "${check_cmd[@]}" 2> >(cat >&2))"
rc=$?
set -e

reject_high=0
((rc != 0)) && reject_high=1
if ((raw == 1)); then
	((reject_high == 1)) && exit "$rc"
	printf '%s' "$checked"
	exit "$rc"
fi

timestamp="${PMSS_EXTERNAL_DATA_TIMESTAMP:-$(date -u +"%Y-%m-%dT%H:%M:%SZ")}"
hostname="${PMSS_EXTERNAL_DATA_HOSTNAME:-$(hostname 2>/dev/null || uname -n)}"
pid="${PMSS_EXTERNAL_DATA_PID:-$$}"

command -v sha256sum >/dev/null 2>&1 || external_data_die "sha256sum not found"

# shellcheck disable=SC1091
source "$script_dir/externalDataWrap.sh"

# Wrap output so downstream consumers keep it isolated as data.
header="UNTRUSTED EXTERNAL DATA"
encoding="plain"
payload="$checked"
if ((reject_high == 1)); then
	header="$header (REJECTED)"
	encoding="rejected"
	payload="[REDACTED: high-risk external data]"
elif ((encode == 1)); then
	header="$header (PMSS-B64V2)"
	encoding="pmss-b64v2"
	# Encode only when explicitly requested.
	command -v base64 >/dev/null 2>&1 || external_data_die "base64 not found"
	layer1="$(printf '%s' "$checked" | base64 | tr -d '\n')"
	layer2="pmss:${layer1}:data"
	payload="$(printf '%s' "$layer2" | base64 | tr -d '\n')"
fi

external_data_wrap_xml "$header" "$encoding" "$payload" "$label" "$timestamp" "$hostname" "$pid"

exit "$rc"
