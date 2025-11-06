#!/usr/bin/env bash
set -euo pipefail

echo "[bash-syntax]" >&2
find . -type f -name "*.sh" \
  -not -path "./vendor/*" \
  -not -path "./etc/skel/www/*" \
  -print0 | xargs -0 -I{} bash -n {}

if command -v shellcheck >/dev/null 2>&1; then
  echo "[shellcheck]" >&2
  # Exclude vendor and frozen skel/www content per AGENTS.md
  find . -type f -name "*.sh" \
    -not -path "./vendor/*" \
    -not -path "./etc/skel/www/*" \
    -print0 | xargs -0 shellcheck || true
else
  echo "(shellcheck not installed; skipping)" >&2
fi

if command -v shfmt >/dev/null 2>&1; then
  echo "[shfmt check]" >&2
  # Check only filtered files, not entire tree
  find . -type f -name "*.sh" \
    -not -path "./vendor/*" \
    -not -path "./etc/skel/www/*" \
    -print0 | xargs -0 shfmt -d || true
else
  echo "(shfmt not installed; skipping)" >&2
fi

echo "OK: bash checks"
