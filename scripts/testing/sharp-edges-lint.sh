#!/usr/bin/env bash
set -euo pipefail

# sharp-edges-lint.sh — flag raw destructive commands used without wrappers
#
# Intent:
# - Detect potentially destructive primitives used directly:
#   * rm -rf, mv, chmod -R, chown -R, chgrp -R
# - For PHP: allow when the command string is passed via runStep(...,
#   "<cmd>") — these are already captured by our logging/JSON wrappers.
# - For shell: flag direct uses; this is advisory in mixed repos but can be
#   made strict when enabled via PMSS_LINT_SHARP=1.
#
# Exclusions:
# - tests/, vendor/, etc/skel/, scripts/lib/devristo/

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
STRICT="${PMSS_LINT_SHARP_STRICT:-1}"
PATTERN='rm\s+-rf|chmod\s+-R|chown\s+-R|chgrp\s+-R|\bmv\b'
VIOL=0

scan_matches() {
  local file="$1"
  grep -nE "$PATTERN" "$file" | cut -d: -f1-2 --output-delimiter=': ' || true
}

report_violation() {
  local kind="$1" file="$2" raw="$3"
  echo "${kind} sharp edge: $file: ${raw}" >&2
  VIOL=$((VIOL+1))
}

php_scan() {
  local file raw
  while IFS= read -r -d '' file; do
    # grep candidate lines
    while IFS= read -r raw; do
      # ignore when inside runStep command string
      if grep -Eq "runStep\s*\(.*(${PATTERN}).*\)" <<<"$raw"; then
        continue
      fi
      report_violation "PHP" "$file" "$raw"
    done < <(scan_matches "$file")
  done < <(find "$ROOT_DIR" -type f -name "*.php" \
           -not -path "*/vendor/*" \
           -not -path "*/scripts/lib/tests/*" \
           -not -path "*/scripts/lib/devristo/*" \
           -not -path "*/etc/skel/*" -print0)
}

sh_scan() {
  local file
  while IFS= read -r -d '' file; do
    while IFS= read -r raw; do
      report_violation "Shell" "$file" "$raw"
    done < <(scan_matches "$file")
  done < <(find "$ROOT_DIR" -type f -name "*.sh" \
           -not -path "*/vendor/*" \
           -not -path "*/etc/skel/*" -print0)
}

php_scan
sh_scan

if [[ $VIOL -gt 0 ]]; then
  echo "sharp-edges lint: $VIOL issue(s) found" >&2
  if [[ "$STRICT" == "1" ]]; then
    exit 1
  fi
fi
echo "sharp-edges lint: OK (advisory)"
