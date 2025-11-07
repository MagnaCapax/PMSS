#!/usr/bin/env bash
set -euo pipefail

# camelCase filename lint for PMSS first-party PHP files
# - Filenames must be camelCase starting lowercase: ^[a-z][a-zA-Z0-9]*\.php$
# - Scope excludes third-party and web skel: etc/skel/**, scripts/lib/devristo/**, tests/**
# - Focus on ops/update code paths where filenames are historically lowercase

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
VIOLATIONS=0

is_camel_file() {
  local base="$1"
  [[ "$base" =~ ^[a-z][a-zA-Z0-9]*\.php$ ]]
}

check_tree() {
  local dir="$1"
  [[ -d "$dir" ]] || return 0
  while IFS= read -r -d '' f; do
    base="$(basename "$f")"
    if ! is_camel_file "$base"; then
      echo "filename violation: $f" >&2
      VIOLATIONS=$((VIOLATIONS+1))
    fi
  done < <(find "$dir" -type f -name "*.php" -print0)
}

# Enforce for selected first-party directories
check_tree "$ROOT_DIR/scripts"
check_tree "$ROOT_DIR/scripts/util"
check_tree "$ROOT_DIR/scripts/cron"
check_tree "$ROOT_DIR/scripts/lib/update"
check_tree "$ROOT_DIR/scripts/lib/network"
check_tree "$ROOT_DIR/scripts/lib/traffic"

# Explicitly skip: tests, devristo, user class libs (mixed historical casing), web skel

if [[ $VIOLATIONS -gt 0 ]]; then
  echo "camelCase filename lint: $VIOLATIONS violation(s) found" >&2
  exit 1
fi
echo "camelCase filename lint: OK"

