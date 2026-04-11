#!/usr/bin/env bash
set -euo pipefail
# Docblock linter for classes and public methods (PMSS first-party code)
# Enforces that:
# - Every class has a preceding docblock (/** ... */) with a description line.
# - Every public method has a preceding docblock with:
#   * at least one non-@ description line
#   * an @return tag
#   * if parameters exist, at least one @param tag
#
# Scope (required CI gate): updater libraries plus top-level shared libs.
#  - Required: all PHP files under scripts/lib/update/**.
#  - Required: first-party shared helpers in scripts/lib/*.php (maxdepth 1).
#  - This reflects the staged rollout in tests/TODO.md: updater libraries were
#    enforced first, then core shared helpers; broader tree coverage remains
#    advisory/opt‑in for now.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"
VIOLATIONS=0

scan_file() {
  local file="$1"
  local rel="${file#"$ROOT_DIR"/}"
  if command -v git >/dev/null 2>&1 && ! git -C "$ROOT_DIR" ls-files --error-unmatch "$rel" >/dev/null 2>&1; then
    return
  fi
  awk -v FILE="$file" '
    BEGIN { in_doc=0; pending_doc=""; violations=0; min_words=6; min_chars=30 }
    function has_description(doc){ return doc ~ /\*[ \t]*[^@][A-Za-z0-9]/ }
    function has_return(doc){ return doc ~ /@return[ \t]+/ }
    function has_param(doc){ return doc ~ /@param[ \t]+/ }
    function description_text(doc,   out, n, i, line){
      out=""; n=split(doc, lines, "\n");
      for(i=1;i<=n;i++){
        line=lines[i];
        if (line ~ /@|\*\//) continue;
        if (line ~ /\*/) { sub(/^.*\*/, "", line); }
        gsub(/^\s+|\s+$/, "", line);
        if (line != "") out = out (out==""?"":" ") line;
      }
      return out;
    }
    function desc_ok(doc,   txt, words){
      txt = description_text(doc);
      gsub(/\s+/, " ", txt);
      split(txt, arr, /[[:space:]]+/);
      words = (txt==""?0:length(arr));
      return (length(txt) >= min_chars && words >= min_words);
    }
    {
      line=$0
      if (line ~ /\/\*\*/) { in_doc=1; current_doc=line"\n"; next }
      if (in_doc==1) {
        current_doc = current_doc line "\n"
        if (line ~ /\*\//) { in_doc=0; pending_doc=current_doc }
        next
      }
      if (line ~ /^(final[ \t]+)?class[ \t]+[A-Za-z_][A-Za-z0-9_]*/) {
        if (pending_doc == "") {
          printf("docblock violation (class): %s:%d\n", FILE, NR) >> "/dev/stderr"; violations++
        } else if (!desc_ok(pending_doc)) {
          printf("docblock violation (class description too short): %s:%d\n", FILE, NR) >> "/dev/stderr"; violations++
        }
        pending_doc=""; next
      }
      if (line ~ /^[ \t]*public[ \t]+function[ \t]+[A-Za-z_][A-Za-z0-9_]*\(/) {
        sig=line
        if (pending_doc == "") {
          printf("docblock violation (method): %s:%d\n", FILE, NR) >> "/dev/stderr"; violations++
        } else {
          if (!has_description(pending_doc)) {
            printf("docblock violation (method description): %s:%d\n", FILE, NR) >> "/dev/stderr"; violations++
          }
          if (!desc_ok(pending_doc)) {
            printf("docblock violation (method description too short): %s:%d\n", FILE, NR) >> "/dev/stderr"; violations++
          }
          if (!has_return(pending_doc)) {
            printf("docblock violation (method @return): %s:%d\n", FILE, NR) >> "/dev/stderr"; violations++
          }
          if (sig !~ /\([[:space:]]*\)/ && !has_param(pending_doc)) {
            printf("docblock violation (method @param): %s:%d\n", FILE, NR) >> "/dev/stderr"; violations++
          }
        }
        pending_doc=""; next
      }
    }
    END { if (violations>0) exit 1; }
  ' "$file" || VIOLATIONS=$((VIOLATIONS+1))
}

# Collect target files (updater libraries only)
mapfile -t FILES < <(
  find "$ROOT_DIR/scripts/lib/update" -type f -name "*.php" | sort -u
  find "$ROOT_DIR/scripts/lib" -maxdepth 1 -type f -name "*.php" | sort -u
)

for f in "${FILES[@]}"; do
  scan_file "$f"
done

if [[ $VIOLATIONS -gt 0 ]]; then
  echo "docblock lint: $VIOLATIONS violation(s) found" >&2
  exit 1
fi
echo "docblock lint: OK"
