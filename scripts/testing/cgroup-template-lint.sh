#!/usr/bin/env bash
set -euo pipefail
# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

v2="$ROOT_DIR/etc/seedbox/config/template.cgroup.user-slice.v2.conf"
v1="$ROOT_DIR/etc/seedbox/config/template.cgroup.user-slice.v1.conf"

fail=0

check_file_exists() {
	local f="$1"
	[[ -f "$f" ]] || {
		echo "missing: $f" >&2
		fail=1
	}
}

check_absent() {
	local f="$1" pat="$2" why="$3"
	if grep -Eq "$pat" "$f"; then
		echo "$why in $f" >&2
		fail=1
	fi
}

check_present() {
	local f="$1" pat="$2" why="$3"
	if ! grep -Eq "$pat" "$f"; then
		echo "$why not found in $f" >&2
		fail=1
	fi
}

check_file_exists "$v2"
check_file_exists "$v1"

# v2 template rules
check_absent "$v2" "BlockIOAccounting" "BlockIOAccounting must not appear (v2)"
check_absent "$v2" "MemoryLimit" "MemoryLimit must not appear (use MemoryMax/High)"
check_present "$v2" "CPUWeight=%%USER_CGROUP_CPU_WEIGHT%%" "CPUWeight placeholder"
check_present "$v2" "IOWeight=%%USER_CGROUP_IO_WEIGHT%%" "IOWeight placeholder"
check_present "$v2" "%%USER_CGROUP_IO_DEVICE_LATENCY%%" "IODeviceLatencyTargetSec placeholder"
check_present "$v2" "TasksMax=%%USER_CGROUP_TASKS_MAX%%" "TasksMax placeholder"
check_present "$v2" "MemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M" "MemoryHigh placeholder"
check_present "$v2" "MemoryMax=%%USER_CGROUP_MEMORY_MAX%%M" "MemoryMax placeholder"
check_present "$v2" "CPUQuota=%%USER_CGROUP_CPU_QUOTA%%" "CPUQuota placeholder"

# v1 template rules
check_present "$v1" "BlockIOAccounting=yes" "BlockIOAccounting (v1)"
check_absent "$v1" "MemoryLimit" "MemoryLimit must not appear (even v1 here)"
check_present "$v1" "TasksMax=%%USER_CGROUP_TASKS_MAX%%" "TasksMax placeholder (v1)"
check_present "$v1" "CPUQuota=%%USER_CGROUP_CPU_QUOTA%%" "CPUQuota placeholder (v1)"

# SystemPrep path rules: default drop-ins under /etc (vendor paths must never be defaults).
system_prep="$ROOT_DIR/scripts/lib/update/systemPrep/systemdSlicesEnsure.php"
check_present "$system_prep" "pmssResolvePathFromEnv\\('PMSS_SYSTEMD_USER_SLICE_DIR', '/etc/systemd/system/user-\\.slice\\.d'\\)" \
	"PMSS_SYSTEMD_USER_SLICE_DIR default (/etc systemd user slice drop-in dir)"
check_absent "$system_prep" "pmssResolvePathFromEnv\\('PMSS_SYSTEMD_USER_SLICE_DIR', '/(usr/lib|lib)/systemd/" \
	"systemPrep uses vendor systemd drop-in dir; must write to /etc only"

pmss_testing_count_lint_finish "$fail" "cgroup-template-lint: issues found" "cgroup-template-lint: OK"
