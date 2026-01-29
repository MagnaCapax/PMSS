#!/usr/bin/env bash
set -euo pipefail

# net-edges-lint.sh — advisory check for raw network calls without wrappers
# Flags: curl, wget (and basic sockets: nc, telnet) used directly.
# Exclusions: vendor/, tests/, etc/skel/, scripts/lib/devristo/
# For PHP: allow when used inside runStep(..., "curl ...") etc.

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
STRICT="${PMSS_LINT_NET_STRICT:-0}"
PATTERN='curl\b|wget\b|\bnc\b|telnet\b'
VIOL=0

scanMatches() {
  local file="$1"
  grep -nE "$PATTERN" "$file" | cut -d: -f1-2 --output-delimiter=': ' || true
}

phpScan() {
  local file raw
  while IFS= read -r -d '' file; do
    while IFS= read -r raw; do
      # Permit when inside runStep command string
      if grep -Eq "runStep\s*\(.*(curl|wget|nc|telnet).*\)" <<<"$raw"; then
        continue
      fi
      echo "PHP net edge: $file: ${raw}" >&2
      VIOL=$((VIOL+1))
    done < <(scanMatches "$file")
  done < <(find "$ROOT_DIR" -type f -name "*.php" \
           -not -path "*/.git/*" \
           -not -path "*/vendor/*" \
           -not -path "*/scripts/lib/tests/*" \
           -not -path "*/scripts/lib/devristo/*" \
           -not -path "*/etc/skel/*" -print0)
}

shScan() {
  local file raw
  while IFS= read -r -d '' file; do
    while IFS= read -r raw; do
      echo "Shell net edge: $file: ${raw}" >&2
      VIOL=$((VIOL+1))
    done < <(scanMatches "$file")
  done < <(find "$ROOT_DIR" -type f -name "*.sh" \
           -not -path "*/.git/*" \
           -not -path "*/vendor/*" \
           -not -path "*/etc/skel/*" \
           -not -path "*/scripts/testing/net-edges-lint.sh" -print0)
}

phpScan
shScan

if [[ $VIOL -gt 0 ]]; then
  echo "net-edges lint: $VIOL issue(s) found" >&2
  if [[ "$STRICT" == "1" ]]; then
    exit 1
  fi
fi
echo "net-edges lint: OK (advisory)"
