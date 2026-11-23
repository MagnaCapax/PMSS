#!/bin/bash
# Detach nested mounts from a pinned purge directory before any recursive work.
set -euo pipefail

purge_root=${1-}
mountinfo=${2-/proc/self/mountinfo}
[[ -n "$purge_root" && "$purge_root" != / && "$(pwd -P)" == "$purge_root" ]] || exit 1

# Decode exactly the kernel's three-digit escapes; printf %b can consume a
# following octal digit. Decode backslashes last to avoid a second expansion.
mountTargetDecode() {
	target=${1//\\040/ }
	target=${target//\\011/$'\t'}
	target=${target//\\012/$'\n'}
	target=${target//\\134/\\}
}

# Keep the kernel's escaped fields until after sorting, so newlines and tabs in
# customer-chosen mount names cannot split targets or become shell syntax.
targets=()
rows=0
while IFS= read -r mount_row; do
	read -r mount_id parent_id device mount_root encoded_target details <<< "$mount_row"
	[[ "$mount_id" =~ ^[0-9]+$ && "$encoded_target" == /* && "$details" == *" - "* ]] || exit 1
	# A plausible target cannot certify a row with corrupt fixed kernel fields.
	# Refuse the snapshot before queued detaches or a no-mount purge can proceed.
	[[ "$parent_id" =~ ^[0-9]+$ && "$device" =~ ^[0-9]+:[0-9]+$ && "$mount_root" == /* ]] || exit 1
	# The separator alone cannot certify a complete row. Require all three
	# post-separator fields before any queued detach or no-mount purge proceeds.
	read -r filesystem source super_options extra <<< "${details#* - }"
	[[ -n "$filesystem" && -n "$source" && -n "$super_options" && -z "$extra" ]] || exit 1
	rows=$((rows + 1))
	mountTargetDecode "$encoded_target"
	# Prefix selection is safe only for canonical kernel targets. Never resolve
	# malformed paths here: that could select a mount outside the pinned root.
	case "$target" in
		*/./* | */. | */../* | */.. | *//*) exit 1 ;;
	esac
	[[ "$target" == / || "$target" != */ ]] || exit 1
	if [[ "$target" == "$purge_root/"* ]]; then
		targets+=("$encoded_target")
	fi
done < "$mountinfo"
# read leaves an unterminated final row in mount_row even though it returns failure.
# Refuse that incomplete snapshot before any unmount or recursive purge work.
[[ -z "$mount_row" ]] || exit 1
((rows > 0)) || exit 1
((${#targets[@]} > 0)) || exit 0

# Reverse lexical order puts every child before its parent; retain duplicate
# targets for stacked mounts. Failed discovery or detachment forbids the walk.
sorted=$(printf '%s\n' "${targets[@]}" | LC_ALL=C sort -r)
while IFS= read -r encoded_target; do
	mountTargetDecode "$encoded_target"
	# Kernel paths are already canonical. Avoid FUSE stat calls and helper hooks.
	timeout --kill-after=5s 45s umount --internal-only --no-canonicalize --lazy -- "$target"
done <<< "$sorted"
