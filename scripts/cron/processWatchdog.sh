#!/usr/bin/env bash
#
# processWatchdog.sh
#
# Cron watchdog for runaway transcoding tasks in shared environments.
# Targets only ffmpeg and HandBrakeCLI processes that run far beyond expected
# transcode windows and applies a two-strike policy before termination.

set -u -o pipefail

WATCHDOG_TAG="pmss-process-watchdog"
STATE_DIR="${PMSS_PROCESS_WATCHDOG_STATE_DIR:-/var/run/pmss/process-watchdog}"
LOCK_PATH="${PMSS_PROCESS_WATCHDOG_LOCK_PATH:-/run/lock/pmss-process-watchdog.lock}"
PROCESS_SOURCE="${PMSS_PROCESS_WATCHDOG_PS_SOURCE:-}"

read_numeric_env() {
	local name="$1"
	local fallback="$2"
	local value="${!name:-}"
	if [[ "$value" =~ ^[0-9]+$ ]]; then
		echo "$value"
		return
	fi
	echo "$fallback"
}

MAX_AGE_SECONDS="$(read_numeric_env PMSS_PROCESS_WATCHDOG_MAX_AGE_SECONDS 86400)"
GRACE_SECONDS="$(read_numeric_env PMSS_PROCESS_WATCHDOG_GRACE_SECONDS 1800)"
KILL_WAIT_SECONDS="$(read_numeric_env PMSS_PROCESS_WATCHDOG_KILL_WAIT_SECONDS 60)"

log_watchdog() {
	local level="$1"
	shift
	local message="[$level] $*"
	if command -v logger >/dev/null 2>&1; then
		logger -t "$WATCHDOG_TAG" -- "$message"
	fi
	printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$message"
}

is_numeric() {
	[[ "$1" =~ ^[0-9]+$ ]]
}

list_processes() {
	if [[ -n "$PROCESS_SOURCE" && -f "$PROCESS_SOURCE" ]]; then
		cat "$PROCESS_SOURCE"
		return
	fi
	ps -eo pid=,etimes=,comm=
}

if command -v flock >/dev/null 2>&1; then
	mkdir -p "$(dirname "$LOCK_PATH")" 2>/dev/null || true
	exec 9>"$LOCK_PATH" || exit 0
	flock -n 9 || exit 0
fi

mkdir -p "$STATE_DIR" 2>/dev/null || exit 0

now_epoch="$(date +%s)"

while read -r pid etimes comm; do
	if ! is_numeric "$pid" || ! is_numeric "$etimes"; then
		continue
	fi
	if [[ "$comm" != "ffmpeg" && "$comm" != "HandBrakeCLI" ]]; then
		continue
	fi
	if ((etimes < MAX_AGE_SECONDS)); then
		continue
	fi

	state_file="$STATE_DIR/${pid}.state"
	process_start_epoch=$((now_epoch - etimes))

	if [[ ! -f "$state_file" ]]; then
		printf '%s\n' "$now_epoch" >"$state_file"
		log_watchdog WARN "Marked long-running process pid=${pid} comm=${comm} etimes=${etimes}s"
		continue
	fi

	first_seen_epoch="$(cat "$state_file" 2>/dev/null || true)"
	if ! is_numeric "$first_seen_epoch" || ((first_seen_epoch < process_start_epoch)); then
		printf '%s\n' "$now_epoch" >"$state_file"
		log_watchdog WARN "Reset strike state for pid=${pid} comm=${comm} (stale mark)"
		continue
	fi

	if ((now_epoch - first_seen_epoch < GRACE_SECONDS)); then
		continue
	fi

	log_watchdog WARN "Terminating stuck process pid=${pid} comm=${comm} etimes=${etimes}s"
	if ! kill -TERM "$pid" 2>/dev/null; then
		rm -f "$state_file"
		continue
	fi

	sleep "$KILL_WAIT_SECONDS"
	if kill -0 "$pid" 2>/dev/null; then
		kill -KILL "$pid" 2>/dev/null || true
		log_watchdog WARN "Force-killed stuck process pid=${pid} comm=${comm}"
	else
		log_watchdog INFO "Process exited after SIGTERM pid=${pid} comm=${comm}"
	fi
	rm -f "$state_file"
done < <(list_processes)

for state_file in "$STATE_DIR"/*.state; do
	[[ -e "$state_file" ]] || break
	stale_pid="$(basename "$state_file" .state)"
	if ! is_numeric "$stale_pid" || ! kill -0 "$stale_pid" 2>/dev/null; then
		rm -f "$state_file"
	fi
done

exit 0
