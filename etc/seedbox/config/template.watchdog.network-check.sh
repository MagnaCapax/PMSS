#!/bin/sh
# PMSS watchdog network check: require sustained loss before failing.

FAIL_FILE="/run/pmss-watchdog-net-fail"
FAIL_THRESHOLD=1800
PING_COUNT=2
PING_TIMEOUT=5

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

if command -v ping >/dev/null 2>&1; then
	for ip in $TARGETS; do
		if ping -c "$PING_COUNT" -W "$PING_TIMEOUT" "$ip" >/dev/null 2>&1; then
			rm -f "$FAIL_FILE" 2>/dev/null || true
			exit 0
		fi
	done
fi

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

if [ -n "$first" ]; then
	elapsed=$((now - first))
	if [ "$elapsed" -ge "$FAIL_THRESHOLD" ]; then
		exit 1
	fi
else
	echo "$now" >"$FAIL_FILE" 2>/dev/null || true
fi

exit 245
