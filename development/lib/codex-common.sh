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
		'^\.gitignore$'
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
	done < <(git -C "$repo_root" diff --name-only 2>/dev/null; git -C "$repo_root" diff --cached --name-only 2>/dev/null)

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

	# Destructive commands + PHP injection patterns (public repo: issue bodies are untrusted)
	local patterns='(rm[[:space:]]+-[[:space:]]*rf|rm[[:space:]]+-[[:space:]]*fr|mkfs[.]|wipefs|dd[[:space:]]+if=|parted[[:space:]]|sfdisk[[:space:]]|zpool[[:space:]]|curl[[:space:]].*[|][[:space:]]*sh|wget[[:space:]].*[|][[:space:]]*sh|eval[[:space:]]*[(]|assert[[:space:]]*[(]|base64_decode[[:space:]]*[(]|proc_open[[:space:]]*[(]|popen[[:space:]]*[(]|\$_GET[[:space:]]*\[|\$_POST[[:space:]]*\[|\$_REQUEST[[:space:]]*\[|\$_SERVER\[.HTTP_)'
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

	if [[ "$found" == "1" ]]; then
		echo "[codex-run] DANGER: suspicious new patterns detected in git diff output" >&2
		if [[ "$fail" == "1" ]]; then
			echo "[codex-run] DANGER: failing due to PMSS_CODEX_DANGER_FAIL=1" >&2
			exit 3
		fi
	fi

	# --- Validation Relaxation Scan ---
	# Detect net removal of validation patterns (guards, constraints, checks).
	# When more validation guards are removed than added, flag as relaxation.
	# PMSS_CODEX_RELAXATION_FAIL=1 exits non-zero when net removals are found.
	local removal_re='(strlen[[:space:]]*[(]|preg_match[[:space:]]*[(]|pmss[A-Za-z]*Validate[A-Za-z]*[[:space:]]*[(]|pmss[A-Za-z]*IsValid[A-Za-z]*[[:space:]]*[(]|die[[:space:]]*[(]|throw[[:space:]]+new)'
	local relaxation_fail="${PMSS_CODEX_RELAXATION_FAIL:-0}"
	local relax_result
	relax_result=$( {
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

	done <<< "$messages"

	if [[ "$found" -eq 1 ]]; then
		echo "[commit-pii] WARNING: $ahead unpushed commit(s) contain PII" >&2
		return 1
	fi

	return 0
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
