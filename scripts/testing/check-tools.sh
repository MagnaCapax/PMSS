#!/usr/bin/env bash
set -euo pipefail

# check-tools.sh — Preflight check for optional tools
# Does not fail the build; prints status so developers know what's available.

have() { command -v "$1" >/dev/null 2>&1; }

echo "[tools] Preflight"

for t in php bash grep sed awk; do
	if have "$t"; then echo "  ✓ $t"; else echo "  ✗ $t (required)"; fi
done

# Optional helpers
for t in rg shellcheck shfmt phpstan; do
	if have "$t"; then echo "  • $t (optional)"; else echo "  • $t (missing, optional)"; fi
done

exit 0
