#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
source "$ROOT/scripts/cli/lib/codex-common.sh"

# Optional debug: PMSS_CODEX_DEBUG=1 enables bash -x tracing.
codex_enable_debug PMSS_CODEX_DEBUG "codex"

codex_set_error_trap "codex"

echo "[codex] start: assembling strict-rails context and invoking assistant" >&1

# codex.sh — Build a strict-rails PMSS prompt for Codex CLI (or compatible).
#
# Usage:
#   scripts/cli/codex.sh                      # build prompt and invoke codex
#   scripts/cli/codex.sh --prompt "..."       # override top-level goal/prompt
#   scripts/cli/codex.sh --exec 'codex'       # choose assistant executable (default: codex)
#
# Local prompt extension (optional, ignored by git):
#   - If $ROOT/.codex-prompt exists, it is appended to the prompt under "Local Operator Notes".

TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-codex-XXXXXXXX")"
PROMPT="$OUTDIR/prompt.txt"

custom_prompt=""
exec_cmd="codex"

while [[ $# -gt 0 ]]; do
	case "$1" in
	--prompt)
		custom_prompt=${2:-}
		shift 2 || true
		;;
	--exec)
		exec_cmd=${2:-}
		shift 2 || true
		;;
	-h | --help)
		sed -n '1,80p' "$0"
		exit 0
		;;
	*)
		echo "[codex] unknown option: $1" >&2
		exit 2
		;;
	esac
done

DEFAULT_PROMPT=$(
	cat <<'PMSSPROMPT'
PMSS Codex Session — Strict Rails Mode

Goal: Start a safe PMSS development session with the correct guard rails loaded.

Read first (do not proceed until read):
- AGENTS.md (rails / Constitution / doctrine; treat as binding).
- agents.local.md (host-specific local rails).
- Any nested AGENTS.md covering files you touch (most-specific instructions win).
- docs/architecture.md (updater topology and responsibilities).
- docs/update.md and docs/install.md (workflow; invariants; do-not-break constraints).
- docs/refactoring.md (Linux-kernel-style refactor guidance).
- docs/adr/* (Accepted ADRs are constraints; consult relevant ones).
- docs/contracts.md (script/function behavior contracts; treat as canonical).

Hard rails (must follow):
- Stability over perfection; minimal diffs; never break old users.
- PHP 7.3 compatibility for all PHP code.
- Skel WWW lockdown: never touch etc/skel/www (or its contents) unless explicitly instructed.
- Treat bundled vendor/third-party trees as read-only unless explicitly approved.
- Do not edit scripts/lib/update/dpkg/selections*.txt without explicit platform sign-off.
- No new external deps/tools/configs unless explicitly approved.
- Prefer established helpers and patterns (runStep(), JSON logging/profile) to keep observability stable.

Refactor tactics (behaviour-preserving):
- Prefer tables/config arrays over branching when behaviour is identical.
- Reduce nesting via guard clauses / early returns / extracted helpers (keep outputs stable).

When proposing or applying changes:
- Declare invariants (3–7 bullets) before editing (CLI output, JSON fields, log markers, ordering, exit codes, paths).
- Do a danger audit for the touched subsystem:
  - /home operations, cron+partial-update windows, shell command composition, internal tool output parsing, dpkg/apt sequencing, structured logging stability.
- Prefer deletion-first only when you can prove unreferenced via repo search; otherwise keep compatibility shims only when absolutely necessary.

Verification expectations (run as applicable after changes):
- php -l on changed PHP files
- php scripts/lib/tests/development/Runner.php
- scripts/testing/php-lint-compat.sh
- scripts/testing/test-php.sh
- scripts/testing/test-bash.sh
- scripts/testing/php73-compat-scan.sh
- If touching scripts/lib/update/**: scripts/testing/doctrine-lint.sh and scripts/testing/docblock-lint.sh

Start by asking what task we are solving, then inspect only the minimal relevant code and docs before editing anything.
PMSSPROMPT
)

prompt_text=${custom_prompt:-$DEFAULT_PROMPT}

{
	echo "$prompt_text"
	echo
	echo "Context to open (paths in this workspace):"
	echo " - AGENTS.md"
	echo " - agents.local.md"
	echo " - docs/architecture.md"
	echo " - docs/update.md"
	echo " - docs/install.md"
	echo " - docs/refactoring.md"
	echo " - docs/contracts.md"
	echo " - docs/adr/"

	if [[ -f "$ROOT/.codex-prompt" ]]; then
		echo
		echo "Local Operator Notes (.codex-prompt):"
		cat "$ROOT/.codex-prompt"
	fi

	echo
	echo "Do not inline these; read them directly from disk."
} >"$PROMPT"

prompt_bytes=$(wc -c <"$PROMPT" | tr -d ' ')
prompt_lines=$(wc -l <"$PROMPT" | tr -d ' ')
echo "[codex] prompt written: $PROMPT (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

if ! command -v "$exec_cmd" >/dev/null 2>&1; then
	echo "[codex] assistant executable not found: $exec_cmd" >&2
	echo "[codex] run manually with codex installed, for example:" >&2
	echo "  codex \"\$(cat '$PROMPT')\"" >&2
	exit 127
fi

echo "[codex] invoking: $exec_cmd [prompt-string]" >&1
"$exec_cmd" "$(cat "$PROMPT")"
