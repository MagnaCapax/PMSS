#!/usr/bin/env bash
# Shared helpers for Codex-oriented CLI wrappers.
# Keep lightweight and dependency-free so scripts can source this safely.

# Initialize ROOT from a launcher path and optionally chdir.
codex_init_root() {
	ROOT="$(cd "$1/.." && pwd)"
	[[ "${2:-0}" == "1" ]] && cd "$ROOT"
	return 0
}

# Enable bash -x tracing when the given env var is set to 1.
codex_enable_debug() {
	local env_var="$1" prefix="$2"
	if [[ "${!env_var:-0}" == "1" ]]; then
		export PS4="[$prefix:trace] "
		set -x
	fi
}

# Standardised ERR trap message with script-specific prefix.
codex_set_error_trap() {
	local prefix="$1"
	trap 'echo "['"$prefix"'] ERROR rc=$? at line $LINENO while: $BASH_COMMAND" >&1' ERR
}

codex_usage_exit() {
	"$1"
	exit 0
}

codex_cli_error_exit() {
	local prefix="$1" message="$2" line
	shift 2 || true
	echo "[$prefix] $message" >&2
	for line in "$@"; do echo "$line" >&2; done
	exit 2
}

codex_cli_help_or_error_exit() {
	local arg="$1" prefix="$2" usage_func="$3" message="$4"
	shift 4 || true
	case "$arg" in
	-h | --help | "")
		codex_usage_exit "$usage_func"
		;;
	*)
		codex_cli_error_exit "$prefix" "$message" "$@"
		;;
	esac
}

# Apply the common agentic launcher root, debug, ERR-trap, and defaults setup.
# shellcheck disable=SC2034
codex_agentic_bootstrap() {
	local here="$1" debug_env="$2" prefix="$3" cd_root="${4:-0}"
	codex_init_root "$here" "$cd_root"
	codex_enable_debug "$debug_env" "$prefix"
	codex_set_error_trap "$prefix"
	ASSIST_DIR="$here/assistants"
	default_agent="${PMSS_AGENTIC_DEFAULT_AGENT:-codex}"
}

# Bootstrap from a sourced launcher without repeating path/default setup.
codex_agentic_bootstrap_self() {
	HERE="$(cd "$(dirname "${BASH_SOURCE[1]:-$0}")" && pwd)"
	codex_agentic_bootstrap "$HERE" "$@"
}

# Millisecond timestamp helper with a portable fallback.
codex_now_ms() {
	local now
	now="$(date +%s%3N 2>/dev/null || true)"
	if [[ "$now" =~ ^[0-9]+$ ]]; then
		printf '%s\n' "$now"
		return 0
	fi
	now="$(date +%s 2>/dev/null || echo 0)"
	printf '%s000\n' "$now"
}

# Minimal JSON string escaping for log/event payloads.
codex_json_escape() {
	local value="${1:-}"
	value="${value//\\/\\\\}"
	value="${value//\"/\\\"}"
	value="${value//$'\n'/\\n}"
	value="${value//$'\r'/\\r}"
	value="${value//$'\t'/\\t}"
	printf '%s' "$value"
}

# Run a static PHP JSON filter against stdin, with decoded payload in $j.
# shellcheck disable=SC2016
codex_json_filter_stdin() {
	local php_code="$1"
	php -r '$j=json_decode(stream_get_contents(STDIN), true); if (!is_array($j)) { $j=[]; } '"$php_code" 2>/dev/null || true
}
# Derive a compact distro label for event logs.
codex_detect_distro_label() {
	local label
	label="$( (
		# shellcheck disable=SC1091
		. /etc/os-release 2>/dev/null || true
		printf '%s' "${VERSION_CODENAME:-${ID:-unknown}}"
	) 2>/dev/null)"
	if [[ -z "$label" ]]; then
		label="unknown"
	fi
	printf '%s\n' "$label"
}

# Emit a single structured JSONL event for wrapper observability.
# Fields align with repo observability baseline when feasible.
codex_emit_event_jsonl() {
	local log_file="$1" event="$2" level="$3" step="$4" correlation_id="$5"
	local rc="${6:-}" duration_ms="${7:-}" detail="${8:-}"
	local log_dir ts host distro rc_json duration_json
	log_dir="$(dirname "$log_file")"
	mkdir -p "$log_dir" 2>/dev/null || return 0

	ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	host="$(hostname -s 2>/dev/null || hostname 2>/dev/null || echo unknown)"
	distro="$(codex_detect_distro_label)"
	rc_json="null"
	duration_json="null"
	if [[ -n "$rc" ]]; then
		rc_json="$rc"
	fi
	if [[ -n "$duration_ms" ]]; then
		duration_json="$duration_ms"
	fi

	printf '{"timestamp":"%s","event":"%s","level":"%s","step":"%s","rc":%s,"duration_ms":%s,"host":"%s","distro":"%s","correlationId":"%s","detail":"%s"}\n' \
		"$(codex_json_escape "$ts")" \
		"$(codex_json_escape "$event")" \
		"$(codex_json_escape "$level")" \
		"$(codex_json_escape "$step")" \
		"$rc_json" \
		"$duration_json" \
		"$(codex_json_escape "$host")" \
		"$(codex_json_escape "$distro")" \
		"$(codex_json_escape "$correlation_id")" \
		"$(codex_json_escape "$detail")" >>"$log_file" 2>/dev/null || true
}

# List available assistant profiles under the given directory.
codex_list_agents() {
	local assist_dir="$1"
	local f
	if compgen -G "$assist_dir/*" >/dev/null; then
		for f in "$assist_dir"/*; do
			[[ -f "$f" ]] || continue
			basename "$f"
		done
	fi
}

# Read the first non-empty, non-comment line from an agent profile file.
codex_read_profile_cmd() {
	local profile="$1" line=""
	while IFS= read -r line || [[ -n "$line" ]]; do
		line="${line%$'\r'}"
		[[ -z "$line" ]] && continue
		[[ "$line" =~ ^[[:space:]]*# ]] && continue
		echo "$line"
		return 0
	done <"$profile"
	return 1
}

# Resolve the assistant exec command from profile/agent/override.
codex_resolve_exec_cmd() {
	local assist_dir="$1" agent="$2" exec_cmd="$3"
	if [[ -n "$exec_cmd" ]]; then
		printf '%s\n' "$exec_cmd"
		return 0
	fi

	local profile="$assist_dir/$agent"
	if [[ -f "$profile" ]]; then
		exec_cmd="$(codex_read_profile_cmd "$profile" || true)"
		if [[ -z "$exec_cmd" ]]; then
			echo "Error: Agent profile '$profile' has no command line." >&2
			return 2
		fi
	elif command -v "$agent" >/dev/null 2>&1; then
		exec_cmd="$agent"
	else
		echo "Error: Agent '$agent' not available." >&2
		echo >&2
		echo "Available agents with profiles:" >&2
		codex_list_agents "$assist_dir" | sed 's/^/  - /' >&2
		echo >&2
		echo "Or use --exec to specify a custom command." >&2
		return 2
	fi

	printf '%s\n' "$exec_cmd"
}

CODEX_PARSE_SHIFT=0
CODEX_PARSE_VALUE=""

# Parse shared launcher options for agent selection and exec override.
codex_parse_agent_exec_option() {
	local agent_name="$1" exec_name="$2" arg="$3" next_value="${4-}" exec_inline_allowed="${5:-0}"
	CODEX_PARSE_SHIFT=0
	if codex_parse_option_value "$agent_name" "$arg" "$next_value" "--agent" 1; then
		return 0
	fi
	if codex_parse_option_value "$exec_name" "$arg" "$next_value" "--exec" "$exec_inline_allowed"; then
		return 0
	fi
	return 1
}

# Parse shared runner toggles used by agentic subcommands.
codex_parse_runner_toggle_option() {
	local dry_run_name="$1" autocommit_name="$2" arg="$3"
	local -n dry_run_ref="$dry_run_name"
	local -n autocommit_ref="$autocommit_name"
	CODEX_PARSE_SHIFT=1
	case "$arg" in
	--dry-run)
		dry_run_ref=1
		return 0
		;;
	--autocommit)
		autocommit_ref=1
		return 0
		;;
	esac
	CODEX_PARSE_SHIFT=0
	return 1
}

# Parse the shared launcher flags reused across agentic wrappers.
codex_parse_launcher_option() {
	local agent_name="$1" exec_name="$2" dry_run_name="${3-}" autocommit_name="${4-}"
	local arg="$5" next_value="${6-}" exec_inline_allowed="${7:-0}" exec_extra_name="${8-}"
	if codex_parse_agent_exec_option "$agent_name" "$exec_name" "$arg" "$next_value" "$exec_inline_allowed"; then
		return 0
	fi
	if [[ -n "$dry_run_name" && -n "$autocommit_name" ]] && codex_parse_runner_toggle_option "$dry_run_name" "$autocommit_name" "$arg"; then
		return 0
	fi
	if [[ -n "$exec_extra_name" ]] && codex_parse_exec_extra_option "$exec_extra_name" "$arg" "$next_value"; then
		return 0
	fi
	return 1
}

# Match wrapper-specific value options so their value token is not consumed as
# a later common launcher option during the shared pre-pass.
codex_launcher_value_option_matches() {
	local arg="$1" option
	shift || true
	[[ "$arg" == --*=* ]] && return 1
	for option in "$@"; do
		option="${option#--}"
		[[ "$arg" == "--$option" ]] && return 0
	done
	return 1
}

# Strip common agentic launcher options and leave wrapper-specific args intact.
codex_parse_launcher_common_args() {
	local agent_name="$1" exec_name="$2" dry_run_name="${3-}" autocommit_name="${4-}"
	local remaining_name="$5" exec_inline_allowed="${6:-0}" exec_extra_name="${7-}"
	shift 7 || true
	local -a value_options=()
	while [[ $# -gt 0 && "$1" != "--" ]]; do
		value_options+=("$1")
		shift || true
	done
	if [[ "${1-}" == "--" ]]; then
		shift || true
	fi

	local -n remaining_ref="$remaining_name"
	remaining_ref=()
	while [[ $# -gt 0 ]]; do
		if [[ "$1" == "--" ]]; then
			remaining_ref+=("$@")
			break
		fi
		if codex_launcher_value_option_matches "$1" "${value_options[@]}"; then
			remaining_ref+=("$1")
			shift || true
			if [[ $# -gt 0 ]]; then
				remaining_ref+=("$1")
				shift || true
			fi
			continue
		fi
		if codex_parse_launcher_option "$agent_name" "$exec_name" "$dry_run_name" "$autocommit_name" "$1" "${2:-}" "$exec_inline_allowed" "$exec_extra_name"; then
			shift "$CODEX_PARSE_SHIFT" || true
			continue
		fi
		remaining_ref+=("$1")
		shift || true
	done
}

# Parse a CLI option and expose its value via shared parse globals.
codex_parse_option() {
	local arg="$1" next_value="${2-}" option_name="$3" inline_allowed="${4:-0}"
	CODEX_PARSE_SHIFT=0
	CODEX_PARSE_VALUE=""

	if [[ "$arg" == "$option_name" ]]; then
		CODEX_PARSE_VALUE="$next_value"
		CODEX_PARSE_SHIFT=2
		return 0
	fi

	if [[ "$inline_allowed" == "1" && "$arg" == "$option_name="* ]]; then
		CODEX_PARSE_VALUE="${arg#"$option_name"=}"
		CODEX_PARSE_SHIFT=1
		return 0
	fi

	return 1
}

# Parse a CLI option into the requested string variable.
codex_parse_option_value() {
	local target_name="$1"
	local -n target_ref="$target_name"
	codex_parse_option "$2" "${3-}" "$4" "${5:-0}" || return 1
	target_ref="$CODEX_PARSE_VALUE"
}

# Parse one of several value options without duplicating case arms in wrappers.
codex_parse_value_option_map() {
	local arg="$1" next_value="${2-}" target_name option_name
	shift 2 || true
	while [[ $# -ge 2 ]]; do
		target_name="$1"
		option_name="$2"
		shift 2 || true
		if codex_parse_option_value "$target_name" "$arg" "$next_value" "$option_name"; then
			return 0
		fi
	done
	return 1
}

# Parse a repeatable option and append its value to the target array.
codex_parse_option_append() {
	local target_name="$1"
	local -n target_ref="$target_name"
	codex_parse_option "$2" "${3-}" "$4" "${5:-0}" || return 1
	target_ref+=("$CODEX_PARSE_VALUE")
}

# Parse one of several repeatable value options into mapped target arrays.
codex_parse_append_option_map() {
	local arg="$1" next_value="${2-}" target_name option_name
	shift 2 || true
	while [[ $# -ge 2 ]]; do
		target_name="$1"
		option_name="$2"
		shift 2 || true
		if codex_parse_option_append "$target_name" "$arg" "$next_value" "$option_name"; then
			return 0
		fi
	done
	return 1
}

# Parse assistant CLI passthrough flags collected before exec normalization.
codex_parse_exec_extra_option() {
	local target_name="$1" arg="$2" next_value="${3-}"
	local -n target_ref="$target_name"
	CODEX_PARSE_SHIFT=1
	case "$arg" in
	--yolo | -y | --dangerously-skip-permissions)
		target_ref+=("$arg")
		return 0
		;;
	--approval-mode | --ask-for-approval | -a | --allowed-tools | --permission-mode)
		target_ref+=("$arg" "$next_value")
		CODEX_PARSE_SHIFT=2
		return 0
		;;
	esac
	CODEX_PARSE_SHIFT=0
	return 1
}

# Append the shared codex-run options used by the agentic launcher wrappers.
codex_append_runner_args() {
	local target_name="$1" repo_root="$2" agent="$3" exec_cmd="$4" dry_run="$5" autocommit="$6"
	local custom_prompt="${7-}" include_agent_contexts="${8:-1}"
	local -n target_ref="$target_name"
	if [[ "$include_agent_contexts" == "1" ]]; then
		[[ -f "$repo_root/AGENTS.${agent}.md" ]] && target_ref+=(--context "$repo_root/AGENTS.${agent}.md")
		[[ -f "$repo_root/AGENTS.${agent}.local.md" ]] && target_ref+=(--context "$repo_root/AGENTS.${agent}.local.md")
	fi
	[[ -n "$custom_prompt" ]] && target_ref+=(--prompt "$custom_prompt")
	[[ -n "$exec_cmd" ]] && target_ref+=(--exec "$exec_cmd")
	[[ "$dry_run" == "1" ]] && target_ref+=(--dry-run)
	[[ "$autocommit" == "1" ]] && target_ref+=(--autocommit)
	return 0
}

# Resolve the requested agent, falling back to the shared default.
codex_default_agent() {
	local agent="${1-}" default_agent="$2"
	printf '%s\n' "${agent:-$default_agent}"
}

# Resolve the effective agent and its exec command in one step for wrappers.
codex_prepare_agent_exec() {
	local assist_dir="$1" default_agent="$2" agent_name="$3" exec_name="$4"
	local -n agent_ref="$agent_name"
	local -n exec_ref="$exec_name"
	agent_ref="$(codex_default_agent "$agent_ref" "$default_agent")"
	exec_ref="$(codex_resolve_exec_cmd "$assist_dir" "$agent_ref" "$exec_ref")" || return $?
}

codex_make_temp_workspace() {
	local prefix="$1"
	mktemp -d "${TMPDIR:-/tmp}/${prefix}-XXXXXXXX"
}

# Filter candidates to entries this process can claim atomically.
codex_scope_claim_filter_candidates() {
	local claims_dir="$1" candidates="$2" claimed_name="$3" claimed_count_name="$4" orig_count_name="$5" stale_minutes="${6:-480}"
	local filtered f key
	local -n claimed_ref="$claimed_name"

	printf -v "$orig_count_name" '%s' 0
	printf -v "$claimed_count_name" '%s' 0
	[[ -n "$claims_dir" && "$claims_dir" != "/" ]] || return 0
	mkdir -p "$claims_dir" 2>/dev/null || true
	[[ -d "$claims_dir" ]] || return 0
	find "$claims_dir" -mindepth 1 -maxdepth 1 -mmin +"$stale_minutes" -type d -exec rm -rf -- {} + 2>/dev/null || true
	[[ -s "$candidates" ]] || return 0

	filtered="${candidates}.filtered"
	printf -v "$orig_count_name" '%s' "$(wc -l <"$candidates" | tr -d ' ')"
	: >"$filtered"
	while IFS= read -r f; do
		[[ -n "$f" ]] || continue
		key="${f//\//_}"
		mkdir "$claims_dir/$key" 2>/dev/null || continue
		printf '%s\n' "$f" >>"$filtered"
		claimed_ref+=("$key")
	done <"$candidates"
	mv "$filtered" "$candidates"
	printf -v "$claimed_count_name" '%s' "$(wc -l <"$candidates" | tr -d ' ')"
}

codex_scope_claim_release() {
	local claims_dir="$1" f
	shift || true
	[[ -n "$claims_dir" && "$claims_dir" != "/" ]] || return 0
	for f in "$@"; do rmdir "$claims_dir/$f" 2>/dev/null || true; done
}

# Assemble and invoke a standard codex-run prompt command.
codex_run_prompt() {
	local here="$1" prompt_file="$2" outdir="$3" repo_root="$4" agent="$5" exec_cmd="$6" dry_run="$7" autocommit="$8"
	local custom_prompt="${9-}" include_agent_contexts="${10:-1}"
	shift 10
	local -a codex_args=(run --prompt-file "$prompt_file" --outdir "$outdir")
	[[ "$#" -gt 0 ]] && codex_args+=("$@")
	codex_append_runner_args codex_args "$repo_root" "$agent" "$exec_cmd" "$dry_run" "$autocommit" "$custom_prompt" "$include_agent_contexts"
	bash "$here/codex-run.sh" "${codex_args[@]}"
}

# Normalize assistant CLI args (map yolo to Claude's danger flag).
codex_normalize_exec_extra_args() {
	local agent="$1"
	shift || true
	local -a args=("$@")
	local -a normalized=()
	local claude_force_danger=0
	local codex_force_approval=""
	local codex_has_approval=0
	local mode=""

	for ((i = 0; i < ${#args[@]}; i++)); do
		case "${args[$i]}" in
		--yolo | -y)
			if [[ "$agent" == "claude" ]]; then
				claude_force_danger=1
			elif [[ "$agent" == "codex" ]]; then
				codex_force_approval="never"
			else
				normalized+=("${args[$i]}")
			fi
			;;
		--approval-mode)
			if [[ "$agent" == "claude" && "${args[$((i + 1))]:-}" == "yolo" ]]; then
				claude_force_danger=1
				i=$((i + 1))
			elif [[ "$agent" == "codex" ]]; then
				mode="${args[$((i + 1))]:-}"
				[[ "$mode" == "yolo" ]] && mode="never"
				normalized+=(--ask-for-approval)
				codex_has_approval=1
				if [[ -n "$mode" ]]; then
					normalized+=("$mode")
					i=$((i + 1))
				fi
			else
				normalized+=("${args[$i]}")
				if [[ -n "${args[$((i + 1))]:-}" ]]; then
					normalized+=("${args[$((i + 1))]}")
					i=$((i + 1))
				fi
			fi
			;;
		--ask-for-approval)
			normalized+=("${args[$i]}")
			codex_has_approval=1
			if [[ -n "${args[$((i + 1))]:-}" ]]; then
				normalized+=("${args[$((i + 1))]}")
				i=$((i + 1))
			fi
			;;
		*)
			normalized+=("${args[$i]}")
			;;
		esac
	done

	if [[ "$claude_force_danger" == "1" && "$agent" == "claude" ]]; then
		normalized+=(--dangerously-skip-permissions)
	fi
	if [[ "$agent" == "codex" && -n "$codex_force_approval" && "$codex_has_approval" == "0" ]]; then
		normalized+=(--ask-for-approval "$codex_force_approval")
	fi

	printf '%s\n' "${normalized[@]}"
}

# Append normalized assistant CLI args to an exec command string.
codex_append_exec_extra_args() {
	local target_name="$1" agent="$2"
	local -n target_ref="$target_name"
	shift 2 || true

	local exec_extra_arg exec_extra_q line
	local -a normalized_exec_extra_args=()
	while IFS= read -r line; do
		[[ -n "$line" ]] || continue
		normalized_exec_extra_args+=("$line")
	done < <(codex_normalize_exec_extra_args "$agent" "$@")

	for exec_extra_arg in "${normalized_exec_extra_args[@]}"; do
		printf -v exec_extra_q '%q' "$exec_extra_arg"
		target_ref+=" $exec_extra_q"
	done
}

# Require that the given path is a non-empty file.
codex_require_nonempty_file() {
	local path="$1" message="${2:-}"
	if [[ -z "$path" || ! -s "$path" ]]; then
		[[ -n "$message" ]] && echo "$message: $path" >&2
		exit 2
	fi
}

# Count how many of the provided paths are non-empty files.
codex_count_nonempty_files() {
	local count=0 f
	for f in "$@"; do
		[[ -s "$f" ]] && count=$((count + 1))
	done
	echo "$count"
}

# Append local operator notes (optional) to a prompt file.
# Usage: codex_append_local_notes "/abs/path/to/.codex-prompt" "/abs/path/to/prompt.txt"
codex_append_local_notes() {
	local notes_file="$1" prompt_file="$2"
	[[ -f "$notes_file" ]] || return 0

	{
		echo
		echo "Local Operator Notes ($(basename "$notes_file")):"
		cat "$notes_file"
	} >>"$prompt_file"
}

# Write a uniform prompt file by combining a base prompt string, a fixed rails
# context list, optional extra context paths, and optional local operator notes.
#
# Usage:
#   codex_write_prompt "/abs/prompt.txt" "/abs/.codex-prompt" "$prompt_text" "extra/path1" "extra/path2" ...
codex_write_prompt() {
	local prompt_file="$1" notes_file="$2" prompt_text="$3"
	shift 3 || true

	printf '%s\n\n' "$prompt_text" >"$prompt_file"
	cat <<'EOF' >>"$prompt_file"
Context to open (paths in this workspace):
 - AGENTS.md
 - AGENTS.local.md
 - docs/architecture.md
 - docs/update.md
 - docs/install.md
 - docs/refactoring.md
 - docs/contracts.md
 - docs/adr/
EOF

	local p
	for p in "$@"; do
		[[ -n "$p" ]] || continue
		printf '\n - %s' "$p" >>"$prompt_file"
	done
	printf '\n' >>"$prompt_file"

	cat <<'EOF' >>"$prompt_file"

Do not inline these; read them directly from disk.

Output safety checklist:
- If output size is unknown or large, redirect to a file and summarize with tail/head/rg.
- Avoid unbounded commands (recursive find/grep, full log dumps, verbose fsck).
- Prefer bounded queries (git status --short, rg -n pattern path | head -50).
- When in doubt, capture output to /tmp and extract a small excerpt.
EOF

	codex_append_local_notes "$notes_file" "$prompt_file"
}

# Post-run enforcement: revert any modifications to frozen pipeline paths.
# The codex sandbox (workspace-write) allows writes to ALL files in the repo.
# This function detects and reverts changes to paths that agents must NEVER modify.
#
# FROZEN PATHS (security-critical):
#   .github/           — CI/CD workflows (sandbox escape vector)
#   development/       — pipeline scripts (self-modification vector)
#   AGENTS.md          — agent constitution (prompt injection vector)
#   AGENTS.local.md    — local agent rails
#   .codex-prompt      — operator notes (persistent prompt injection)
#   .gitignore         — could hide malicious files
#
# Returns 0 if clean, 1 if frozen paths were touched (and reverted).
codex_scan_frozen_paths() {
	local repo_root="$1"
	command -v git >/dev/null 2>&1 || return 0
	git -C "$repo_root" rev-parse --is-inside-work-tree >/dev/null 2>&1 || return 0

	local frozen_patterns=(
		'^\.github/'
		'^development/'
		'^AGENTS\.md$'
		'^AGENTS\.local\.md$'
		'^\.codex-prompt$'
		'(^|/)\.gitignore$'
	)

	local pattern
	pattern=$(printf '%s\n' "${frozen_patterns[@]}" | paste -sd'|')

	local touched_files=()
	local f

	# Check both staged and unstaged changes
	while IFS= read -r f; do
		[[ -n "$f" ]] || continue
		if echo "$f" | grep -qE "$pattern"; then
			touched_files+=("$f")
		fi
	done < <(
		git -C "$repo_root" diff --name-only 2>/dev/null
		git -C "$repo_root" diff --cached --name-only 2>/dev/null
	)

	# Also check untracked files in frozen dirs
	while IFS= read -r f; do
		[[ -n "$f" ]] || continue
		if echo "$f" | grep -qE "$pattern"; then
			touched_files+=("$f")
		fi
	done < <(git -C "$repo_root" ls-files --others --exclude-standard 2>/dev/null)

	if [[ ${#touched_files[@]} -eq 0 ]]; then
		return 0
	fi

	echo "[codex-run] FROZEN PATH VIOLATION: agent modified protected paths:" >&2
	local file
	for file in "${touched_files[@]}"; do
		echo "[codex-run]   - $file" >&2
	done

	# Revert: restore frozen files from HEAD, remove untracked frozen files
	for file in "${touched_files[@]}"; do
		if git -C "$repo_root" ls-files --error-unmatch "$file" >/dev/null 2>&1; then
			# Tracked file — restore from HEAD
			git -C "$repo_root" checkout HEAD -- "$file" 2>/dev/null || true
			echo "[codex-run]   REVERTED: $file (restored from HEAD)" >&2
		else
			# Untracked file in frozen dir — remove it
			rm -f "$repo_root/$file" 2>/dev/null || true
			echo "[codex-run]   REMOVED: $file (untracked in frozen path)" >&2
		fi
	done

	# Unstage any frozen files that were staged
	for file in "${touched_files[@]}"; do
		git -C "$repo_root" reset HEAD -- "$file" 2>/dev/null || true
	done

	echo "[codex-run] FROZEN PATH VIOLATION: all frozen paths reverted/removed" >&2
	return 1
}

# Post-run warning scan for risky patterns newly added to the working tree.
# This is intentionally best-effort: it should never rewrite or revert changes.
#
# Set PMSS_CODEX_DANGER_FAIL=1 to exit non-zero when matches are found.
codex_scan_git_diff_for_dangers() {
	local repo_root="$1"
	local fail="${PMSS_CODEX_DANGER_FAIL:-0}"

	command -v git >/dev/null 2>&1 || return 0
	command -v awk >/dev/null 2>&1 || return 0
	git -C "$repo_root" rev-parse --is-inside-work-tree >/dev/null 2>&1 || return 0

	# Destructive commands + PHP injection/execution patterns (public repo: issue bodies are untrusted)
	# Keep in sync — expand when Joukahainen finds gaps (Round 27/28/30)
	# NOTE: Use [$] for literal $ and [[] for literal [ — awk -v converts \$ and \[ to plain chars,
	# breaking regex. \$ becomes $ (anchor), \[ becomes [ (unmatched bracket → awk exit 2).
	local patterns='(rm[[:space:]]+-[[:space:]]*rf|rm[[:space:]]+-[[:space:]]*fr|mkfs[.]|wipefs|dd[[:space:]]+if=|parted[[:space:]]|sfdisk[[:space:]]|zpool[[:space:]]|curl[[:space:]].*[|][[:space:]]*sh|wget[[:space:]].*[|][[:space:]]*sh|eval[[:space:]]*[(]|assert[[:space:]]*[(]|base64_decode[[:space:]]*[(]|proc_open[[:space:]]*[(]|popen[[:space:]]*[(]|shell_exec[[:space:]]*[(]|[^a-z_]system[[:space:]]*[(]|passthru[[:space:]]*[(]|[^a-z_]exec[[:space:]]*[(]|curl_init[[:space:]]*[(]|curl_exec[[:space:]]*[(]|file_get_contents[[:space:]]*[(][[:space:]]*["\x27]https?://|chmod[[:space:]]+[0-7]*7[0-7][0-7]|[$]_GET[[:space:]]*[[]|[$]_POST[[:space:]]*[[]|[$]_REQUEST[[:space:]]*[[]|[$]_SERVER[[:space:]]*[[]|getenv[[:space:]]*[(][[:space:]]*["\x27]CI["\x27]|[$]_ENV[[:space:]]*[[][[:space:]]*["\x27]CI["\x27])'
	local found=0

	if git -C "$repo_root" diff --no-color |
		awk -v re="$patterns" '
			/^[+][+][+] b[/]/ { file=substr($0,7); next }
			/^[+][+][+]/ { next }
			/^[+]/ {
				line=substr($0,2)
				if (line ~ re) {
					printf("%s: +%s\n", (file ? file : "<unknown>"), line)
					found=1
				}
			}
			END { exit (found ? 0 : 1) }
		' |
		sed 's/^/[codex-run] DANGER: /' >&2; then
		found=1
	fi

	if git -C "$repo_root" diff --cached --no-color |
		awk -v re="$patterns" '
			/^[+][+][+] b[/]/ { file=substr($0,7); next }
			/^[+][+][+]/ { next }
			/^[+]/ {
				line=substr($0,2)
				if (line ~ re) {
					printf("%s: +%s\n", (file ? file : "<unknown>"), line)
					found=1
				}
			}
			END { exit (found ? 0 : 1) }
		' |
		sed 's/^/[codex-run] DANGER: /' >&2; then
		found=1
	fi

	# --- Binary File Detection ---
	# Binary files bypass all text-based scanning (danger, relaxation, PII).
	local binary_files=()
	while IFS= read -r f; do
		[[ -n "$f" ]] || continue
		binary_files+=("$f")
	done < <({
		git -C "$repo_root" diff --numstat 2>/dev/null
		git -C "$repo_root" diff --cached --numstat 2>/dev/null
	} | awk '$1 == "-" && $2 == "-" { print $3 }' | sort -u)
	if [[ ${#binary_files[@]} -gt 0 ]]; then
		echo "[codex-run] DANGER: new binary file(s) detected (bypass text scanning):" >&2
		printf '  %s\n' "${binary_files[@]}" >&2
		found=1
	fi

	if [[ "$found" == "1" ]]; then
		echo "[codex-run] DANGER: suspicious new patterns detected in git diff output" >&2
		if [[ "$fail" == "1" ]]; then
			echo "[codex-run] DANGER: failing due to PMSS_CODEX_DANGER_FAIL=1" >&2
			exit 3
		fi
	fi

	# --- Validation Relaxation Scan ---
	# Detect net removal of validation patterns (guards, constraints, checks, assertions).
	# When more validation guards are removed than added, flag as relaxation.
	# Includes test assertions — assertion removal is a test corruption signal (Joukahainen Round 26).
	# PMSS_CODEX_RELAXATION_FAIL=1 exits non-zero when net removals are found.
	local removal_re='(strlen[[:space:]]*[(]|preg_match[[:space:]]*[(]|pmss[A-Za-z]*Validate[A-Za-z]*[[:space:]]*[(]|pmss[A-Za-z]*IsValid[A-Za-z]*[[:space:]]*[(]|die[[:space:]]*[(]|throw[[:space:]]+new|[$]this->assert[A-Z])'
	local relaxation_fail="${PMSS_CODEX_RELAXATION_FAIL:-0}"
	local relax_result
	relax_result=$({
		git -C "$repo_root" diff --no-color 2>/dev/null
		git -C "$repo_root" diff --cached --no-color 2>/dev/null
	} | awk -v re="$removal_re" '
		/^[+][+][+] b[/]/ { file=substr($0,7); next }
		/^[+][+][+]/ || /^[-][-][-]/ { next }
		/^[+]/ && substr($0,2) ~ re { added++ }
		/^[-]/ && substr($0,2) ~ re { removed++; files[file]++ }
		END {
			net = (removed+0) - (added+0)
			if (net > 0) {
				for (f in files) printf "  %s\n", f
				printf "NET_REMOVAL=%d\n", net
			}
		}
	' 2>/dev/null) || true

	if [[ "$relax_result" == *"NET_REMOVAL="* ]]; then
		echo "[codex-run] RELAXATION: net validation removal detected:" >&2
		echo "$relax_result" | grep -v '^NET_REMOVAL=' | sed 's/^/[codex-run] RELAXATION: /' >&2
		if [[ "$relaxation_fail" == "1" ]]; then
			echo "[codex-run] RELAXATION: failing due to PMSS_CODEX_RELAXATION_FAIL=1" >&2
			exit 4
		fi
	fi
}

# Scan unpushed commit messages for PII that should not be on a public repo.
# Usage: codex_scan_commit_messages_for_pii <repo_root> [<base_ref>]
# base_ref defaults to origin/main.
# Returns 0 if clean, 1 if PII detected.
# Outputs violation details to stderr.
#
# NOTE: This is a lightweight generic check. The external push wrapper may
# apply additional operator-specific patterns.
codex_scan_commit_messages_for_pii() {
	local repo_root="$1"
	local base_ref="${2:-origin/main}"

	command -v git >/dev/null 2>&1 || return 0
	git -C "$repo_root" rev-parse --is-inside-work-tree >/dev/null 2>&1 || return 0

	local ahead
	ahead=$(git -C "$repo_root" rev-list --count "${base_ref}..HEAD" 2>/dev/null || echo 0)
	[[ "$ahead" -gt 0 ]] || return 0

	local messages
	messages=$(git -C "$repo_root" log --format='%H %s%n%b' "${base_ref}..HEAD" 2>/dev/null) || return 0
	[[ -n "$messages" ]] || return 0

	local found=0
	local line

	while IFS= read -r line; do
		[[ -n "$line" ]] || continue

		# /home/<username> paths (real user accounts)
		if [[ "$line" =~ /home/[a-z][a-z0-9]{2,15}[^a-z0-9] ]] || [[ "$line" =~ /home/[a-z][a-z0-9]{2,15}$ ]]; then
			echo "[commit-pii] BLOCK: user path in commit message: $line" >&2
			found=1
		fi

		# Email addresses (except noreply@)
		if [[ "$line" =~ [a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,} ]]; then
			if [[ ! "$line" =~ noreply@ ]]; then
				echo "[commit-pii] BLOCK: email address in commit message: $line" >&2
				found=1
			fi
		fi

		# ssh root@ commands
		if [[ "$line" =~ ssh[[:space:]]+root@ ]]; then
			echo "[commit-pii] BLOCK: SSH command in commit message: $line" >&2
			found=1
		fi

	done <<<"$messages"

	if [[ "$found" -eq 1 ]]; then
		echo "[commit-pii] WARNING: $ahead unpushed commit(s) contain PII" >&2
		return 1
	fi

	return 0
}

# Expand prompt placeholders for both real invocation and dry-run previews.
codex_expand_prompt_placeholders() {
	local exec_cmd="$1" prompt_file="$2" prompt_replacement="$3" output_name="$4" mode_name="$5"
	local -n output_ref="$output_name"
	local -n mode_ref="$mode_name"
	local prompt_file_q
	printf -v prompt_file_q '%q' "$prompt_file"
	output_ref="$exec_cmd"
	mode_ref="prompt-string"
	if [[ "$output_ref" == *"##PROMPT_FILE##"* ]]; then
		output_ref="${output_ref//##PROMPT_FILE##/$prompt_file_q}"
		mode_ref="prompt-inline"
	fi
	if [[ "$output_ref" == *"##PROMPT##"* ]]; then
		output_ref="${output_ref//##PROMPT##/$prompt_replacement}"
		mode_ref="prompt-inline"
	fi
	if [[ "$output_ref" == *"##PROMPT_STDIN##"* ]]; then
		output_ref="${output_ref//##PROMPT_STDIN##/}"
		mode_ref="prompt-stdin"
	fi
}

# Invoke the assistant executable with the prompt file contents.
codex_invoke() {
	local exec_cmd="$1" prompt_file="$2"
	local exec_bin="${exec_cmd%% *}"
	if [[ -z "$exec_bin" ]] || ! command -v "$exec_bin" >/dev/null 2>&1; then
		echo "[codex] assistant executable not found: $exec_bin" >&2
		echo "[codex] run manually with codex installed, for example:" >&2
		echo "  codex \"\$(cat '$prompt_file')\"" >&2
		exit 127
	fi

	local prompt prompt_q exec_cmd_final prompt_mode
	prompt="$(cat "$prompt_file")"
	printf -v prompt_q '%q' "$prompt"
	codex_expand_prompt_placeholders "$exec_cmd" "$prompt_file" "$prompt_q" exec_cmd_final prompt_mode

	if [[ "$prompt_mode" == "prompt-stdin" ]]; then
		echo "[codex] invoking: $exec_cmd_final [prompt-stdin]" >&1
		eval "$exec_cmd_final < $prompt_file"
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
