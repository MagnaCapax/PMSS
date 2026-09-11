#!/usr/bin/env bash
# Runner CLI contract: parse arguments before creating artifacts or invoking tools.
# Sourced by codex-run.sh so help remains a side-effect-free path.
# shellcheck disable=SC2034

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
  --exec CMD          Assistant command line (default: codex exec ##PROMPT_STDIN##)
                      Codex defaults: gpt-6-astra, medium reasoning
  --event-log PATH    JSONL event log output path (default: log/codex-run/DATE/RUN.jsonl)
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
  PMSS_CODEX_NO_SANDBOX=1  Skip automatic sandbox selection
  PMSS_CODEX_DANGER_FAIL  Fail if dangerous diff patterns are detected (1=fail)
  PMSS_CODEX_RUN_EVENT_LOG  Default event log path (overridden by --event-log)
  TMPDIR                 Temp directory root for prompt output

Examples:
  development/codex-run.sh run --prompt-file development/prompts/codex.txt
  development/codex-run.sh run --prompt "Summarize changes" --dry-run
  development/codex-run.sh run --prompt-file development/prompts/refactor.txt --exec "codex --sandbox workspace-write --ask-for-approval untrusted"
EOF
}

cmd=${1:-}
shift || true

case "$cmd" in
run) ;;
*)
	codex_cli_help_or_error_exit "$cmd" codex-run usage "unknown command: $cmd"
	;;
esac

prompt_file=""
custom_prompt=""
exec_cmd="codex exec ##PROMPT_STDIN##"
outdir=""
event_log=""
dry_run=0
autocommit=0
declare -a extra_context=()

while [[ $# -gt 0 ]]; do
	if codex_parse_value_option_map "$1" "${2:-}" prompt_file --prompt-file custom_prompt --prompt exec_cmd --exec outdir --outdir event_log --event-log; then
		shift "$CODEX_PARSE_SHIFT" || true
		continue
	fi
	if codex_parse_append_option_map "$1" "${2:-}" extra_context --context; then
		shift "$CODEX_PARSE_SHIFT" || true
		continue
	fi
	case "$1" in
	--dry-run | --autocommit)
		if [[ "$1" == "--dry-run" ]]; then
			dry_run=1
		else
			autocommit=1
		fi
		shift || true
		;;
	*)
		codex_cli_help_or_error_exit "$1" codex-run usage "unknown option: $1"
		;;
	esac
done
