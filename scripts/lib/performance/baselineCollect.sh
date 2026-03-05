#!/usr/bin/env bash
# Shared helpers for performance baseline collection utility.

pmssPerformanceJsonEscape() {
	printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

pmssPerformanceJsonNumberOrNull() {
	if [[ "$1" =~ ^-?[0-9]+([.][0-9]+)?$ ]]; then
		printf '%s' "$1"
	else
		printf 'null'
	fi
}

pmssPerformanceBaselineUsage() {
	echo "Usage: scripts/util/performanceBaselineCollect.sh [--output <path>]"
}

pmssPerformanceBaselineCollectMain() {
	set -euo pipefail

	# Parse a minimal CLI: optional output path or help.
	local output_path
	output_path=""
	while [[ $# -gt 0 ]]; do
		case "$1" in
		--output)
			[[ $# -ge 2 ]] || {
				pmssPerformanceBaselineUsage >&2
				exit 2
			}
			output_path="$2"
			shift 2
			;;
		-h | --help)
			pmssPerformanceBaselineUsage
			exit 0
			;;
		*)
			pmssPerformanceBaselineUsage >&2
			exit 2
			;;
		esac
	done

	# Capture immutable host identity values for this sample.
	local timestamp_utc kernel_release
	timestamp_utc="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	kernel_release="$(uname -r 2>/dev/null || echo unknown)"

	# Read a focused sysctl subset tied to network and memory tuning.
	local tcp_congestion_control rmem_max wmem_max swappiness vfs_cache_pressure min_free_kbytes
	tcp_congestion_control="$(sysctl -n net.ipv4.tcp_congestion_control 2>/dev/null || true)"
	rmem_max="$(sysctl -n net.core.rmem_max 2>/dev/null || true)"
	wmem_max="$(sysctl -n net.core.wmem_max 2>/dev/null || true)"
	swappiness="$(sysctl -n vm.swappiness 2>/dev/null || true)"
	vfs_cache_pressure="$(sysctl -n vm.vfs_cache_pressure 2>/dev/null || true)"
	min_free_kbytes="$(sysctl -n vm.min_free_kbytes 2>/dev/null || true)"

	# Pull one short vmstat sample to estimate current memory pressure.
	local swap_in_kb_per_sec swap_out_kb_per_sec mem_free_kb
	swap_in_kb_per_sec="null"
	swap_out_kb_per_sec="null"
	mem_free_kb="null"
	if command -v vmstat >/dev/null 2>&1; then
		local vmstat_row free_kb si so
		vmstat_row="$(vmstat 1 2 2>/dev/null | tail -n 1 || true)"
		if [[ -n "$vmstat_row" ]]; then
			read -r _ _ _ free_kb _ _ si so _ <<<"$vmstat_row"
			swap_in_kb_per_sec="$(pmssPerformanceJsonNumberOrNull "${si:-}")"
			swap_out_kb_per_sec="$(pmssPerformanceJsonNumberOrNull "${so:-}")"
			mem_free_kb="$(pmssPerformanceJsonNumberOrNull "${free_kb:-}")"
		fi
	fi

	# Parse kernel TCP retransmission counter from /proc for regressions.
	local tcp_retrans_segments tcp_retrans_raw
	tcp_retrans_segments="null"
	if [[ -r /proc/net/snmp ]]; then
		tcp_retrans_raw="$(awk '/^Tcp:/ { if (seen == 0) { for (i = 1; i <= NF; i++) key[$i] = i; seen = 1; next } print $(key["RetransSegs"]); exit }' /proc/net/snmp 2>/dev/null || true)"
		tcp_retrans_segments="$(pmssPerformanceJsonNumberOrNull "$tcp_retrans_raw")"
	fi

	# Gather one iostat device row when available; tolerate missing tools/fields.
	local disk_device disk_await_ms disk_util_pct
	disk_device=""
	disk_await_ms="null"
	disk_util_pct="null"
	if command -v iostat >/dev/null 2>&1; then
		local disk_sample disk_await_raw disk_util_raw
		disk_sample="$(iostat -dx 1 1 2>/dev/null | awk '
			/^Device/ {
				headers_ready = 1
				for (i = 1; i <= NF; i++) { name = $i; gsub(":", "", name); col[name] = i }
				next
			}
			(headers_ready == 1 && NF > 1 && $1 !~ /^Linux/) {
				device = $1
				await = ("await" in col ? $(col["await"]) : "")
				util = ("%util" in col ? $(col["%util"]) : "")
				print device "|" await "|" util
				exit
			}' || true)"
		if [[ -n "$disk_sample" ]]; then
			IFS='|' read -r disk_device disk_await_raw disk_util_raw <<<"$disk_sample"
			disk_await_ms="$(pmssPerformanceJsonNumberOrNull "${disk_await_raw:-}")"
			disk_util_pct="$(pmssPerformanceJsonNumberOrNull "${disk_util_raw:-}")"
		fi
	fi

	# Emit stable JSON fields with nulls for unavailable metrics.
	local json_payload
	json_payload="$(
		cat <<JSON
{
  "timestamp": "${timestamp_utc}",
  "kernel": "$(pmssPerformanceJsonEscape "$kernel_release")",
  "sysctl": {
    "net.ipv4.tcp_congestion_control": "$(pmssPerformanceJsonEscape "$tcp_congestion_control")",
    "net.core.rmem_max": $(pmssPerformanceJsonNumberOrNull "$rmem_max"),
    "net.core.wmem_max": $(pmssPerformanceJsonNumberOrNull "$wmem_max"),
    "vm.swappiness": $(pmssPerformanceJsonNumberOrNull "$swappiness"),
    "vm.vfs_cache_pressure": $(pmssPerformanceJsonNumberOrNull "$vfs_cache_pressure"),
    "vm.min_free_kbytes": $(pmssPerformanceJsonNumberOrNull "$min_free_kbytes")
  },
  "network": {
    "tcp_retrans_segments": ${tcp_retrans_segments}
  },
  "disk": {
    "device": "$(pmssPerformanceJsonEscape "$disk_device")",
    "await_ms": ${disk_await_ms},
    "util_pct": ${disk_util_pct}
  },
  "memory": {
    "swap_in_kb_per_sec": ${swap_in_kb_per_sec},
    "swap_out_kb_per_sec": ${swap_out_kb_per_sec},
    "free_kb": ${mem_free_kb}
  }
}
JSON
	)"

	# Write atomically to the requested file path, or print to stdout.
	if [[ -n "$output_path" ]]; then
		mkdir -p "$(dirname "$output_path")"
		printf '%s\n' "$json_payload" >"$output_path"
		echo "$output_path"
	else
		printf '%s\n' "$json_payload"
	fi
}
