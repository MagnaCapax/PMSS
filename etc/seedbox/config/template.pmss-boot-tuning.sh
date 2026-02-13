#!/bin/sh
# PMSS boot-time tuning for /sys knobs that sysctl cannot persist.
# Keeps sysctl.d focused on /proc/sys while applying runtime /sys writes.

write_sys() {
	[ -e "$1" ] || return 0
	echo "$2" >"$1" 2>/dev/null || true
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
	write_sys "$md/queue/read_ahead_kb" 2048
	write_sys "$md/queue/scheduler" bfq
done

# Set per-disk scheduler and read-ahead values.
for disk in /sys/block/sd*; do
	[ -d "$disk/queue" ] || continue
	rot=$(cat "$disk/queue/rotational" 2>/dev/null || echo "")
	if [ "$rot" = "0" ]; then
		write_sys "$disk/queue/scheduler" mq-deadline
		write_sys "$disk/queue/read_ahead_kb" 512
	else
		write_sys "$disk/queue/scheduler" bfq
		write_sys "$disk/queue/read_ahead_kb" 1024
	fi
done
for disk in /sys/block/nvme*; do
	[ -d "$disk/queue" ] || continue
	write_sys "$disk/queue/scheduler" none
	write_sys "$disk/queue/read_ahead_kb" 128
done
