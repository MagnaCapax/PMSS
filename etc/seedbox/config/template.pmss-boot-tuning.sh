#!/bin/sh
# PMSS boot-time tuning for /sys knobs that sysctl cannot persist.
# Keeps sysctl.d focused on /proc/sys while applying runtime /sys writes.

write_sys() {
	[ -e "$1" ] || return 0
	echo "$2" >"$1" 2>/dev/null || true
}

json_bool() {
	[ "$1" = "1" ] && printf 'true' || printf 'false'
}

json_int_or_null() {
	case "$1" in
	'' | *[!0-9]*) printf 'null' ;;
	*) printf '%s' "$1" ;;
	esac
}

write_hardware_summary() {
	timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ" 2>/dev/null || echo '')
	ram_gb=''
	default_iface=''
	nic_speed_mbps=''
	zswap_requested=0
	has_mglru=0
	target_dir=/etc/seedbox/config
	target_file="$target_dir/hardware.json"
	tmp_file="$target_file.tmp"

	if [ -r /proc/meminfo ]; then
		ram_kb=$(awk '/^MemTotal:/ { print $2; exit }' /proc/meminfo 2>/dev/null || echo '')
		case "$ram_kb" in
		'' | *[!0-9]*) ;;
		*) ram_gb=$(((ram_kb + 1048575) / 1048576)) ;;
		esac
	fi

	if command -v ip >/dev/null 2>&1; then
		default_iface=$(ip route show default 2>/dev/null | awk 'NR == 1 { for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit } }')
	fi
	if [ -n "$default_iface" ] && [ -r "/sys/class/net/$default_iface/speed" ]; then
		nic_speed_mbps=$(cat "/sys/class/net/$default_iface/speed" 2>/dev/null || echo '')
		case "$nic_speed_mbps" in
		'' | *[!0-9]*) nic_speed_mbps='' ;;
		esac
	fi

	[ -e /sys/kernel/mm/lru_gen/enabled ] && has_mglru=1
	if [ "$swap_fast" = "1" ] && [ -f /sys/module/zswap/parameters/enabled ]; then
		zswap_requested=1
	fi

	mkdir -p "$target_dir" 2>/dev/null || return 0
	cat >"$tmp_file" <<EOF
{
  "timestamp": "$timestamp",
  "detection": {
    "ram_gb": $(json_int_or_null "$ram_gb"),
    "swap_is_fast": $(json_bool "$swap_fast"),
    "nic_speed_mbps": $(json_int_or_null "$nic_speed_mbps"),
    "has_mglru": $(json_bool "$has_mglru")
  },
  "applied": {
    "mglru_requested": $(json_bool "$has_mglru"),
    "zswap_requested": $(json_bool "$zswap_requested"),
    "md_scheduler": "bfq",
    "md_read_ahead_kb": 4096,
    "rotational_scheduler": "bfq",
    "rotational_read_ahead_kb": 4096,
    "nonrotational_scheduler": "mq-deadline",
    "nonrotational_read_ahead_kb": 4096,
    "nvme_scheduler": "none",
    "nvme_read_ahead_kb": 128,
    "bcache_scheduler": "bfq",
    "bcache_read_ahead_kb": 4096
  }
}
EOF
	mv "$tmp_file" "$target_file" 2>/dev/null || rm -f "$tmp_file"
}

# Enable MGLRU when supported (kernel 6.1+).
write_sys /sys/kernel/mm/lru_gen/enabled 7

# Enable zswap only when swap is on fast storage.
swap_fast=0
if [ -r /proc/swaps ]; then
	while read -r path _; do
		[ "$path" = "Filename" ] && continue
		[ -b "$path" ] || continue
		dev=$(readlink -f "$path" 2>/dev/null || echo "$path")
		dev=$(basename "$dev")
		rot_path="/sys/class/block/$dev/queue/rotational"
		if [ -r "$rot_path" ] && [ "$(cat "$rot_path" 2>/dev/null)" = "0" ]; then
			swap_fast=1
			break
		fi
		for slave in "/sys/class/block/$dev/slaves/"*; do
			[ -e "$slave/queue/rotational" ] || continue
			rot=$(cat "$slave/queue/rotational" 2>/dev/null || echo "")
			if [ "$rot" = "0" ]; then
				swap_fast=1
				break 2
			fi
		done
	done </proc/swaps
fi

if [ "$swap_fast" = "1" ] && [ -f /sys/module/zswap/parameters/enabled ]; then
	modprobe zswap 2>/dev/null || true
	modprobe lz4 2>/dev/null || true
	if [ -f /sys/module/zswap/parameters/compressor ]; then
		echo lz4 >/sys/module/zswap/parameters/compressor 2>/dev/null ||
			echo zstd >/sys/module/zswap/parameters/compressor 2>/dev/null || true
	fi
	write_sys /sys/module/zswap/parameters/max_pool_percent 10
	write_sys /sys/module/zswap/parameters/enabled 1
	write_sys /sys/module/zswap/parameters/shrinker_enabled Y
fi

# Tune MD arrays for throughput and rebuild speed.
for md in /sys/block/md[0-9]*; do
	[ -d "$md" ] || continue
	write_sys "$md/md/stripe_cache_size" 32768
	write_sys "$md/md/sync_speed_min" 25000
	write_sys "$md/md/sync_speed_max" 750000
	write_sys "$md/queue/read_ahead_kb" 4096
	write_sys "$md/queue/scheduler" bfq
done

# Set per-disk scheduler and read-ahead values.
for disk in /sys/block/sd*; do
	[ -d "$disk/queue" ] || continue
	rot=$(cat "$disk/queue/rotational" 2>/dev/null || echo "")
	# read_ahead_kb is 4096 for BOTH classes because template.rc.local writes a blanket 4096
	# to every non-nvme non-md device (sd* included) and runs LATER than this unit
	# (rc-local is After=network-online.target; this unit is After=local-fs.target), so
	# rc.local wins on every overlapping device. Operator ruling 2026-09-18: "4096 is more
	# authoritative". A differing value here is not a policy, it is a value that gets
	# overwritten one second later - stating 4096 makes the code say what actually happens.
	# The SCHEDULER split below is deliberately UNCHANGED: it is the documented intent, the
	# operator has ruled only on read_ahead, and rc.local's blanket bfq currently overrides
	# mq-deadline on SSDs. That override is tracked in ADR 0064, not silently blessed here.
	if [ "$rot" = "0" ]; then
		write_sys "$disk/queue/scheduler" mq-deadline
		write_sys "$disk/queue/read_ahead_kb" 4096
	else
		write_sys "$disk/queue/scheduler" bfq
		write_sys "$disk/queue/read_ahead_kb" 4096
	fi
done
for disk in /sys/block/nvme*; do
	[ -d "$disk/queue" ] || continue
	write_sys "$disk/queue/scheduler" none
	write_sys "$disk/queue/read_ahead_kb" 128
done

# Virtio-blk disks (KVM guests). These carry /home on every PMSS VM guest, so
# skipping them leaves all customer I/O unscheduled and makes the per-user
# blkio.bfq.weight tiers set by cron/cgroupBfqWeightApply.php inert.
# virtio-blk cannot advertise non-rotational, so rotational always reads 1 here
# and BFQ is always the correct choice. read_ahead_kb MUST match template.rc.local's 4096:
# both write this knob, so a mismatch makes the value depend on which ran last. The
# original 2048 here matched the value observed in the FIELD, which was a stale
# 2022-vintage rc.local pushed by an external actor - not the specification.
for disk in /sys/block/vd* /sys/block/xvd*; do
	[ -d "$disk/queue" ] || continue
	write_sys "$disk/queue/scheduler" bfq
	write_sys "$disk/queue/read_ahead_kb" 4096
done

# bcache devices (legacy caching layer; several hosts run MD ON TOP of bcache). Until now this
# unit had NO bcache branch, so template.rc.local was their ONLY queue-knob writer: its blanket
# loop is `ls /sys/block|grep -v nvme|grep -v md|grep -v loop`, which INCLUDES bcache*, and it
# writes 4096 + bfq there. Removing that loop without this branch would lose the tuning entirely,
# which is why ADR 0064 orders this branch FIRST. Values match rc.local for the same reason every
# other class here does: both writers touch these knobs, so a mismatch makes the effective value
# depend on which unit ran last.
# SCOPE FENCE - queue knobs ONLY. cache_mode / writeback_percent / writeback_delay are written by
# a SEPARATE rc.local loop, not the blanket per-disk one, and are deliberately NOT adopted here:
# ADR 0064 step 2 gates only the per-disk loop, so that block survives and loses no coverage, and
# the cache MODE is a live policy question (hosts are being moved off writeback) that must not be
# frozen into this unit as a side effect of a read_ahead consolidation.
for disk in /sys/block/bcache*; do
	[ -d "$disk/queue" ] || continue
	write_sys "$disk/queue/scheduler" bfq
	write_sys "$disk/queue/read_ahead_kb" 4096
done

# Record the detected host profile and the boot-time tuning targets for audits.
write_hardware_summary
