#!/usr/bin/env bash
set -euo pipefail
set -o errtrace
# Optional debug: PMSS_CI_CODEX_DEBUG=1 enables bash -x tracing
if [[ "${PMSS_CI_CODEX_DEBUG:-0}" == "1" ]]; then
	export PS4='[ci-codex:trace] '
	set -x
fi

# Predeclare for shellcheck: assigned in trap context
rc=0

trap 'rc=$?; echo "[ci-codex] ERROR rc=$rc at line $LINENO while: $BASH_COMMAND" >&1' ERR

echo "[ci-codex] start: assembling CI context and invoking Codex" >&1

# ci-codex.sh — Fetch latest CI logs and feed them to a coding assistant (Codex CLI or similar).
#
# Requirements:
#   - GitHub CLI (gh) installed and authenticated: gh auth login
# Optional:
#   - A local assistant CLI to receive the prompt. Provide via --exec (e.g., --exec 'codex')
#
# Usage:
#   scripts/cli/ci-codex.sh                          # assemble prompt + logs into ci-codex/prompt.txt
#   scripts/cli/ci-codex.sh --job smoke               # include only 'smoke' job logs in the prompt
#   scripts/cli/ci-codex.sh --prompt "text..."        # use custom high-level prompt text
#   scripts/cli/ci-codex.sh --exec 'codex'             # send prompt to Codex CLI directly
#
# The default prompt:
#   "Last CI Integration Logs are here. If issues or code fails, please fix them.
#    First read AGENTS.md, docs, and ADRs to understand the rails, and double
#    check TODOs before any code changes. Maintain PHP 7.3 compatibility."

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
# Create a throwaway workspace under the system temp dir (avoid repo clutter)
TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-ci-codex-XXXXXXXX")"
ARTDIR="$OUTDIR/artifacts"
JOBLOG="$OUTDIR/job.log"
PROMPT="$OUTDIR/prompt.txt"
SUMMARY="$OUTDIR/ci-summary.txt"

# Render caps to keep prompt readable
JOB_LOG_LINES=${JOB_LOG_LINES:-600}
ARTIFACT_LINES=${ARTIFACT_LINES:-200}
MAX_ARTIFACT_FILES=${MAX_ARTIFACT_FILES:-6}
PMSS_CI_WAIT_SECS=${PMSS_CI_WAIT_SECS:-300}

DEFAULT_PROMPT=$(
	cat <<'PMSSPROMPT'
PMSS CI Assist — Strict Rails Mode

Goal: Make required CI jobs pass with the smallest coherent change.

Read First (ABSOLUTE — do not proceed until read):
- AGENTS.md (entire file; rails/Constitution/Doctrine).
- docs/architecture.md and docs/update.md (provisioning flow, updater topology).
- docs/adr/* (governing decisions; when present, they apply).

Must Follow:
- PHP 7.3 compatibility for all PHP runtime code.
- Updater topology: install.sh → scripts/update.php (bootstrap/minimal) → scripts/util/update-step2.php (orchestration/profiling).
- Distro detection: use scripts/lib/update/distro.php::pmssDetectDistro(); trust VERSION_CODENAME; allowed env overrides: PMSS_OS_RELEASE_PATH, PMSS_APT_SOURCES_PATH.
- Do not modify etc/skel/www/ (and subpaths), third-party/vendor bundles, or scripts/lib/update/dpkg/selections*.txt.
- Language/deps: Bash-first; PHP for complex flows; do not introduce Python; avoid new dependencies without explicit approval.
- Doctrine: Deletion‑First; Minimal Edits; One Flow; Pit of Success; No Aliases; Context‑First naming (domain→action); Single‑Method Consistency; Separation of Concerns; Idempotence; Fail‑Soft where safe.
- Observability: route shelling through runStep(); prefer structured logs; use PMSS_JSON_LOG and PMSS_PROFILE_OUTPUT when applicable.
- Tests (dev): hermetic by default (no real network/system modification); prefer PMSS_TEST_MODE=1 to remove jitter.

Absolutes:
- NEVER CREATE GIT BRANCHES.
- Commit the fix after applying it: stage all and commit with a clear, focused message (e.g., `git add -A && git commit -m "ci: <scope> — <short reason>"`). Do not push.
- ABSOLUTELY NO ZFS — unacceptable for our workload (poor 100% random I/O performance and elevated data-loss risk). Do not propose or introduce it here.
- Keep diffs small, idempotent, backward‑compatible; reuse existing helpers; no aliases; context‑first naming.
 
Workflow (Do This Now):
- Triage the first failing required job/step using the CI summary and job log tails below.
- Implement the smallest coherent fix; do not touch frozen paths; reuse existing helpers and keep changes minimal.
- Verify locally:
  * php -l <each changed PHP file>
  * php scripts/lib/tests/development/Runner.php
  * scripts/testing/test-php.sh
  * scripts/testing/test-bash.sh
  * scripts/testing/php73-compat-scan.sh
- From the CI logs, locate the latest LOC snapshot emitted by scripts/testing/loc.sh (look for "PMSS lines of code (excluding third-party trees)" and the advisory complexity sections) and use that to understand category growth and hotspots; do not re-run loc.sh unless explicitly requested.
- Commit the fix (no branches, no push):
  * git add -A && git commit -m "ci: <scope> — <short reason>"

Proceed to triage the CI summary, job logs, and artifacts. Propose a minimal patch, the verification commands you ran, and a brief summary of the latest LOC snapshot from the CI logs (key category totals and any notable hotspots based on the loc.sh output).
PMSSPROMPT
)

job_name=""
custom_prompt=""
exec_cmd=""

while [[ $# -gt 0 ]]; do
	case "$1" in
	--job)
		job_name=${2:-}
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
	-h | --help)
		sed -n '1,60p' "$0"
		exit 0
		;;
	*)
		echo "[ci-codex] unknown option: $1" >&2
		exit 2
		;;
	esac
done

have() { command -v "$1" >/dev/null 2>&1; }

if ! have gh; then
	echo "[ci-codex] GitHub CLI not found. Install gh and run 'gh auth login'" >&1
	exit 1
fi

echo "[ci-codex] gh: $(command -v gh)" >&1 || true
gh --version 2>/dev/null | sed 's/^/[ci-codex] /' >&1 || true

mkdir -p "$OUTDIR" "$ARTDIR"

echo "[ci-codex] workspace: $OUTDIR" >&1
echo "[ci-codex] artifact dir: $ARTDIR" >&1

echo "[ci-codex] discovering latest run..." >&1
run_id=$(gh run list --limit 1 --json databaseId --jq '.[0].databaseId')
if [[ -z "$run_id" ]]; then
	echo "[ci-codex] no workflow runs found" >&2
	exit 1
fi

echo "[ci-codex] latest run id: $run_id" >&1

echo "[ci-codex] waiting for run completion (timeout ${PMSS_CI_WAIT_SECS}s)…" >&1
status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
deadline=$(($(date +%s) + PMSS_CI_WAIT_SECS))
while [[ "$status" != "completed" && $(date +%s) -lt $deadline ]]; do
	echo "[ci-codex] run status: $status (waiting)" >&1
	sleep 5
	status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
done
echo "[ci-codex] run status now: $status" >&1

# Download artifacts (best-effort)
echo "[ci-codex] downloading artifacts to $ARTDIR" >&1
mkdir -p "$ARTDIR"
art_count=0
for attempt in {1..10}; do
	if gh run download "$run_id" --dir "$ARTDIR" >/dev/null 2>&1; then
		:
	fi
	art_count=$(find "$ARTDIR" -type f 2>/dev/null | wc -l | tr -d ' ')
	if [[ "$art_count" -gt 0 || "$status" == "completed" && $attempt -ge 3 ]]; then
		break
	fi
	echo "[ci-codex] artifacts not ready (attempt $attempt); waiting…" >&1
	sleep 5
done
echo "[ci-codex] artifacts downloaded: $art_count file(s)" >&1

# Prepare CI summary and capture latest artifact path for reference
gh run view "$run_id" >"$SUMMARY" || true
latest_art=""
if compgen -G "$ARTDIR/*" >/dev/null; then
	latest_art=$(find "$ARTDIR" -type f -printf '%T@ %p\n' | sort -nr | head -n1 | cut -d' ' -f2-)
fi

# Optionally capture a specific job log
# Fetch logs for a requested job, or both 'build' and 'smoke' by default
fetch_job_log() {
	local name="$1" out="$2"
	local id
	id=$(gh run view "$run_id" --json jobs --jq ".jobs[] | select(.name == \"$name\").databaseId") || true
	if [[ -n "$id" ]]; then
		gh run view --job "$id" --log >"$out" || true
	fi
}

if [[ -n "$job_name" ]]; then
	echo "[ci-codex] fetching job logs for '$job_name'" >&1
	fetch_job_log "$job_name" "$JOBLOG"
else
	echo "[ci-codex] fetching job logs for 'build' and 'smoke'" >&1
	fetch_job_log "build" "$OUTDIR/job-build.log"
	fetch_job_log "smoke" "$OUTDIR/job-smoke.log"
fi
nonempty_logs=0
for attempt in {1..10}; do
	nonempty_logs=0
	for jl in "$OUTDIR"/job-*.log "$JOBLOG"; do
		[[ -s "$jl" ]] && nonempty_logs=$((nonempty_logs + 1))
	done
	if [[ $nonempty_logs -gt 0 || "$status" == "completed" && $attempt -ge 3 ]]; then
		break
	fi
	echo "[ci-codex] job logs not ready (attempt $attempt); waiting…" >&1
	sleep 5
	# re-fetch to refresh
	if [[ -n "$job_name" ]]; then
		fetch_job_log "$job_name" "$JOBLOG"
	else
		fetch_job_log "build" "$OUTDIR/job-build.log"
		fetch_job_log "smoke" "$OUTDIR/job-smoke.log"
	fi
done
echo "[ci-codex] job logs present: $nonempty_logs file(s)" >&1

# Build the prompt file (header/instructions only). Context paths are listed for the assistant to open.
prompt_text=${custom_prompt:-$DEFAULT_PROMPT}
{
	echo "$prompt_text"
	echo
	echo "Context to open (paths in this workspace):"
	echo " - $SUMMARY (CI summary)"
	for jl in "$OUTDIR"/job-*.log "$JOBLOG"; do
		[[ -s "$jl" ]] || continue
		echo " - $jl"
	done
	if [[ -n "$latest_art" ]]; then
		echo " - $latest_art (newest artifact file)"
	fi
	echo
	echo "Do not inline these; read them directly from disk."
} >"$PROMPT"

prompt_bytes=$(wc -c <"$PROMPT" | tr -d ' ')
prompt_lines=$(wc -l <"$PROMPT" | tr -d ' ')
echo "[ci-codex] prompt written: $PROMPT (${prompt_bytes} bytes, ${prompt_lines} lines)" >&1

# Invoke Codex with the main prompt string only; the prompt lists the file paths to read
prompt_str=$(cat "$PROMPT")
if [[ -n "$exec_cmd" && "$exec_cmd" != "codex" ]]; then
	echo "[ci-codex] unsupported --exec value ('$exec_cmd'); defaulting to 'codex'" >&1
fi
if command -v codex >/dev/null 2>&1; then
	echo "[ci-codex] invoking: codex [prompt-string]" >&1
	codex "$prompt_str" || {
		echo "[ci-codex] codex invocation failed. Run manually:" >&1
		echo "  codex \"\$(cat '$PROMPT')\"" >&1
		exit 1
	}
else
	echo "[ci-codex] Codex CLI not found. Run manually:" >&1
	echo "  codex \"\$(cat '$PROMPT')\"" >&1
fi

# Auto-commit any changes created by the assistant (no branches, no push)
PMSS_CI_AUTOCOMMIT=${PMSS_CI_AUTOCOMMIT:-1}
if [[ "$PMSS_CI_AUTOCOMMIT" == "1" ]]; then
	if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		echo "[ci-codex] auto-commit: checking for changes" >&1
		if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
			msg="ci-codex: apply assistant changes for run $run_id"
			git -C "$ROOT" add -A
			git -C "$ROOT" commit -m "$msg" && echo "[ci-codex] auto-commit: committed changes" >&1 || echo "[ci-codex] auto-commit: commit failed" >&1
		else
			echo "[ci-codex] auto-commit: no changes to commit" >&1
		fi
	else
		echo "[ci-codex] auto-commit: git not available or not inside a repo" >&1
	fi
else
	echo "[ci-codex] auto-commit disabled (PMSS_CI_AUTOCOMMIT=0)" >&1
fi
