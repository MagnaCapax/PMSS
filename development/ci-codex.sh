#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_CI_CODEX_DEBUG=1 enables bash -x tracing
codex_enable_debug PMSS_CI_CODEX_DEBUG "ci-codex"

codex_set_error_trap "ci-codex"

echo "[ci-codex] start: assembling CI context and invoking Codex" >&1

# ci-codex.sh — Fetch latest CI logs and feed them to a coding assistant (Codex CLI or similar).
#
# Requirements:
#   - GitHub CLI (gh) installed and authenticated: gh auth login
# Optional:
#   - A local assistant CLI to receive the prompt. Provide via --exec (e.g., --exec 'codex')
#
# Usage:
#   development/ci-codex.sh                          # assemble prompt + logs into ci-codex/prompt.txt
#   development/ci-codex.sh --job smoke               # include only 'smoke' job logs in the prompt
#   development/ci-codex.sh --prompt "text..."        # use custom high-level prompt text
#   development/ci-codex.sh --exec 'codex'             # send prompt to Codex CLI directly
#
# The default prompt:
#   "Last CI Integration Logs are here. If issues or code fails, please fix them.
#    First read AGENTS.md, docs, and ADRs to understand the rails, and double
#    check TODOs before any code changes. Maintain PHP 7.3 compatibility."

# Create a throwaway workspace under the system temp dir (avoid repo clutter)
TMP="${TMPDIR:-/tmp}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-ci-codex-XXXXXXXX")"
ARTDIR="$OUTDIR/artifacts"
JOBLOG="$OUTDIR/job.log"
RUNLOG="$OUTDIR/job-run.log"
PROMPT="$OUTDIR/prompt.txt"
SUMMARY="$OUTDIR/ci-summary.txt"

# Render caps to keep prompt readable
JOB_LOG_LINES=${JOB_LOG_LINES:-600}
ARTIFACT_LINES=${ARTIFACT_LINES:-200}
MAX_ARTIFACT_FILES=${MAX_ARTIFACT_FILES:-6}
PMSS_CI_WAIT_SECS=${PMSS_CI_WAIT_SECS:-300}

job_name=""
custom_prompt=""
exec_cmd=""
dry_run=0

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
	--dry-run)
		dry_run=1
		shift || true
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
	if [[ "$art_count" -gt 0 || $attempt -ge 10 ]]; then
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

fetch_run_log() {
	local out="$1"
	gh run view "$run_id" --log >"$out" || true
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
	# Refresh logs each pass; sometimes GitHub needs extra time to make them available.
	if [[ -n "$job_name" ]]; then
		fetch_job_log "$job_name" "$JOBLOG"
	else
		fetch_job_log "build" "$OUTDIR/job-build.log"
		fetch_job_log "smoke" "$OUTDIR/job-smoke.log"
	fi

	nonempty_logs=$(codex_count_nonempty_files "$OUTDIR"/job-*.log "$JOBLOG")
	if [[ $nonempty_logs -gt 0 || $attempt -ge 10 ]]; then
		break
	fi
	echo "[ci-codex] job logs not ready (attempt $attempt); waiting…" >&1
	sleep 5
done
echo "[ci-codex] job logs present: $nonempty_logs file(s)" >&1

echo "[ci-codex] fetching full run log" >&1
for attempt in {1..5}; do
	fetch_run_log "$RUNLOG"
	if [[ -s "$RUNLOG" || $attempt -ge 5 ]]; then
		break
	fi
	echo "[ci-codex] run log not ready (attempt $attempt); waiting…" >&1
	sleep 3
done
[[ -s "$RUNLOG" ]] || echo "[ci-codex] WARNING: full run log is empty; check gh auth/network" >&1

# If nothing was retrieved, fail fast so callers notice missing QA context.
any_logs=0
for jl in "$OUTDIR"/job-*.log "$JOBLOG" "$RUNLOG"; do
	[[ -s "$jl" ]] && any_logs=$((any_logs + 1))
done
if [[ $any_logs -eq 0 ]]; then
	echo "[ci-codex] ERROR: no CI logs could be fetched; aborting" >&2
	exit 1
fi

codex_args=(run --prompt-file "$HERE/prompts/ci.txt" --outdir "$OUTDIR" --context "$SUMMARY")
for jl in "$OUTDIR"/job-*.log "$JOBLOG" "$RUNLOG"; do
	[[ -s "$jl" ]] || continue
	codex_args+=(--context "$jl")
done
if [[ -n "$latest_art" ]]; then
	codex_args+=(--context "$latest_art")
fi
if [[ -n "$custom_prompt" ]]; then
	codex_args+=(--prompt "$custom_prompt")
fi
if [[ -n "$exec_cmd" ]]; then
	codex_args+=(--exec "$exec_cmd")
fi
if [[ "$dry_run" == "1" ]]; then
	codex_args+=(--dry-run)
fi
bash "$HERE/codex-run.sh" "${codex_args[@]}"
