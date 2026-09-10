#!/usr/bin/env bash
# Assistant command defaults, prompt transport, and invocation. No execution on source.
# Nameref outputs are consumed by the caller.
# shellcheck disable=SC2034

codex_prepare_invocation() {
	local exec_bin="${exec_cmd%% *}" defaults sandbox_mode=danger-full-access
	[[ "${exec_bin##*/}" == "codex" ]] || return 0
	# Repeated config keys are evaluated in order; explicit CLI options/configs win.
	# Config defaults avoid duplicate --sandbox/-s options or bypass-flag conflicts.
	defaults="$exec_bin -c 'model=\"gpt-6-astra\"' -c 'model_reasoning_effort=\"xhigh\"'"
	if [[ "${PMSS_CODEX_NO_SANDBOX:-0}" != "1" ]]; then
		if [[ "$exec_cmd" == "$exec_bin" ]]; then
			sandbox_mode=workspace-write
			defaults+=" --add-dir .git"
		fi
		# danger-full-access lets headless Codex write .git/index.lock and commit directly.
		# workspace-write + --add-dir .git did not reliably permit that, which spawned
		# the removed parent-shell staging fallback and destroyed mode attribution.
		# Preserve the operator-accepted headless policy (c8f7b21f, 2026-05-14).
		defaults+=" -c 'sandbox_mode=\"$sandbox_mode\"'"
	else
		echo "[codex-run] NOTICE: sandbox disabled via PMSS_CODEX_NO_SANDBOX=1"
	fi
	# exec does not accept the interactive approval flag; the config key works in both modes.
	if [[ ! "$exec_cmd" =~ (^|[[:space:]])(exec|e)([[:space:]]|$) ]]; then
		defaults+=" -c 'approval_policy=\"untrusted\"'"
	fi
	exec_cmd="$defaults${exec_cmd#"$exec_bin"}"
}

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
	local exec_cmd_final mode

	codex_expand_prompt_placeholders "$exec_cmd" "$prompt_file" "<PROMPT>" exec_cmd_final mode
	# Trim trailing whitespace left by placeholder removal.
	exec_cmd_final="${exec_cmd_final%"${exec_cmd_final##*[![:space:]]}"}"

	printf '%s [%s]' "$exec_cmd_final" "$mode"
}

# Expand prompt placeholders for both real invocation and dry-run previews.
codex_expand_prompt_placeholders() {
	local exec_cmd="$1" prompt_file="$2" prompt_replacement="$3" output_name="$4" mode_name="$5"
	local -n output_ref="$output_name"
	local -n mode_ref="$mode_name"
	local prompt_file_q remaining="$exec_cmd" token
	local placeholder_pattern='##PROMPT(_FILE|_STDIN)?##'
	printf -v prompt_file_q '%q' "$prompt_file"
	output_ref=""
	mode_ref="prompt-string"
	# Expand only template tokens, never markers or ampersands inside replacement data.
	while [[ "$remaining" =~ $placeholder_pattern ]]; do
		token="${BASH_REMATCH[0]}"
		output_ref+="${remaining%%"$token"*}"
		remaining="${remaining#*"$token"}"
		case "$token" in
		'##PROMPT_STDIN##') mode_ref="prompt-stdin" ;;
		'##PROMPT_FILE##' | '##PROMPT##')
			if [[ "$token" == '##PROMPT_FILE##' ]]; then
				output_ref+="$prompt_file_q"
			else
				output_ref+="$prompt_replacement"
			fi
			[[ "$mode_ref" == "prompt-stdin" ]] || mode_ref="prompt-inline"
			;;
		esac
	done
	output_ref+="$remaining"
}

# Invoke the assistant executable with the prompt file contents.
codex_invoke() {
	local exec_cmd="$1" prompt_file="$2"
	local exec_bin="${exec_cmd%% *}"
	if [[ -z "$exec_bin" ]] || ! command -v "$exec_bin" >/dev/null 2>&1; then
		echo "[codex] assistant executable not found: $exec_bin" >&2
		echo "[codex] run manually with codex installed, for example:" >&2
		echo "  codex \"\$(cat '$prompt_file')\"" >&2
		return 127
	fi

	local prompt prompt_q prompt_file_q exec_cmd_final prompt_mode
	prompt="$(cat "$prompt_file")"
	printf -v prompt_q '%q' "$prompt"
	printf -v prompt_file_q '%q' "$prompt_file"
	codex_expand_prompt_placeholders "$exec_cmd" "$prompt_file" "$prompt_q" exec_cmd_final prompt_mode

	if [[ "$prompt_mode" == "prompt-stdin" ]]; then
		echo "[codex] invoking: $exec_cmd_final [prompt-stdin]" >&1
		eval "$exec_cmd_final < $prompt_file_q"
		return
	fi

	if [[ "$prompt_mode" == "prompt-inline" ]]; then
		echo "[codex] invoking: $exec_cmd_final [prompt-inline]" >&1
		eval "$exec_cmd_final"
		return
	fi

	echo "[codex] invoking: $exec_cmd_final [prompt-string]" >&1
	eval "$exec_cmd_final $prompt_q"
}
