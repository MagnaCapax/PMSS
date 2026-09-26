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

# Lua interpreter: needed to execute the mod_magnet panel session gate in tests
# (any of lua5.4/lua5.3/lua5.1/lua). Without it PanelSessionGateExecutionTest skips.
if have lua5.4 || have lua5.3 || have lua5.1 || have lua; then
	echo "  • lua (present; gate-execution test will run)"
else
	echo "  • lua (missing; gate-execution test will skip)"
fi

exit 0
