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

# Required BY THE SUITE rather than by this script: without these the hermetic
# development tests fail for reasons that have nothing to do with the code under
# test, which reads as a broken checkout to a new contributor.
if have curl; then echo "  ✓ curl"; else echo "  ✗ curl (required by the filemanager remote-URL tests)"; fi
if php -r 'exit(class_exists("DOMDocument") ? 0 : 1);' 2>/dev/null; then
	echo "  ✓ php DOMDocument"
else
	echo "  ✗ php DOMDocument (install php-xml; welcome-announcement and D-Bus render tests need it)"
fi

# Several tests assert that an unreadable file stays unreadable, or that a
# wrong-owner artifact is refused. Neither can hold for uid 0, so a root run
# reports failures the same suite passes as an ordinary user.
if [ "$(id -u)" = "0" ]; then
	echo "  ! running as root: permission-refusal tests cannot pass; run the suite as an ordinary user"
fi

exit 0
