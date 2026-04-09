#!/usr/bin/env bash
# DEPRECATED (2026-02-15): Code-level-only QA replaced by full E2E QA.
# Full E2E QA runs externally. This script is retained for manual/fallback use only.
set -uo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
cd "$ROOT"
# shellcheck disable=SC1091
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_QA_CODEX_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_QA_CODEX_DEBUG "agentic-qa"
codex_set_error_trap "agentic-qa"

echo "[agentic-qa] DEPRECATED: Full E2E QA runs externally. Running code-level fallback." >&2
echo "[agentic-qa] start: fetching complete-verify issues and invoking assistant" >&1

# agentic-qa.sh — Fetch issues labeled complete-verify and launch an assistant
# to run the QA verification gauntlet on each.
#
# Usage:
#   development/agentic-qa.sh
#   development/agentic-qa.sh --exec 'codex exec'
#   development/agentic-qa.sh --autocommit
#   development/agentic-qa.sh --dry-run

usage() {
	cat <<EOF
Usage:
  development/agentic-qa.sh [options]

Purpose:
  Fetch issues labeled complete-verify and launch the assistant to verify
  each one through the code-level QA gauntlet. Closes verified issues,
  removes label from failures.

Options:
  --agent NAME    Assistant profile (default: codex)
  --exec CMD      Override assistant command line
  --autocommit    Enable autocommit rules (allows inline fixes for failures)
  --dry-run       Skip GitHub API; only assemble prompt scaffolding
  -h, --help      Show this help

Environment:
  PMSS_AGENTIC_DEFAULT_AGENT  Default agent when --agent is omitted
  PMSS_QA_CODEX_DEBUG=1       Enable bash -x tracing
EOF
}

ASSIST_DIR="$HERE/assistants"
default_agent="${PMSS_AGENTIC_DEFAULT_AGENT:-codex}"
OUTDIR="$(codex_make_temp_workspace pmss-qa-codex)"
QA_FILE="$OUTDIR/qa-context.txt"

# I6: Limit to 5 issues to avoid context exhaustion (each includes full diffs).
MAX_QA_ISSUES=5
# I7: Warn if context exceeds this size (bytes).
QA_CONTEXT_WARN=102400
# I13: Issue bodies excluded from QA context (security policy — Joukahainen Round 21).

agent=""
exec_cmd=""
dry_run=0
autocommit=0

while [[ $# -gt 0 ]]; do
	if codex_parse_launcher_option agent exec_cmd dry_run autocommit "$1" "${2:-}"; then
		shift "$CODEX_PARSE_SHIFT" || true
		continue
	fi
	case "$1" in
	-h | --help)
		usage
		exit 0
		;;
	*)
		echo "[agentic-qa] unknown option: $1" >&2
		exit 2
		;;
	esac
done

codex_prepare_agent_exec "$ASSIST_DIR" "$default_agent" agent exec_cmd || exit $?

echo "[agentic-qa] output directory: $OUTDIR" >&1

if [[ "$dry_run" == "1" ]]; then
	echo "[agentic-qa] dry-run: skipping GitHub API" >&1
	echo "(dry-run placeholder)" >"$QA_FILE"

	codex_run_prompt "$HERE" "$HERE/prompts/qa.txt" "$OUTDIR" "$ROOT" "$agent" "$exec_cmd" 1 "$autocommit" '' 0 --context "$QA_FILE"
	exit 0
fi

# F2: Pre-flight — verify gh is authenticated before proceeding.
if ! gh auth status >/dev/null 2>&1; then
	echo "[agentic-qa] ERROR: gh is not authenticated. Run 'gh auth login' first." >&2
	exit 1
fi

# Pre-flight: any complete-verify issues?
cv_count=$(gh issue list --state open --label "complete-verify" --limit 1 --json number --jq length 2>/dev/null || echo 0)
if [[ "$cv_count" -eq 0 ]]; then
	echo "[agentic-qa] No complete-verify issues. Skipping." >&1
	exit 0
fi

# Fetch complete-verify issues (exclude wontfix). I6: limit to $MAX_QA_ISSUES.
echo "[agentic-qa] fetching complete-verify issues (max $MAX_QA_ISSUES)..." >&1
issue_numbers=()
while IFS= read -r num; do
	[[ -n "$num" ]] && issue_numbers+=("$num")
done < <(gh issue list --state open --label "complete-verify" --limit "$MAX_QA_ISSUES" \
	--json number,title,labels \
	--jq '.[] | select((.labels | map(.name) | any(. == "wontfix")) | not) | .number' 2>/dev/null || true)

if [[ ${#issue_numbers[@]} -eq 0 ]]; then
	echo "[agentic-qa] No verifiable issues (all wontfix). Skipping." >&1
	exit 0
fi

echo "[agentic-qa] found ${#issue_numbers[@]} issue(s) to verify: ${issue_numbers[*]}" >&1

# Build QA context file with details for each issue + implementing commits.
: >"$QA_FILE"
for num in "${issue_numbers[@]}"; do
	echo "[agentic-qa] fetching details for #$num..." >&1
	{
		echo "======================================================================"
		echo "ISSUE #$num — PENDING VERIFICATION"
		echo "======================================================================"
		# SECURITY: Do NOT include issue body in QA context.
		# QA runs unsandboxed with SSH access. Issue bodies are untrusted external input.
		# PI in issue body + SSH access = RCE on dev infrastructure. (Joukahainen Round 21)
		# QA only needs: title + labels + implementing commit diffs.
		gh issue view "$num" --json title,labels \
			--jq '"Title: " + .title + "\nLabels: " + ([.labels[].name] | join(", ")) + "\n\n[Issue body excluded from QA context — security policy. Use implementing diffs below for verification.]"' 2>/dev/null || echo "(failed to fetch issue #$num)"
		echo ""

		# Find implementing commits (I8: search main only, not --all branches)
		echo "--- Implementing Commits ---"
		git log --oneline --grep="Refs #$num" -10 2>/dev/null || echo "(no commits found)"
		echo ""

		# Show diffs for implementing commits
		implementing_shas=()
		while IFS= read -r sha; do
			[[ -n "$sha" ]] && implementing_shas+=("$sha")
		done < <(git log --format='%H' --grep="Refs #$num" -5 2>/dev/null || true)

		for sha in "${implementing_shas[@]}"; do
			echo "--- Diff for ${sha:0:7} ---"
			git show --stat "$sha" 2>/dev/null | head -30 || true
			# I5: Show diff with line count + truncation indicator
			local_diff=$(git show --no-stat "$sha" 2>/dev/null || true)
			local_lines=$(printf '%s' "$local_diff" | wc -l)
			if [[ "$local_lines" -gt 200 ]]; then
				printf '%s' "$local_diff" | head -200
				echo ""
				echo "[DIFF TRUNCATED: showing 200 of $local_lines lines — read full with: git show $sha]"
			else
				printf '%s' "$local_diff"
			fi
			echo ""
		done

		echo ""
	} >>"$QA_FILE"
done

# Basic sanitization: strip common prompt injection markers.
if [[ -s "$QA_FILE" ]]; then
	sed -i \
		-e 's/<|endoftext|>//g' \
		-e 's/<|im_start|>//g' \
		-e 's/<|im_end|>//g' \
		-e 's/\[INST\]//g' \
		-e 's/\[\/INST\]//g' \
		"$QA_FILE" 2>/dev/null || true
fi

qa_bytes=$(wc -c <"$QA_FILE" | tr -d ' ')
echo "[agentic-qa] QA context: $qa_bytes bytes" >&1

# I7: Warn if context is very large (risk of context exhaustion).
if [[ "$qa_bytes" -gt "$QA_CONTEXT_WARN" ]]; then
	echo "[agentic-qa] WARNING: QA context is ${qa_bytes} bytes (>${QA_CONTEXT_WARN}). Risk of context exhaustion." >&1
fi

# Launch the assistant.
codex_run_prompt "$HERE" "$HERE/prompts/qa.txt" "$OUTDIR" "$ROOT" "$agent" "$exec_cmd" 0 "$autocommit" '' 1 --context "$QA_FILE"

echo "[agentic-qa] done" >&1
