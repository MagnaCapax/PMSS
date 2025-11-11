#!/usr/bin/env bash
set -euo pipefail

# adr-list.sh — list and filter ADRs for quick discovery
#
# Usage:
#   scripts/cli/adr-list.sh [--category architecture|security|data|domain] [--paths] [keywords...]
#
# Behavior:
# - Prints lines: "NNNN Title — [category] — path"
# - --paths: print only file paths
# - keywords: case-insensitive filter across filename slug, title, and optional Tags line

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ADR_DIR="$ROOT_DIR/docs/adr"
CATEGORY=""
ONLY_PATHS=0
KEYWORDS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --category)
      CATEGORY="${2:-}"; shift 2 ;;
    --paths)
      ONLY_PATHS=1; shift ;;
    --*) echo "Unknown option: $1" >&2; exit 2 ;;
    *) KEYWORDS+=("$1"); shift ;;
  esac
done

shopt -s nullglob
# Build the list via compgen to avoid shfmt confusion with [0-9] in array literals
mapfile -t files < <(compgen -G "$ADR_DIR"/[0-9][0-9][0-9][0-9]-*.md | sort)

match_keywords() {
  local hay="$1"; shift
  local k
  for k in "$@"; do
    [[ -z "$k" ]] && continue
    if ! printf '%s' "$hay" | grep -qi -- "$k"; then
      return 1
    fi
  done
  return 0
}

for f in "${files[@]}"; do
  [[ -f "$f" ]] || continue
  bn=$(basename "$f")
  num=${bn%%-*}
  slug=${bn#*-}; slug=${slug%.md}
  title=$(grep -m1 -E '^# ADR [0-9]{4}:' "$f" | sed -E 's/^# ADR [0-9]{4}:[[:space:]]*//')
  cat=$(head -n 25 "$f" | grep -E '^Category:' | head -n1 | sed -E 's/^Category:[[:space:]]*//')
  tags=$(head -n 40 "$f" | grep -E '^Tags:' | head -n1 | sed -E 's/^Tags:[[:space:]]*//')

  # Category filter
  if [[ -n "$CATEGORY" && "$cat" != "$CATEGORY" ]]; then
    continue
  fi

  # Build haystack for keyword search
  hay="$slug $title $tags"
  if [[ ${#KEYWORDS[@]} -gt 0 ]]; then
    match_keywords "$hay" "${KEYWORDS[@]}" || continue
  fi

  if [[ $ONLY_PATHS -eq 1 ]]; then
    printf '%s\n' "$f"
  else
    printf '%s %s — [%s] — %s\n' "$num" "${title:-<no title>}" "${cat:-unknown}" "$f"
  fi
done
