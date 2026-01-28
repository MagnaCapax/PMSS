#!/usr/bin/env bash
# Shared helpers for Codex-oriented CLI wrappers.
# Keep lightweight and dependency-free so scripts can source this safely.

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

# Normalize assistant CLI args (map yolo to Claude's danger flag).
codex_normalize_exec_extra_args() {
	local agent="$1"
	shift || true
	local -a args=("$@")
	local -a normalized=()
	local claude_force_danger=0

	for ((i = 0; i < ${#args[@]}; i++)); do
		case "${args[$i]}" in
		--yolo | -y)
			if [[ "$agent" == "claude" ]]; then
				claude_force_danger=1
			else
				normalized+=("${args[$i]}")
			fi
			;;
		--approval-mode)
			if [[ "$agent" == "claude" && "${args[$((i + 1))]:-}" == "yolo" ]]; then
				claude_force_danger=1
				i=$((i + 1))
			else
				normalized+=("${args[$i]}")
				if [[ -n "${args[$((i + 1))]:-}" ]]; then
					normalized+=("${args[$((i + 1))]}")
					i=$((i + 1))
				fi
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

	printf '%s\n' "${normalized[@]}"
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
EOF

	codex_append_local_notes "$notes_file" "$prompt_file"
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

	local patterns='(rm[[:space:]]+-[[:space:]]*rf|rm[[:space:]]+-[[:space:]]*fr|mkfs[.]|wipefs|dd[[:space:]]+if=|parted[[:space:]]|sfdisk[[:space:]]|zpool[[:space:]]|curl[[:space:]].*[|][[:space:]]*sh|wget[[:space:]].*[|][[:space:]]*sh)'
	local found=0

	if git -C "$repo_root" diff --no-color \
		| awk -v re="$patterns" '
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
		' \
		| sed 's/^/[codex-run] DANGER: /' >&2; then
		found=1
	fi

	if git -C "$repo_root" diff --cached --no-color \
		| awk -v re="$patterns" '
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
		' \
		| sed 's/^/[codex-run] DANGER: /' >&2; then
		found=1
	fi

	if [[ "$found" == "1" ]]; then
		echo "[codex-run] DANGER: suspicious new patterns detected in git diff output" >&2
		if [[ "$fail" == "1" ]]; then
			echo "[codex-run] DANGER: failing due to PMSS_CODEX_DANGER_FAIL=1" >&2
			exit 3
		fi
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

	local prompt prompt_q prompt_file_q exec_cmd_final inline_prompt
	prompt="$(cat "$prompt_file")"
	printf -v prompt_q '%q' "$prompt"
	printf -v prompt_file_q '%q' "$prompt_file"
	exec_cmd_final="$exec_cmd"
	inline_prompt=0

	if [[ "$exec_cmd_final" == *"##PROMPT_FILE##"* ]]; then
		exec_cmd_final="${exec_cmd_final//##PROMPT_FILE##/$prompt_file_q}"
		inline_prompt=1
	fi
	if [[ "$exec_cmd_final" == *"##PROMPT##"* ]]; then
		exec_cmd_final="${exec_cmd_final//##PROMPT##/$prompt_q}"
		inline_prompt=1
	fi
	if [[ "$exec_cmd_final" == *"##PROMPT_STDIN##"* ]]; then
		exec_cmd_final="${exec_cmd_final//##PROMPT_STDIN##/}"
		echo "[codex] invoking: $exec_cmd_final [prompt-stdin]" >&1
		eval "$exec_cmd_final < $prompt_file"
		return
	fi

	if [[ "$inline_prompt" == "1" ]]; then
		echo "[codex] invoking: $exec_cmd_final [prompt-inline]" >&1
		eval "$exec_cmd_final"
		return
	fi

	echo "[codex] invoking: $exec_cmd_final [prompt-string]" >&1
	eval "$exec_cmd_final $prompt_q"
}
