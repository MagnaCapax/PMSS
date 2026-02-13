#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
# shellcheck disable=SC1091
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_CODEX_RUN_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_CODEX_RUN_DEBUG "codex-run"
codex_set_error_trap "codex-run"

TMP="${TMPDIR:-/tmp}"

# Colorize a single line when stdout is a TTY.
codex_color_line() {
	local color="$1"
	shift || true
	local msg="$*"
	if [[ -t 1 ]]; then
		printf '\033[%sm%s\033[0m\n' "$color" "$msg"
	else
		printf '%s\n' "$msg"
	fi
}

# Render a dry-run preview without inlining full prompt text.
codex_exec_preview() {
	local exec_cmd="$1" prompt_file="$2"
	local exec_cmd_final inline_prompt prompt_file_q mode
	exec_cmd_final="$exec_cmd"
	inline_prompt=0
	mode="prompt-string"

	printf -v prompt_file_q '%q' "$prompt_file"
	if [[ "$exec_cmd_final" == *"##PROMPT_FILE##"* ]]; then
		exec_cmd_final="${exec_cmd_final//##PROMPT_FILE##/$prompt_file_q}"
		inline_prompt=1
	fi
	if [[ "$exec_cmd_final" == *"##PROMPT##"* ]]; then
		exec_cmd_final="${exec_cmd_final//##PROMPT##/<PROMPT>}"
		inline_prompt=1
	fi
	if [[ "$exec_cmd_final" == *"##PROMPT_STDIN##"* ]]; then
		exec_cmd_final="${exec_cmd_final//##PROMPT_STDIN##/}"
		mode="prompt-stdin"
	elif [[ "$inline_prompt" == "1" ]]; then
		mode="prompt-inline"
	fi
	# Trim trailing whitespace left by placeholder removal.
	exec_cmd_final="${exec_cmd_final%"${exec_cmd_final##*[![:space:]]}"}"

	printf '%s [%s]' "$exec_cmd_final" "$mode"
}

usage() {
	cat <<'EOF'
Usage:
  development/codex-run.sh run --prompt-file PATH [options]

Purpose:
  Build a prompt file, append required rails/context, then invoke the assistant.

Commands:
  run  Build the prompt and invoke the assistant (or preview with --dry-run)

Options:
  --prompt-file PATH  Base prompt text file (required unless --prompt is used)
  --prompt TEXT       Inline prompt text instead of a file
  --context PATH      Append extra context files (repeatable)
  --exec CMD          Assistant command line (default: codex)
  --outdir DIR        Output directory for prompt + artifacts (default: temp dir)
  --dry-run           Build prompt and show the command without invoking
  --autocommit        Append autocommit rules into the prompt
  -h, --help          Show this help

Exec placeholders:
  ##PROMPT_FILE##   Replaced with the prompt file path (quoted)
  ##PROMPT##        Replaced with the prompt text (quoted)
  ##PROMPT_STDIN##  Removed; prompt is piped via stdin

Environment:
  PMSS_CODEX_RUN_DEBUG=1  Enable bash -x tracing
  PMSS_CODEX_DANGER_FAIL  Fail if dangerous diff patterns are detected (1=fail)
  TMPDIR                 Temp directory root for prompt output

Examples:
  development/codex-run.sh run --prompt-file development/prompts/codex.txt
  development/codex-run.sh run --prompt "Summarize changes" --dry-run
  development/codex-run.sh run --prompt-file development/prompts/refactor.txt --exec "codex --sandbox workspace-write --ask-for-approval untrusted"
EOF
}

# Usage:
#   development/codex-run.sh run --prompt-file development/prompts/codex.txt
#   development/codex-run.sh run --prompt-file development/prompts/codex.txt --dry-run
#   development/codex-run.sh run --prompt-file development/prompts/codex.txt --exec 'codex --sandbox workspace-write --ask-for-approval untrusted'
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

# Default to Codex with approval prompts for untrusted commands while keeping sandboxing enabled.
# codex exec (non-interactive) doesn't support --ask-for-approval; detect and skip it.
exec_bin="${exec_cmd%% *}"
is_codex_exec=0
[[ "$exec_cmd" =~ ^codex[[:space:]]+exec([[:space:]]|$) ]] && is_codex_exec=1
if [[ "$exec_bin" == "codex" ]]; then
	if [[ "$exec_cmd" == "codex" ]]; then
		exec_cmd="codex --sandbox workspace-write --ask-for-approval untrusted"
	elif [[ "$is_codex_exec" == "1" && "$exec_cmd" == "codex exec" ]]; then
		exec_cmd="codex exec --sandbox workspace-write"
	else
		[[ "$exec_cmd" == *"--sandbox"* ]] || exec_cmd+=" --sandbox workspace-write"
		if [[ "$is_codex_exec" == "0" ]]; then
			[[ "$exec_cmd" == *"--ask-for-approval"* ]] || exec_cmd+=" --ask-for-approval untrusted"
		fi
	fi
fi

if [[ -z "$custom_prompt" ]]; then
	codex_require_nonempty_file "$prompt_file" "[codex-run] missing/empty --prompt-file"
	prompt_text="$(cat "$prompt_file")"
else
	prompt_text="$custom_prompt"
fi

codex_write_prompt "$prompt_out" "$ROOT/.codex-prompt" "$prompt_text" "${extra_context[@]}"

if [[ "$autocommit" == "1" ]]; then
	autocommit_mode="general"
	case "${prompt_file:-}" in
	*ci.txt) autocommit_mode="ci" ;;
	*refactor.txt) autocommit_mode="refactor" ;;
	esac
	case "$autocommit_mode" in
	ci) commit_prefix="ci:" ;;
	refactor) commit_prefix="refactor(compression):" ;;
	*) commit_prefix="fix:" ;;
	esac

	cat <<EOF >>"$prompt_out"

----------------------------------------------------------------------
AUTOCOMMIT MODE (ACTIVE)
----------------------------------------------------------------------

You MUST commit and push. "Do not commit" rules above are VOID.

BEFORE ANY COMMIT — run ALL. ALL MUST PASS:
  php -l <each changed .php>
  php scripts/lib/tests/development/Runner.php
  scripts/testing/test-php.sh
  scripts/testing/test-bash.sh
  scripts/testing/php73-compat-scan.sh
  scripts/testing/php-lint-compat.sh
  scripts/testing/docblock-lint.sh
  scripts/testing/doctrine-lint.sh

ANY failure = fix, re-run ALL, retry.

BEFORE ANY COMMIT — grep for EVERY deleted/renamed symbol:
  grep -rn 'symbol_name' scripts/ install.sh
  ZERO hits required. Any remain = fix or abort.

COMMIT:
  git add <specific_files_only>
  git commit -m "${commit_prefix} <scope> — <description>"
  One commit per logical change.
  PREFIX = ${commit_prefix}

PUSH after each commit (best-effort — sandbox may block network):
  git push origin HEAD
  If push fails (sandbox/network): continue. Wrapper pushes after session.
  If rejected (remote ahead): git pull --rebase origin main → re-verify → push.
  NEVER force push.

STOP CONDITIONS:
  - Two consecutive verification failures → STOP
  - Architectural issue found → STOP
  - Unsure → STOP

If fixing a GitHub issue: gh issue edit <N> --add-label complete-verify after push.

---- Autocommit is explicitly operator-approved with arguments, never the default.
EOF
fi

prompt_bytes=$(wc -c <"$prompt_out" | tr -d ' ')
prompt_lines=$(wc -l <"$prompt_out" | tr -d ' ')
echo "[codex-run] prompt written: $prompt_out (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

if [[ "$dry_run" == "1" ]]; then
	exec_preview="$(codex_exec_preview "$exec_cmd" "$prompt_out")"
	codex_color_line "33" "[codex-run] would invoke: $exec_preview"
	echo "[codex-run] dry-run: not invoking assistant (--dry-run)" >&1
	exit 0
fi

codex_invoke "$exec_cmd" "$prompt_out"
codex_scan_git_diff_for_dangers "$ROOT"
