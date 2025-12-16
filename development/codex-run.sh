#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_CODEX_RUN_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_CODEX_RUN_DEBUG "codex-run"
codex_set_error_trap "codex-run"

TMP="${TMPDIR:-/tmp}"

usage() {
	sed -n '1,120p' "$0"
}

# Usage:
#   development/codex-run.sh run --prompt-file development/prompts/codex.txt
#   development/codex-run.sh run --prompt-file development/prompts/codex.txt --dry-run
#   development/codex-run.sh run --prompt-file development/prompts/codex.txt --exec 'codex'
#   development/codex-run.sh run --prompt-file development/prompts/refactor.txt --autocommit

cmd=${1:-}
shift || true

case "$cmd" in
run) ;;
-h | --help | "")
	usage
	exit 0
	;;
*)
	echo "[codex-run] unknown command: $cmd" >&2
	exit 2
	;;
esac

prompt_file=""
custom_prompt=""
exec_cmd="codex"
outdir=""
dry_run=0
autocommit=0
declare -a extra_context=()

while [[ $# -gt 0 ]]; do
	case "$1" in
	--prompt-file)
		prompt_file=${2:-}
		shift 2 || true
		;;
	--prompt)
		custom_prompt=${2:-}
		shift 2 || true
		;;
	--exec)
		exec_cmd=${2:-}
		shift 2 || true
		;;
	--outdir)
		outdir=${2:-}
		shift 2 || true
		;;
	--context)
		extra_context+=("${2:-}")
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
		echo "[codex-run] unknown option: $1" >&2
		exit 2
		;;
	esac
done

if [[ -z "$outdir" ]]; then
	outdir="$(mktemp -d "${TMP%/}/pmss-codex-run-XXXXXXXX")"
fi
prompt_out="$outdir/prompt.txt"

if [[ -z "$custom_prompt" ]]; then
	codex_require_nonempty_file "$prompt_file" "[codex-run] missing/empty --prompt-file"
	prompt_text="$(cat "$prompt_file")"
else
	prompt_text="$custom_prompt"
fi

codex_write_prompt "$prompt_out" "$ROOT/.codex-prompt" "$prompt_text" "${extra_context[@]}"

if [[ "$autocommit" == "1" ]]; then
	cat <<'EOF' >>"$prompt_out"

Autocommit Mode (operator-approved only)

If (and only if) the operator explicitly enables Autocommit Mode for this run:

- Commits are allowed ONLY when ALL are true:
  - Compression gates passed (runtime LOC not up; concept count not up).
  - All required verification commands in the prompt have passed.
  - `git status --porcelain` shows only the intended changes.
  - The summary includes the scorecard (runtime LOC delta, concepts delta, helpers pruned).

- Commit format (required):
  - Subject: "refactor(compression): <subsystem> — runtime -X LOC, concepts -Y"
  - Body must include:
    - invariants relied on,
    - commands run,
    - what was deleted.

- Stop conditions (required):
  - If two consecutive runs cannot find a target that satisfies gates, STOP the loop.
  - If any gate fails, STOP (do not attempt “fix forward” automatically).

---- ****  Autocommit is explicitly operator approved with arguments, never the defualt.
EOF
fi

prompt_bytes=$(wc -c <"$prompt_out" | tr -d ' ')
prompt_lines=$(wc -l <"$prompt_out" | tr -d ' ')
echo "[codex-run] prompt written: $prompt_out (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

if [[ "$dry_run" == "1" ]]; then
	echo "[codex-run] dry-run: not invoking assistant (--dry-run)" >&1
	exit 0
fi

codex_invoke "$exec_cmd" "$prompt_out"
codex_scan_git_diff_for_dangers "$ROOT"
