#!/bin/sh
# PMSS watchdog network check: require sustained loss before failing.

# Evidence must leave room for the daemon's 60s test timeout, even on slow guests.
START_UP=""
{ read -r START_UP _ </proc/uptime; } 2>/dev/null
START_UP=${START_UP%%.*}
EVIDENCE_BUDGET=20

FAIL_FILE="/run/pmss-watchdog-net-fail"
FAIL_THRESHOLD=1800
PING_COUNT=2
PING_TIMEOUT=5

# Evidence (Refs #916). Keep the primary record under the check*.log rotation
# policy when /var/log/pmss exists; syslog carries a second copy across reboots.
LOG_TAG="pmss-watchdog-net"
EVIDENCE_LOG="/var/log/pmss/checkNetworkWatchdog.log"
LOG_STATE="/run/pmss-watchdog-net-lastlog"
SUSTAINED_FILE="/run/pmss-watchdog-net-sustained"
LOG_INTERVAL=60

log_line() {
	if [ -d "/var/log/pmss" ]; then
		echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') $1" >>"$EVIDENCE_LOG" 2>/dev/null || true
	fi
	if command -v logger >/dev/null 2>&1; then
		logger -t "$LOG_TAG" "$1" 2>/dev/null || true
	fi
}

# One line per LOG_INTERVAL while a failure window stays open: the daemon runs this
# every 10s, and a full window is 1800s. Window open, recovery, and the
# reboot-imminent line are never rate-limited.
log_due() {
	last=""
	if [ -f "$LOG_STATE" ]; then
		last=$(cat "$LOG_STATE" 2>/dev/null || echo "")
	fi
	case "$last" in
	'' | *[!0-9]*)
		last=""
		;;
	esac
	if [ -z "$last" ] || [ $(($1 - last)) -ge "$LOG_INTERVAL" ]; then
		echo "$1" >"$LOG_STATE" 2>/dev/null || true
		return 0
	fi
	return 1
}

# Budget checks use builtins only; an unavailable clock disables extra evidence.
within_budget() {
	runtime="?"
	now_up=""
	{ read -r now_up _ </proc/uptime; } 2>/dev/null
	now_up=${now_up%%.*}
	case "$START_UP" in '' | *[!0-9]*) return 1 ;; esac
	case "$now_up" in '' | *[!0-9]*) return 1 ;; esac
	runtime=$((now_up - START_UP))
	[ "$runtime" -lt "$EVIDENCE_BUDGET" ]
}

# Finish timing only for failure/recovery evidence, never on the healthy path.
probe_finished() {
	PROBE_END=""
	{ read -r PROBE_END _ </proc/uptime; } 2>/dev/null
	PROBE_END=${PROBE_END%%.*}
	probe_seconds="?"
	case "$PROBE_START" in '' | *[!0-9]*) return ;; esac
	case "$PROBE_END" in '' | *[!0-9]*) return ;; esac
	probe_seconds=$((PROBE_END - PROBE_START))
}

# Local pressure and interface counters distinguish a stalled guest from lost ICMP.
host_snapshot() {
	if ! within_budget; then
		echo "snapshot=skipped runtime=${runtime}s probe=${probe_seconds}s"
		return
	fi
	load=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || echo '?')
	up=$(cut -d' ' -f1 /proc/uptime 2>/dev/null || echo '?')
	io_pressure=$(awk '$1 == "some" {sub(/^avg10=/, "", $2); print $2; exit}' /proc/pressure/io 2>/dev/null)
	cpu_pressure=$(awk '$1 == "some" {sub(/^avg10=/, "", $2); print $2; exit}' /proc/pressure/cpu 2>/dev/null)
	iface=$(ip -4 route show default 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "dev") {print $(i + 1); exit}}')
	rx=$(cat "/sys/class/net/$iface/statistics/rx_bytes" 2>/dev/null || echo '?')
	tx=$(cat "/sys/class/net/$iface/statistics/tx_bytes" 2>/dev/null || echo '?')
	echo "load=[${load}] uptime=${up}s psi_io_some_avg10=${io_pressure:-?} psi_cpu_some_avg10=${cpu_pressure:-?} iface=${iface:-?} rx_bytes=${rx} tx_bytes=${tx} probe=${probe_seconds}s"
}

# Captured ping output for the window boundaries only (open and reboot-imminent),
# never on the every-10s path. This is the text that distinguishes a dead gateway
# from a filtered ICMP path from a host that cannot send at all.
target_diagnosis() {
	command -v ping >/dev/null 2>&1 || return 0
	for ip in $TARGETS; do
		if ! within_budget; then
			echo "budget-exhausted"
			break
		fi
		out=$(ping -c 1 -W "$PING_TIMEOUT" "$ip" 2>&1 | tr '\n' ' ' | sed 's/  */ /g')
		echo "${ip}: ${out}"
	done
}

DEFAULT_GATEWAYS=$(ip -4 route show default 2>/dev/null | awk '
	$1 == "default" {
		for (i = 1; i < NF; i++) {
			if ($i == "via") {
				print $(i + 1)
			}
		}
	}
')
EXTERNAL_TARGETS="1.1.1.1 8.8.8.8"
TARGETS="$DEFAULT_GATEWAYS $EXTERNAL_TARGETS"

if [ -f "$FAIL_FILE" ]; then
	first=$(cat "$FAIL_FILE" 2>/dev/null || echo "")
else
	first=""
fi

case "$first" in
'' | *[!0-9]*)
	first=""
	;;
esac

# Which targets failed, and how many, is the evidence #669 needed and never had.
# Accumulate it as we go; the loop still answers on the first target that responds.
failed=""
failed_count=0
PROBE_START=""
{ read -r PROBE_START _ </proc/uptime; } 2>/dev/null
PROBE_START=${PROBE_START%%.*}
if command -v ping >/dev/null 2>&1; then
	for ip in $TARGETS; do
		if ping -c "$PING_COUNT" -W "$PING_TIMEOUT" "$ip" >/dev/null 2>&1; then
			if [ -n "$first" ]; then
				now=$(date +%s)
				probe_finished
				log_line "recovered on ${ip} after $((now - first))s; failed first: [${failed:-none}] $(host_snapshot)"
			fi
			rm -f "$FAIL_FILE" "$LOG_STATE" "$SUSTAINED_FILE" 2>/dev/null || true
			exit 0
		fi
		failed="${failed}${failed:+ }${ip}"
		failed_count=$((failed_count + 1))
	done
else
	# Previously indistinguishable from a network outage: no ping binary means every
	# run fails and the host reboots on a missing package.
	failed="ping-binary-missing"
fi
now=$(date +%s)
probe_finished

if [ -n "$first" ]; then
	elapsed=$((now - first))
	if [ "$elapsed" -ge "$FAIL_THRESHOLD" ]; then
		# The daemon may retry for 3600s: capture the full evidence only once.
		if [ ! -f "$SUSTAINED_FILE" ]; then
			echo "$now" >"$SUSTAINED_FILE" 2>/dev/null || true
			echo "$now" >"$LOG_STATE" 2>/dev/null || true
			route=""
			if within_budget; then
				route=$(ip -4 route show default 2>/dev/null | tr '\n' ';')
			fi
			log_line "SUSTAINED FAILURE ${elapsed}s >= ${FAIL_THRESHOLD}s; failed=${failed_count} [${failed}] $(host_snapshot) route=[${route}]"
			log_line "diagnosis: $(target_diagnosis | tr '\n' '|')"
			log_line "reporting failure to watchdog; a reboot follows once retry-timeout expires"
		elif log_due "$now"; then
			log_line "failing for ${elapsed}s; failed=${failed_count} [${failed}] $(host_snapshot)"
		fi
		exit 1
	fi
	if log_due "$now"; then
		log_line "failing for ${elapsed}s; failed=${failed_count} [${failed}] $(host_snapshot)"
	fi
else
	rm -f "$SUSTAINED_FILE" 2>/dev/null || true
	echo "$now" >"$FAIL_FILE" 2>/dev/null || true
	echo "$now" >"$LOG_STATE" 2>/dev/null || true
	log_line "failure window opened; failed=${failed_count} [${failed}] $(host_snapshot)"
	log_line "diagnosis: $(target_diagnosis | tr '\n' '|')"
fi

exit 245
