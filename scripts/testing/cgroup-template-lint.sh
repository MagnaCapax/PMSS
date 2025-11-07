#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

v2="$ROOT_DIR/etc/seedbox/config/template.user-slice.v2.conf"
v1="$ROOT_DIR/etc/seedbox/config/template.user-slice.v1.conf"

fail=0

check_file_exists() {
  local f="$1"; [[ -f "$f" ]] || { echo "missing: $f" >&2; fail=1; }
}

check_absent() {
  local f="$1" pat="$2" why="$3"
  if grep -Eq "$pat" "$f"; then
    echo "$why in $f" >&2; fail=1
  fi
}

check_present() {
  local f="$1" pat="$2" why="$3"
  if ! grep -Eq "$pat" "$f"; then
    echo "$why not found in $f" >&2; fail=1
  fi
}

check_file_exists "$v2"
check_file_exists "$v1"

# v2 template rules
check_absent  "$v2" "BlockIOAccounting" "BlockIOAccounting must not appear (v2)"
check_absent  "$v2" "MemoryLimit" "MemoryLimit must not appear (use MemoryMax/High)"
check_present "$v2" "CPUWeight=%%USER_CPUWEIGHT%%" "CPUWeight placeholder"
check_present "$v2" "IOWeight=%%USER_IOWEIGHT%%" "IOWeight placeholder"
check_present "$v2" "TasksMax=%%TASKS_MAX%%" "TasksMax placeholder"
check_present "$v2" "MemoryHigh=%%USER_MEMORY_HIGH%%M" "MemoryHigh placeholder"
check_present "$v2" "MemoryMax=%%USER_MEMORY_MAX%%M" "MemoryMax placeholder"

# v1 template rules
check_present "$v1" "BlockIOAccounting=yes" "BlockIOAccounting (v1)"
check_absent  "$v1" "MemoryLimit" "MemoryLimit must not appear (even v1 here)"
check_present "$v1" "TasksMax=%%TASKS_MAX%%" "TasksMax placeholder (v1)"

# SystemPrep path rules: drop-ins under /etc not /usr/lib
if rg -n "/usr/lib/systemd/(system|user).*/user-.slice" "$ROOT_DIR/scripts/lib/update/systemPrep.php" >/dev/null 2>&1; then
  echo "systemPrep contains vendor drop-in paths; must write to /etc only" >&2; fail=1
fi

if [[ $fail -ne 0 ]]; then
  echo "cgroup-template-lint: issues found" >&2
  exit 1
fi
echo "cgroup-template-lint: OK"

