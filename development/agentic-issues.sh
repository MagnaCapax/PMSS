#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
cd "$ROOT"
# shellcheck disable=SC1091
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_ISSUES_CODEX_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_ISSUES_CODEX_DEBUG "agentic-issues"
codex_set_error_trap "agentic-issues"

echo "[agentic-issues] start: fetching open issues and invoking assistant" >&1

# agentic-issues.sh — Fetch open GitHub issues and launch an assistant to implement them.
#
# Usage:
#   development/agentic-issues.sh
#   development/agentic-issues.sh --max-issues 5
#   development/agentic-issues.sh --exec 'codex exec'
#   development/agentic-issues.sh --autocommit
#   development/agentic-issues.sh --dry-run

usage() {
	cat <<EOF
Usage:
  development/agentic-issues.sh [options]

Purpose:
  Fetch open GitHub issues (excluding complete-verify, wontfix) and launch
  the assistant to implement tractable ones.

Options:
  --max-issues N  Maximum issues to fetch (default: ${max_issues})
  --agent NAME    Assistant profile (default: codex)
  --exec CMD      Override assistant command line
  --autocommit    Enable autocommit rules in the prompt (operator-approved)
  --dry-run       Skip GitHub API; only assemble prompt scaffolding
  -h, --help      Show this help

Environment:
  PMSS_AGENTIC_DEFAULT_AGENT  Default agent when --agent is omitted
  PMSS_ISSUES_CODEX_DEBUG=1   Enable bash -x tracing
EOF
}

TMP="${TMPDIR:-/tmp}"
ASSIST_DIR="$HERE/assistants"
default_agent="${PMSS_AGENTIC_DEFAULT_AGENT:-codex}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-issues-codex-XXXXXXXX")"
ISSUES_FILE="$OUTDIR/issues-context.txt"

max_issues=5
agent=""
exec_cmd=""
dry_run=0
autocommit=0

while [[ $# -gt 0 ]]; do
	case "$1" in
	--max-issues)
		max_issues=${2:-5}
		shift 2 || true
		;;
	--agent)
		agent=${2:-}
		shift 2 || true
		;;
	--agent=*)
		agent=${1#--agent=}
		shift || true
		;;
	--exec)
		exec_cmd=${2:-}
		shift 2 || true
		;;
	--autocommit)
		autocommit=1
		shift || true
		;;
	--dry-run)
		dry_run=1
		shift || true
		;;
	-h | --help)
		usage
		exit 0
		;;
	*)
		echo "[agentic-issues] unknown option: $1" >&2
		exit 2
		;;
	esac
done

if [[ -z "$agent" ]]; then
	agent="$default_agent"
fi

exec_cmd="$(codex_resolve_exec_cmd "$ASSIST_DIR" "$agent" "$exec_cmd")" || exit $?

echo "[agentic-issues] output directory: $OUTDIR" >&1

if [[ "$dry_run" == "1" ]]; then
	echo "[agentic-issues] dry-run: skipping GitHub API" >&1
	echo "[agentic-issues] dry-run: would fetch $max_issues open issues" >&1
	echo "(dry-run placeholder)" >"$ISSUES_FILE"

	codex_args=(run --prompt-file "$HERE/prompts/issues.txt" --outdir "$OUTDIR" --context "$ISSUES_FILE" --dry-run)
	[[ -n "$exec_cmd" ]] && codex_args+=(--exec "$exec_cmd")
	[[ "$autocommit" == "1" ]] && codex_args+=(--autocommit)

	bash "$HERE/codex-run.sh" "${codex_args[@]}"
	exit 0
fi

# Pre-flight: any open issues?
issue_count=$(gh issue list --state open --limit 1 --json number --jq length 2>/dev/null || echo 0)
if [[ "$issue_count" -eq 0 ]]; then
	echo "[agentic-issues] No open issues. Skipping." >&1
	exit 0
fi

# Fetch open issues excluding complete-verify and wontfix labels.
# Get basic list first, then fetch details for each.
echo "[agentic-issues] fetching up to $max_issues open issues..." >&1
issue_numbers=()
while IFS= read -r num; do
	[[ -n "$num" ]] && issue_numbers+=("$num")
done < <(gh issue list --state open --limit "$max_issues" \
	--json number,title,labels \
	--jq '.[] | select((.labels | map(.name) | any(. == "complete-verify" or . == "wontfix")) | not) | .number' 2>/dev/null || true)

if [[ ${#issue_numbers[@]} -eq 0 ]]; then
	echo "[agentic-issues] No tractable issues (all labeled complete-verify or wontfix). Skipping." >&1
	exit 0
fi

echo "[agentic-issues] found ${#issue_numbers[@]} issue(s) (pre-gate): ${issue_numbers[*]}" >&1

# --- Author Gate (security: public repo, only org issues auto-approved) ---
# MagnaCapax issues: auto-approved.
# All others: require "approved" label AND a comment from MagnaCapax.
# Anti-bait-and-switch: reject if issue was edited after the approval comment.
APPROVED_AUTHORS="MagnaCapax"

check_issue_approved() {
	local num="$1"
	local author
	author=$(gh api "repos/MagnaCapax/PMSS/issues/$num" --jq '.user.login' 2>/dev/null) || return 1

	# Auto-approve known authors
	local a
	for a in $APPROVED_AUTHORS; do
		[[ "$author" == "$a" ]] && return 0
	done

	# External: require "approved" label
	local has_label
	has_label=$(gh api "repos/MagnaCapax/PMSS/issues/$num" --jq '[.labels[].name] | any(. == "approved")' 2>/dev/null)
	if [[ "$has_label" != "true" ]]; then
		echo "[agentic-issues] GATE: #$num rejected — no 'approved' label (author: $author)" >&1
		return 1
	fi

	# External: require comment from approved author
	local approved_comments
	approved_comments=$(gh api "repos/MagnaCapax/PMSS/issues/$num/comments" \
		--jq '[.[] | select(.user.login == "MagnaCapax")] | length' 2>/dev/null || echo 0)
	if [[ "$approved_comments" -eq 0 ]]; then
		echo "[agentic-issues] GATE: #$num rejected — no MagnaCapax comment (author: $author)" >&1
		return 1
	fi

	# Anti-bait-and-switch: issue must not be edited after latest approval comment
	local issue_updated
	issue_updated=$(gh api "repos/MagnaCapax/PMSS/issues/$num" --jq '.updated_at' 2>/dev/null)
	local approval_ts
	approval_ts=$(gh api "repos/MagnaCapax/PMSS/issues/$num/comments" \
		--jq '[.[] | select(.user.login == "MagnaCapax")] | sort_by(.created_at) | last | .created_at' 2>/dev/null)
	if [[ -n "$approval_ts" && "$issue_updated" > "$approval_ts" ]]; then
		echo "[agentic-issues] GATE: #$num rejected — edited after approval (bait-and-switch protection)" >&1
		return 1
	fi

	return 0
}

# Apply author gate
approved_issues=()
for num in "${issue_numbers[@]}"; do
	if check_issue_approved "$num"; then
		approved_issues+=("$num")
		echo "[agentic-issues] GATE: #$num approved" >&1
	fi
done

if [[ ${#approved_issues[@]} -eq 0 ]]; then
	echo "[agentic-issues] No approved issues after gate. Skipping." >&1
	exit 0
fi

issue_numbers=("${approved_issues[@]}")
echo "[agentic-issues] ${#issue_numbers[@]} issue(s) passed gate: ${issue_numbers[*]}" >&1

# Build issue context file with details for each issue.
: >"$ISSUES_FILE"
for num in "${issue_numbers[@]}"; do
	echo "[agentic-issues] fetching details for #$num..." >&1
	{
		echo "======================================================================"
		echo "ISSUE #$num"
		echo "======================================================================"
		# Get title, labels, and body
		gh issue view "$num" --json title,labels,body \
			--jq '"Title: " + .title + "\nLabels: " + ([.labels[].name] | join(", ")) + "\n\nBody:\n" + .body' 2>/dev/null || echo "(failed to fetch issue #$num)"
		echo ""
		echo ""
	} >>"$ISSUES_FILE"
done

# Basic sanitization: strip common prompt injection markers.
if [[ -s "$ISSUES_FILE" ]]; then
	# Remove known prompt injection patterns (best-effort, not exhaustive).
	sed -i \
		-e 's/<|endoftext|>//g' \
		-e 's/<|im_start|>//g' \
		-e 's/<|im_end|>//g' \
		-e 's/\[INST\]//g' \
		-e 's/\[\/INST\]//g' \
		"$ISSUES_FILE" 2>/dev/null || true
fi

issue_bytes=$(wc -c <"$ISSUES_FILE" | tr -d ' ')
echo "[agentic-issues] issue context: $issue_bytes bytes" >&1

# Launch the assistant.
codex_args=(run --prompt-file "$HERE/prompts/issues.txt" --outdir "$OUTDIR" --context "$ISSUES_FILE")
[[ -f "$ROOT/AGENTS.${agent}.md" ]] && codex_args+=(--context "$ROOT/AGENTS.${agent}.md")
[[ -f "$ROOT/AGENTS.${agent}.local.md" ]] && codex_args+=(--context "$ROOT/AGENTS.${agent}.local.md")
[[ -n "$exec_cmd" ]] && codex_args+=(--exec "$exec_cmd")
[[ "$dry_run" == "1" ]] && codex_args+=(--dry-run)
[[ "$autocommit" == "1" ]] && codex_args+=(--autocommit)

bash "$HERE/codex-run.sh" "${codex_args[@]}"

echo "[agentic-issues] done" >&1
