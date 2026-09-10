#!/usr/bin/env bash
# Compose the selected task and optional autocommit contract from prompt files.
# The runner establishes these values before sourcing this module.
# shellcheck disable=SC2154

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
	*issues.txt) autocommit_mode="issues" ;;
	*qa.txt) autocommit_mode="qa" ;;
	esac
	case "$autocommit_mode" in
	ci) commit_prefix="ci:" ;;
	refactor) commit_prefix="refactor(compression):" ;;
	qa) commit_prefix="fix:" ;;
	*) commit_prefix="fix:" ;;
	esac

	# Mode-aware prefix: refactor runs embed the selected mode's prefix in the prompt
	# (e.g. 'COMMIT PREFIX OVERRIDE: Use "refactor(decompose):" as commit prefix.'). Honor it
	# so commit attribution reflects the ACTUAL mode (decompose/dry/safety), not the filename
	# default. Absent (build/issues/qa prompts) -> keep the filename-derived default above.
	if [[ -n "$custom_prompt" ]]; then
		prefix_pattern='COMMIT PREFIX OVERRIDE: Use "([^"]+)"'
		if [[ "$custom_prompt" =~ $prefix_pattern ]]; then
			commit_prefix="${BASH_REMATCH[1]}"
		fi
	fi

	# Substitute only the established prefix marker; the rest stays literal prompt text.
	prompt_rules="$(cat "$HERE/prompts/autocommit.txt")"
	printf '%s\n' "${prompt_rules//##COMMIT_PREFIX##/$commit_prefix}" >>"$prompt_out"
fi
