#!/bin/sh
# PMSS watchdog network check: require sustained loss before failing.

FAIL_FILE="/run/pmss-watchdog-net-fail"
FAIL_THRESHOLD=1800
PING_COUNT=2
PING_TIMEOUT=5

# Evidence (Refs #916). This check can end in a reboot, and /run is tmpfs, so the
# only sink that survives what the check decides is syslog. A durable copy also
# goes to /var/log/pmss when that directory exists.
LOG_TAG="pmss-watchdog-net"
EVIDENCE_LOG="/var/log/pmss/watchdog-network-check.log"
LOG_STATE="/run/pmss-watchdog-net-lastlog"
LOG_INTERVAL=60

log_line() {
	if command -v logger >/dev/null 2>&1; then
		logger -t "$LOG_TAG" "$1" 2>/dev/null || true
	fi
	if [ -d "/var/log/pmss" ]; then
		echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') $1" >>"$EVIDENCE_LOG" 2>/dev/null || true
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

# What the guest was doing, cheaply -- read straight out of /proc.
host_snapshot() {
	load=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || echo '?')
	up=$(cut -d' ' -f1 /proc/uptime 2>/dev/null || echo '?')
	echo "load=[${load}] uptime=${up}s"
}

# Captured ping output for the window boundaries only (open and reboot-imminent),
# never on the every-10s path. This is the text that distinguishes a dead gateway
# from a filtered ICMP path from a host that cannot send at all.
target_diagnosis() {
	for ip in $TARGETS; do
		out=$(ping -c 1 -W "$PING_TIMEOUT" "$ip" 2>&1 | tr '
' ' ' | sed 's/  */ /g')
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

now=$(date +%s)
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
if command -v ping >/dev/null 2>&1; then
	for ip in $TARGETS; do
		if ping -c "$PING_COUNT" -W "$PING_TIMEOUT" "$ip" >/dev/null 2>&1; then
			if [ -n "$first" ]; then
				log_line "recovered on ${ip} after $((now - first))s; failed first: [${failed:-none}] $(host_snapshot)"
			fi
			rm -f "$FAIL_FILE" "$LOG_STATE" 2>/dev/null || true
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

if [ -n "$first" ]; then
	elapsed=$((now - first))
	if [ "$elapsed" -ge "$FAIL_THRESHOLD" ]; then
		log_line "SUSTAINED FAILURE ${elapsed}s >= ${FAIL_THRESHOLD}s; failed=${failed_count} [${failed}] $(host_snapshot) route=[$(ip -4 route show default 2>/dev/null | tr '
' ';')]"
		log_line "diagnosis: $(target_diagnosis | tr '
' '|')"
		log_line "reporting failure to watchdog; a reboot follows once retry-timeout expires"
		exit 1
	fi
	if log_due "$now"; then
		log_line "failing for ${elapsed}s; failed=${failed_count} [${failed}] $(host_snapshot)"
	fi
else
	echo "$now" >"$FAIL_FILE" 2>/dev/null || true
	echo "$now" >"$LOG_STATE" 2>/dev/null || true
	log_line "failure window opened; failed=${failed_count} [${failed}] $(host_snapshot)"
	log_line "diagnosis: $(target_diagnosis | tr '
' '|')"
fi

exit 245
