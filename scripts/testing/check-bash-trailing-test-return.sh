#!/usr/bin/env bash
set -euo pipefail

# check-bash-trailing-test-return.sh - flag bash functions that can return rc=1
# solely because the final command is a conditional test or test-command chain.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

VIOL=0

scan_file() {
	local file="$1"
	awk -v file="$file" '
    function trim(value) {
      sub(/^[[:space:]]+/, "", value)
      sub(/[[:space:]]+$/, "", value)
      return value
    }

    function count_char(value, char, count, i) {
      count = 0
      for (i = 1; i <= length(value); i++) {
        if (substr(value, i, 1) == char) {
          count++
        }
      }
      return count
    }

    function strip_inline_comment(value) {
      sub(/[[:space:]]+#.*/, "", value)
      return value
    }

    function record_statement(value, lineno, parts, count, i, stmt) {
      value = strip_inline_comment(value)
      gsub(/\}[[:space:]]*$/, "", value)
      count = split(value, parts, ";")
      for (i = 1; i <= count; i++) {
        stmt = trim(parts[i])
        if (stmt == "" || stmt == "{" || stmt == "}") {
          continue
        }
        last_stmt = stmt
        last_line = lineno
      }
    }

    function test_tail(value) {
      return value ~ /^(\[\[.*\]\]|\(\(.*\)\))[[:space:]]*(&&|\|\|)[[:space:]]*[^&|;]+$/ ||
        value ~ /^(\[\[.*\]\]|\(\(.*\)\))[[:space:]]*$/
    }

    function predicate_function(name) {
      return name ~ /^(is|has|can|should)_[A-Za-z0-9_]+$/
    }

    function finish_function() {
      if (!predicate_function(function_name) && last_stmt != "" && test_tail(last_stmt)) {
        printf "%s:%d:bash trailing test return: function %s ends with `%s`; add an explicit successful terminator when rc=1 is not intended\n", file, last_line, function_name, last_stmt > "/dev/stderr"
        violations++
      }
      in_function = 0
      brace_depth = 0
      function_name = ""
      last_stmt = ""
      last_line = 0
    }

    function start_function(name, line, lineno, open_pos, body) {
      in_function = 1
      function_name = name
      last_stmt = ""
      last_line = 0
      open_pos = index(line, "{")
      body = substr(line, open_pos + 1)
      brace_depth = 1 + count_char(body, "{") - count_char(body, "}")
      record_statement(body, lineno)
      if (brace_depth <= 0) {
        finish_function()
      }
    }

    {
      raw = $0
      stripped = trim(raw)

      if (!in_function) {
        if (stripped == "" || stripped ~ /^#/) {
          next
        }
        if (match(stripped, /^([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*\(\)[[:space:]]*\{/)) {
          function_name = stripped
          sub(/[[:space:]]*\(\)[[:space:]]*\{.*/, "", function_name)
          start_function(function_name, raw, NR)
          next
        }
        if (match(stripped, /^function[[:space:]]+([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*(\(\))?[[:space:]]*\{/)) {
          function_name = stripped
          sub(/^function[[:space:]]+/, "", function_name)
          sub(/[[:space:]]*(\(\))?[[:space:]]*\{.*/, "", function_name)
          start_function(function_name, raw, NR)
          next
        }
        next
      }

      brace_depth += count_char(raw, "{") - count_char(raw, "}")
      record_statement(raw, NR)
      if (brace_depth <= 0) {
        finish_function()
      }
    }

    END {
      exit violations > 0
    }
  ' "$file" || VIOL=$((VIOL + 1))
}

while IFS= read -r -d '' file; do
	case "$file" in
	# The development launchers are outside this issue session's edit scope, so
	# keep this fatal gate focused on PMSS runtime and test-maintained scripts.
	"$ROOT_DIR/development/"*) continue ;;
	esac
	scan_file "$file"
done < <(pmss_testing_find_bash_files "$ROOT_DIR")

pmss_testing_count_lint_finish "$VIOL" "bash trailing test return lint: $VIOL file(s) with issue(s)" "bash trailing test return lint: OK"
