#!/bin/sh
# Opt-in ext4 group preallocation workaround; see ADR 0169.
# Decision is pure; the applier accepts proc/sys roots for hermetic fixtures.

pmss_ext4_prealloc_decide() {
	awk -v current="$1" -v stripe="$2" -v kernel="$3" 'BEGIN {
		if (kernel !~ /^[0-9]+\.[0-9]+([.-]|$)/) { print "skip:unknown-kernel"; exit }
		split(kernel, version, ".")
		if (version[1]+0 > 6 || (version[1]+0 == 6 && version[2]+0 >= 8)) {
			print "skip:fixed-kernel"; exit
		}
		# ext4 exposes unsigned 32-bit values; reject unsafe or missing reads.
		if (current !~ /^[0-9]+$/ || stripe !~ /^[0-9]+$/ ||
			current+0 < 1 || current+0 > 4294967295 || stripe+0 > 4294967295) {
			print "skip:invalid-values"; exit
		}
		if (stripe+0 <= 1) { print "skip:no-stripe"; exit }
		if (current % stripe != 0) { print "skip:already-unaligned"; exit }
		printf "%.0f\n", current-1
	}'
}

# Keep malformed reads out of the numeric JSON fields.
pmss_ext4_prealloc_number() {
	awk -v v="$1" 'BEGIN {
		if (v ~ /^[0-9]+$/ && v+0 <= 4294967295) printf "%.0f", v
		else printf "null"
	}'
}

pmss_ext4_prealloc_apply() (
	# A proc entry represents one mounted superblock, including non-md devices.
	proc_root=$1
	sys_root=$2
	kernel=$3
	separator=''
	printf '['
	for options in "$proc_root"/*/options; do
		[ -e "$options" ] || continue
		dev=${options%/options}
		dev=${dev##*/}
		# Kernel block names normally use this alphabet; keep JSON/path data literal.
		case "$dev" in '' | *[!a-zA-Z0-9_.!-]*) continue ;; esac
		knob="$sys_root/$dev/mb_group_prealloc"
		stripe=$(awk -F= '$1 == "stripe" { print $2; exit }' "$options" 2>/dev/null)
		current=$(cat "$knob" 2>/dev/null)
		result=$(pmss_ext4_prealloc_decide "$current" "$stripe" "$kernel")
		if [ ! -e "$knob" ]; then
			result=skip:missing-prealloc
		fi
		before=$current
		case "$result" in
		skip:*) ;;
		*)
			# Unlike write_sys, retain write failures and verify the observed value.
			if (printf '%s\n' "$result" >"$knob") 2>/dev/null; then
				current=$(cat "$knob" 2>/dev/null)
				if [ "$current" = "$result" ]; then
					result=applied
				else
					result=readback-mismatch
				fi
			else
				current=$(cat "$knob" 2>/dev/null)
				result=write-failed
			fi
			;;
		esac
		# Only canonical uint32 values enter JSON as numbers; errors become null.
		printf '%s{"device":"%s","stripe":%s,"before":%s,"after":%s,"result":"%s"}' \
			"$separator" "$dev" "$(pmss_ext4_prealloc_number "$stripe")" \
			"$(pmss_ext4_prealloc_number "$before")" "$(pmss_ext4_prealloc_number "$current")" "$result"
		separator=,
	done
	printf ']'
)
