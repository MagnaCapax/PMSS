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
#   made strict when enabled via PMSS_LINT_SHARP_STRICT=1.
#
# Exclusions:
# - tests/, vendor/, etc/skel/, scripts/lib/devristo/

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
STRICT="${PMSS_LINT_SHARP_STRICT:-1}"
PATTERN='rm\s+-rf|chmod\s+-R|chown\s+-R|chgrp\s+-R|\bmv\b'
VIOL=0
FATAL_VIOL=0

scanMatches() {
  local file="$1"
  grep -nE "$PATTERN" "$file" | cut -d: -f1-2 --output-delimiter=': ' || true
}

reportViolation() {
  local kind="$1" file="$2" raw="$3"
  echo "${kind} sharp edge: $file: ${raw}" >&2
  VIOL=$((VIOL+1))
}

reportFatal() {
  local file="$1" raw="$2"
  echo "FATAL sharp edge: $file: ${raw}" >&2
  FATAL_VIOL=$((FATAL_VIOL+1))
}

reportFatalMatches() {
  local file="$1" regex="$2" raw
  while IFS= read -r raw; do
    reportFatal "$file" "$raw"
  done < <(grep -nE "$regex" "$file" | cut -d: -f1-2 --output-delimiter=': ' || true)
}

phpScan() {
  local file raw
  while IFS= read -r -d '' file; do
    # grep candidate lines
    while IFS= read -r raw; do
      # ignore when inside runStep command string
      if grep -Eq "runStep\s*\(.*(${PATTERN}).*\)" <<<"$raw"; then
        continue
      fi
      reportViolation "PHP" "$file" "$raw"
    done < <(scanMatches "$file")
  done < <(find "$ROOT_DIR" -type f -name "*.php" \
           -not -path "*/.git/*" \
           -not -path "*/vendor/*" \
           -not -path "*/scripts/lib/tests/*" \
           -not -path "*/scripts/lib/devristo/*" \
           -not -path "*/etc/skel/*" -print0)
}

shScan() {
  local file
  while IFS= read -r -d '' file; do
    while IFS= read -r raw; do
      reportViolation "Shell" "$file" "$raw"
    done < <(scanMatches "$file")
  done < <(find "$ROOT_DIR" -type f -name "*.sh" \
           -not -path "*/.git/*" \
           -not -path "*/vendor/*" \
           -not -path "*/etc/skel/*" \
           -not -path "*/scripts/testing/sharp-edges-lint.sh" -print0)
}

# Fatal patterns – always fail CI regardless of STRICT mode.
# These represent catastrophic primitives that must never appear:
# - rm -rf / (root) or rm -rf /home (full home tree)
# - rm -rf /home/$var (dynamic home targets without safeguards)
# - rm -rf $var (unquoted variable argument in shell/strings)
fatalScan() {
  local file regex
  local fatalRegexes=(
    # rm -rf / (root) — spaces or end-of-line after slash
    'rm[[:space:]]+-rf[[:space:]]+/([[:space:];]|$)'

    # rm -rf /home (full home tree)
    'rm[[:space:]]+-rf[[:space:]]+/home([[:space:];]|$)'

    # rm -rf /home/ (full home tree with trailing slash or wildcard)
    'rm[[:space:]]+-rf[[:space:]]+/home/([[:space:];]|$|\*)'

    # rm -rf "/home" or "/home/..." (quoted home path)
    'rm[[:space:]]+-rf[[:space:]]+\"/home'

    # rm -rf /home/$var (dynamic home path without explicit quoting helper)
    'rm[[:space:]]+-rf[[:space:]]+/home/\$[A-Za-z_][A-Za-z0-9_]*'

    # rm -rf $var (unquoted variable argument)
    'rm[[:space:]]+-rf[[:space:]]+\$[A-Za-z_][A-Za-z0-9_]*([[:space:];]|$)'

    # rm -rf $HOME or paths derived directly from $HOME
    'rm[[:space:]]+-rf[[:space:]]+[$]HOME([/[:space:];]|$)'

    # rm -rf ~ or ~/...
    'rm[[:space:]]+-rf[[:space:]]+~([/[:space:];]|$)'
  )
  while IFS= read -r -d '' file; do
    for regex in "${fatalRegexes[@]}"; do
      reportFatalMatches "$file" "$regex"
    done
  done < <(find "$ROOT_DIR" -type f \
	           -not -path "*/.git/*" \
	           -not -path "*/vendor/*" \
	           -not -path "*/scripts/lib/tests/*" \
	           -not -path "*/scripts/lib/devristo/*" \
           -not -path "*/etc/skel/*" \
           -not -path "*/docs/*" \
           -not -path "*/scripts/testing/sharp-edges-lint.sh" -print0)
}

phpScan
shScan
fatalScan

if [[ $FATAL_VIOL -gt 0 ]]; then
  echo "sharp-edges lint: $FATAL_VIOL fatal issue(s) found" >&2
  exit 1
fi

if [[ $VIOL -gt 0 ]]; then
  echo "sharp-edges lint: $VIOL issue(s) found" >&2
  if [[ "$STRICT" == "1" ]]; then
    exit 1
  fi
fi
echo "sharp-edges lint: OK (advisory)"
