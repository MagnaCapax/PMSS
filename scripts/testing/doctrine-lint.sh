#!/usr/bin/env bash
# Be strict on unset vars and pipelines, but aggregate failures to report clearly.
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
FAIL=0

# 1) README should not link directly to docs/adr/ (use adr-list or browse dir)
check_no_readme_adr_links() {
  local hits
  if [[ -f "$ROOT_DIR/README.md" ]]; then
    hits=$(grep -n "docs/adr/" "$ROOT_DIR/README.md" || true)
    if [[ -n "$hits" ]]; then
      echo "doctrine lint: README links to docs/adr (forbidden). Use scripts/cli/adr-list.sh or directory browsing instead." >&2
      echo "$hits" >&2
      FAIL=$((FAIL+1))
    fi
  fi
}

check_no_readme_adr_links

# 2) ADR metadata: enforce H1 format and Category line
check_adr_metadata() {
  local adr_dir="$ROOT_DIR/docs/adr"
  [[ -d "$adr_dir" ]] || return 0
  local f title rest words chars cat
  while IFS= read -r -d '' f; do
    # H1 must be first matching line starting with '# ADR NNNN:'
    title=$(grep -m1 -E '^# ADR [0-9]{4}:' "$f" || true)
    if [[ -z "$title" ]]; then
      echo "doctrine lint: ADR missing H1 with number: $f" >&2
      FAIL=$((FAIL+1))
      continue
    fi
    # Extract text after colon and trim
    rest="${title#*:}"; rest="${rest## }"; rest="${rest%% }"
    # Count words and chars (minimums modest and non-intrusive)
    words=$(printf '%s\n' "$rest" | awk '{print NF}')
    chars=$(printf '%s' "$rest" | wc -c | awk '{print $1}')
    if (( words < 3 || chars < 20 )); then
      echo "doctrine lint: ADR title should be more descriptive (>=3 words and >=20 chars): $f" >&2
      echo "  -> $title" >&2
      FAIL=$((FAIL+1))
    fi
    # Category must exist near top and be one of allowed values
    cat=$(head -n 25 "$f" | grep -E '^Category:' | head -n1 | sed -E 's/^Category:[[:space:]]*//')
    if [[ -z "$cat" ]]; then
      echo "doctrine lint: ADR missing Category: line (architecture|security|data|domain): $f" >&2
      FAIL=$((FAIL+1))
      continue
    fi
    case "$cat" in
      architecture|security|data|domain) : ;;
      *)
        echo "doctrine lint: ADR Category invalid ('$cat'): $f (must be architecture|security|data|domain)" >&2
        FAIL=$((FAIL+1))
        ;;
    esac
    # Require at least a Decision marker somewhere
    if ! grep -Eq '^[#]{2}[[:space:]]+Decision\b|^[[:space:]]*Decision[[:space:]]*$' "$f"; then
      echo "doctrine lint: ADR should contain a 'Decision' section/marker: $f" >&2
      FAIL=$((FAIL+1))
    fi
  done < <(find "$adr_dir" -maxdepth 1 -type f -name "[0-9][0-9][0-9][0-9]-*.md" -print0)
}

check_adr_metadata

# 3) Guardrail: forbid ADR index docs (no docs/adr/index.md or README.md)
check_no_adr_index_docs() {
  local adr_dir="$ROOT_DIR/docs/adr"
  local bad=0
  if [[ -f "$adr_dir/index.md" ]]; then
    echo "doctrine lint: docs/adr/index.md is not allowed (avoid index docs)" >&2
    bad=1
  fi
  if [[ -f "$adr_dir/README.md" ]]; then
    echo "doctrine lint: docs/adr/README.md is not allowed (avoid index docs)" >&2
    bad=1
  fi
  if [[ $bad -eq 1 ]]; then
    FAIL=$((FAIL+1))
  fi
}

check_no_adr_index_docs

# 4) Guardrail: forbid any index files under docs/ (directory listings suffice)
check_no_docs_index_files() {
  local matches
  matches=$(find "$ROOT_DIR/docs" -type f \( -iname 'index.md' -o -iname 'index.html' \) -print 2>/dev/null || true)
  if [[ -n "$matches" ]]; then
    echo "doctrine lint: index files under docs/ are not allowed (use directory listing instead):" >&2
    echo "$matches" >&2
    FAIL=$((FAIL+1))
  fi
}

check_no_docs_index_files

if [[ $FAIL -gt 0 ]]; then
  echo "doctrine lint: $FAIL issue(s) found" >&2
  exit 1
fi
echo "doctrine lint: OK"

