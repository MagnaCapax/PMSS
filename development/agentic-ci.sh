#!/usr/bin/env bash
# shellcheck disable=SC2016
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
cd "$ROOT"
# shellcheck disable=SC1091
source "$HERE/lib/codex-common.sh"

# Optional debug: PMSS_CI_CODEX_DEBUG=1 enables bash -x tracing
codex_enable_debug PMSS_CI_CODEX_DEBUG "codex-ci"

codex_set_error_trap "codex-ci"

echo "[codex-ci] start: assembling CI context and invoking Codex" >&1

# codex-ci.sh — Fetch latest CI logs and feed them to a coding assistant (Codex CLI or similar).
#
# Requirements (one of):
#   - GitHub CLI (`gh`) installed and authenticated: gh auth login
#   - `curl` available; for private repos set `GITHUB_TOKEN` (classic PAT with `repo` + `actions:read`)
# Optional:
#   - A local assistant CLI to receive the prompt. Provide via --exec (e.g., --exec 'codex --sandbox workspace-write --ask-for-approval untrusted')
#
# Usage:
#   development/codex-ci.sh                          # assemble prompt + logs into codex-ci/prompt.txt
#   development/codex-ci.sh --job smoke               # include only 'smoke' job logs in the prompt
#   development/codex-ci.sh --prompt "text..."        # use custom high-level prompt text
#   development/codex-ci.sh --exec 'codex --sandbox workspace-write --ask-for-approval untrusted'  # send prompt to Codex CLI directly
#
# The default prompt:
#   "Last CI Integration Logs are here. If issues or code fails, please fix them.
#    First read AGENTS.md, docs, and ADRs to understand the rails, and double
#    check TODOs before any code changes. Maintain PHP 7.3 compatibility."

usage() {
	cat <<EOF
Usage:
  development/agentic-ci.sh [options]

Purpose:
  Fetch the latest CI logs/artifacts and feed them to the assistant prompt.

Options:
  --job NAME     Only include logs for jobs matching NAME
  --prompt TEXT  Override the default CI prompt text
  --agent NAME   Assistant profile (default: ${default_agent})
  --exec CMD     Override assistant command line
  --dry-run      Skip GitHub API/downloads; only assemble prompt scaffolding
  --autocommit   Enable autocommit rules in the prompt (operator-approved)
  -h, --help     Show this help

Requirements:
  - GitHub CLI (gh) authenticated, or curl + GITHUB_TOKEN for private repos.

Environment:
  PMSS_AGENTIC_DEFAULT_AGENT  Default agent when --agent is omitted
  PMSS_CI_CODEX_DEBUG=1       Enable bash -x tracing
  GITHUB_TOKEN               GitHub token for API access (private repos)
  JOB_LOG_LINES              Max lines of job logs (default: ${JOB_LOG_LINES})
  ARTIFACT_LINES             Max lines per artifact (default: ${ARTIFACT_LINES})
  MAX_ARTIFACT_FILES         Max artifact files to include (default: ${MAX_ARTIFACT_FILES})
  PMSS_CI_WAIT_SECS          Wait for run completion (default: ${PMSS_CI_WAIT_SECS})

Examples:
  development/agentic-ci.sh
  development/agentic-ci.sh --job smoke
  development/agentic-ci.sh --prompt "Fix CI failures"
  development/agentic-ci.sh --agent=codex --dry-run
EOF
}

# Create a throwaway workspace under the system temp dir (avoid repo clutter)
TMP="${TMPDIR:-/tmp}"
ASSIST_DIR="$HERE/assistants"
default_agent="${PMSS_AGENTIC_DEFAULT_AGENT:-codex}"
OUTDIR="$(mktemp -d "${TMP%/}/pmss-codex-ci-XXXXXXXX")"
ARTDIR="$OUTDIR/artifacts"
JOBLOG="$OUTDIR/job.log"
RUNLOG="$OUTDIR/job-run.log"
SUMMARY="$OUTDIR/ci-summary.txt"

# Render caps to keep prompt readable
JOB_LOG_LINES=${JOB_LOG_LINES:-600}
ARTIFACT_LINES=${ARTIFACT_LINES:-200}
MAX_ARTIFACT_FILES=${MAX_ARTIFACT_FILES:-6}
PMSS_CI_WAIT_SECS=${PMSS_CI_WAIT_SECS:-300}

job_name=""
agent=""
custom_prompt=""
exec_cmd=""
dry_run=0
autocommit=0

while [[ $# -gt 0 ]]; do
	case "$1" in
	--agent | --agent=*)
		codex_parse_option_value agent "$1" "${2:-}" "--agent" 1
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--job)
		codex_parse_option_value job_name "$1" "${2:-}" "--job"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--prompt)
		codex_parse_option_value custom_prompt "$1" "${2:-}" "--prompt"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--exec)
		codex_parse_option_value exec_cmd "$1" "${2:-}" "--exec"
		shift "$CODEX_PARSE_SHIFT" || true
		;;
	--dry-run | --autocommit)
		if [[ "$1" == "--dry-run" ]]; then
			dry_run=1
		else
			autocommit=1
		fi
		shift || true
		;;
	-h | --help)
		usage
		exit 0
		;;
	*)
		echo "[codex-ci] unknown option: $1" >&2
		exit 2
		;;
	esac
done

if [[ -z "$agent" ]]; then
	agent="$default_agent"
fi

exec_cmd="$(codex_resolve_exec_cmd "$ASSIST_DIR" "$agent" "$exec_cmd")" || exit $?

# Pre-flight: skip session entirely if CI is already green (saves tokens on no-op runs)
if [[ "$autocommit" == "1" && "$dry_run" == "0" ]]; then
	latest_conclusion=$(gh run list --limit 1 --json conclusion --jq '.[0].conclusion' 2>/dev/null || true)
	if [[ "$latest_conclusion" == "success" ]]; then
		echo "[codex-ci] CI is green. Nothing to fix. Skipping." >&1
		exit 0
	fi
	echo "[codex-ci] CI conclusion: ${latest_conclusion:-unknown} — proceeding with fix pass" >&1
fi

have() { command -v "$1" >/dev/null 2>&1; }

mkdir -p "$OUTDIR" "$ARTDIR"

echo "[codex-ci] workspace: $OUTDIR" >&1
echo "[codex-ci] artifact dir: $ARTDIR" >&1

if [[ "$dry_run" == "1" ]]; then
	echo "[codex-ci] dry-run: skipping GitHub API, artifact download, and log fetch" >&1
	echo "[codex-ci] dry-run: would run (best-effort):" >&1
	echo "  gh run list / gh run view / gh run download / GitHub API curl fallback" >&1

	{
		echo "[codex-ci] dry-run: this run only assembles the prompt scaffolding"
		echo
		echo "Selected agent: $agent"
		echo "Selected exec: $exec_cmd"
		[[ -n "$job_name" ]] && echo "Requested job: $job_name"
	} >"$SUMMARY"

	codex_args=(run --prompt-file "$HERE/prompts/ci.txt" --outdir "$OUTDIR" --context "$SUMMARY" --dry-run)
	codex_append_runner_args codex_args "$ROOT" "$agent" "$exec_cmd" 0 "$autocommit" "$custom_prompt"

	bash "$HERE/codex-run.sh" "${codex_args[@]}"
	exit 0
fi

CI_CODEX_SAVED_XTRACE=0
ci_shell_disable_xtrace() {
	CI_CODEX_SAVED_XTRACE=0
	case "$-" in
	*x*)
		CI_CODEX_SAVED_XTRACE=1
		set +x
		;;
	esac
}

ci_shell_restore_xtrace() {
	if [[ "${CI_CODEX_SAVED_XTRACE:-0}" == "1" ]]; then
		set -x
	fi
	CI_CODEX_SAVED_XTRACE=0
}

ci_parse_github_repo() {
	local url="$1"
	url="${url%.git}"
	if [[ "$url" =~ ^git@github[.]com:(.+/.+)$ ]]; then
		echo "${BASH_REMATCH[1]}"
		return 0
	fi
	if [[ "$url" =~ ^https?://github[.]com/(.+/.+)$ ]]; then
		echo "${BASH_REMATCH[1]}"
		return 0
	fi
	if [[ "$url" =~ ^ssh://git@github[.]com/(.+/.+)$ ]]; then
		echo "${BASH_REMATCH[1]}"
		return 0
	fi
	return 1
}

ci_validate_github_repo() {
	local repo="$1"
	[[ "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]
}

ci_api_get_json() {
	local url="$1"
	ci_shell_disable_xtrace
	local token="${GITHUB_TOKEN:-}"
	if [[ -n "$token" ]]; then
		local auth_header="Authorization: token $token"
		if [[ "$token" == github_pat_* ]]; then
			auth_header="Authorization: Bearer $token"
		fi
		curl -fsSL \
			-H "$auth_header" \
			-H "Accept: application/vnd.github+json" \
			-H "X-GitHub-Api-Version: 2022-11-28" \
			"$url"
	else
		curl -fsSL \
			-H "Accept: application/vnd.github+json" \
			-H "X-GitHub-Api-Version: 2022-11-28" \
			"$url"
	fi
	local rc=$?
	ci_shell_restore_xtrace
	return $rc
}

ci_api_download_zip() {
	local url="$1" out_zip="$2"
	local headers="$OUTDIR/ci-download.headers"
	local location=""
	local rc=0

	ci_shell_disable_xtrace
	local token="${GITHUB_TOKEN:-}"

	: >"$headers"
	rm -f "$out_zip" >/dev/null 2>&1 || true
	if [[ -n "$token" ]]; then
		local auth_header="Authorization: token $token"
		if [[ "$token" == github_pat_* ]]; then
			auth_header="Authorization: Bearer $token"
		fi
		curl -fsS \
			-H "$auth_header" \
			-H "Accept: application/vnd.github+json" \
			-H "X-GitHub-Api-Version: 2022-11-28" \
			-D "$headers" \
			-o "$out_zip" \
			"$url" || rc=$?
	else
		curl -fsS \
			-H "Accept: application/vnd.github+json" \
			-H "X-GitHub-Api-Version: 2022-11-28" \
			-D "$headers" \
			-o "$out_zip" \
			"$url" || rc=$?
	fi

	location="$(awk 'BEGIN{IGNORECASE=1} $0 ~ /^location:/ { sub(/^location:[ \t]*/, "", $0); gsub("\r", "", $0); print $0; exit }' "$headers" 2>/dev/null || true)"
	ci_shell_restore_xtrace

	if [[ $rc -ne 0 ]]; then
		rm -f "$out_zip" >/dev/null 2>&1 || true
		return $rc
	fi

	if [[ -n "$location" ]]; then
		curl -fsSL "$location" -o "$out_zip" || return 1
	fi
	if [[ ! -s "$out_zip" ]]; then
		return 1
	fi

	# Defensive: GitHub API failures can still produce a non-empty JSON body. Ensure
	# the output looks like a zip before callers try to unzip it.
	head -c 2 "$out_zip" 2>/dev/null | grep -q '^PK'
}

ci_unzip_to_file() {
	local zip="$1" out="$2"
	local tmp_extract
	tmp_extract="$(mktemp -d "${TMP%/}/pmss-ci-zip-XXXXXXXX")"
	if unzip -qq "$zip" -d "$tmp_extract" >/dev/null 2>&1; then
		find "$tmp_extract" -type f 2>/dev/null | sort | while read -r f; do
			printf '\n=== %s ===\n' "$(basename "$f")"
			cat "$f"
			printf '\n'
		done >"$out"
	fi
	rm -rf "$tmp_extract" >/dev/null 2>&1 || true
	[[ -s "$out" ]]
}

github_token_present=0
ci_shell_disable_xtrace
[[ -n "${GITHUB_TOKEN:-}" ]] && github_token_present=1
ci_shell_restore_xtrace
if [[ "$github_token_present" == "1" ]]; then
	case "$-" in
	*x*)
		echo "[codex-ci] NOTE: disabling xtrace to avoid leaking GITHUB_TOKEN" >&1
		set +x
		;;
	esac
fi

fetch_mode="gh"
if have gh; then
	echo "[codex-ci] gh: $(command -v gh)" >&1 || true
	gh --version 2>/dev/null | sed 's/^/[codex-ci] /' >&1 || true
elif have curl; then
	fetch_mode="curl"
	echo "[codex-ci] gh not found; using GitHub API via curl (set GITHUB_TOKEN for private repos)" >&1
else
	echo "[codex-ci] ERROR: neither 'gh' nor 'curl' is available; cannot fetch CI logs" >&2
	exit 1
fi

run_id=""
repo_full=""
api_base="https://api.github.com"

if [[ "$fetch_mode" == "gh" ]]; then
	origin_url="$(git config --get remote.origin.url 2>/dev/null || true)"
	repo_full="$(ci_parse_github_repo "$origin_url" 2>/dev/null || true)"
	if ! ci_validate_github_repo "$repo_full"; then
		repo_full=""
	fi

	echo "[codex-ci] discovering latest run..." >&1
	run_id=$(gh run list --limit 1 --json databaseId --jq '.[0].databaseId')
else
	origin_url="$(git config --get remote.origin.url 2>/dev/null || true)"
	repo_full="$(ci_parse_github_repo "$origin_url" 2>/dev/null || true)"
	if ! ci_validate_github_repo "$repo_full"; then
		echo "[codex-ci] ERROR: could not derive GitHub repo from origin URL: $origin_url" >&2
		echo "[codex-ci] Install gh (recommended) or set origin to a github.com remote." >&2
		exit 1
	fi

	echo "[codex-ci] discovering latest run via API for $repo_full..." >&1
	runs_json="$(ci_api_get_json "$api_base/repos/$repo_full/actions/runs?per_page=1" 2>/dev/null || true)"
	run_id="$(printf '%s' "$runs_json" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["workflow_runs"][0]["id"] ?? "";' 2>/dev/null || true)"
fi

if [[ -z "$run_id" ]]; then
	echo "[codex-ci] no workflow runs found (or auth missing)" >&2
	if [[ "$fetch_mode" == "curl" && "$github_token_present" == "0" ]]; then
		echo "[codex-ci] Hint: set GITHUB_TOKEN (classic PAT with repo+actions read) for private repos." >&2
	fi
	exit 1
fi

echo "[codex-ci] latest run id: $run_id" >&1

echo "[codex-ci] waiting for run completion (timeout ${PMSS_CI_WAIT_SECS}s)…" >&1
deadline=$(($(date +%s) + PMSS_CI_WAIT_SECS))
if [[ "$fetch_mode" == "gh" ]]; then
	status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
else
	status="$(ci_api_get_json "$api_base/repos/$repo_full/actions/runs/$run_id" 2>/dev/null |
		php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["status"] ?? "";' 2>/dev/null || true)"
	[[ -n "$status" ]] || status="queued"
fi
while [[ "$status" != "completed" && $(date +%s) -lt $deadline ]]; do
	echo "[codex-ci] run status: $status (waiting)" >&1
	sleep 5
	if [[ "$fetch_mode" == "gh" ]]; then
		status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
	else
		status="$(ci_api_get_json "$api_base/repos/$repo_full/actions/runs/$run_id" 2>/dev/null |
			php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["status"] ?? "";' 2>/dev/null || true)"
		[[ -n "$status" ]] || status="queued"
	fi
done
echo "[codex-ci] run status now: $status" >&1

# Download artifacts (best-effort)
echo "[codex-ci] downloading artifacts to $ARTDIR" >&1
mkdir -p "$ARTDIR"
art_count=0
for attempt in {1..10}; do
	if [[ "$fetch_mode" == "gh" ]]; then
		gh run download "$run_id" --dir "$ARTDIR" >/dev/null 2>&1 || true
	else
		arts_json="$(ci_api_get_json "$api_base/repos/$repo_full/actions/runs/$run_id/artifacts?per_page=100" 2>/dev/null || true)"
		printf '%s' "$arts_json" | php -r '
			$j=json_decode(stream_get_contents(STDIN), true);
			foreach (($j["artifacts"] ?? []) as $a) {
				$id=$a["id"] ?? "";
				$name=$a["name"] ?? "";
				if ($id && $name) { echo $id . "\t" . $name . "\n"; }
			}
		' 2>/dev/null | while IFS=$'\t' read -r art_id art_name; do
			[[ -n "$art_id" && -n "$art_name" ]] || continue
			safe_name="$(printf '%s' "$art_name" | tr -cs 'A-Za-z0-9_.-' '_' | sed 's/^_\\+//; s/_\\+$//')"
			zip_path="$OUTDIR/artifact-${art_id}.zip"
			extract_dir="$ARTDIR/${safe_name:-artifact-$art_id}"
			mkdir -p "$extract_dir"
			if ci_api_download_zip "$api_base/repos/$repo_full/actions/artifacts/$art_id/zip" "$zip_path" >/dev/null 2>&1; then
				unzip -qq "$zip_path" -d "$extract_dir" >/dev/null 2>&1 || true
			fi
		done
	fi

	art_count=$(find "$ARTDIR" -type f 2>/dev/null | wc -l | tr -d ' ')
	if [[ "$art_count" -gt 0 || $attempt -ge 10 ]]; then
		break
	fi
	echo "[codex-ci] artifacts not ready (attempt $attempt); waiting…" >&1
	sleep 5
done
echo "[codex-ci] artifacts downloaded: $art_count file(s)" >&1

# Prepare CI summary and capture latest artifact path for reference
latest_art=""
if [[ "$fetch_mode" == "gh" ]]; then
	gh run view "$run_id" >"$SUMMARY" || true
else
	run_json="$(ci_api_get_json "$api_base/repos/$repo_full/actions/runs/$run_id" 2>/dev/null || true)"
	printf '%s' "$run_json" | php -r '
		$j=json_decode(stream_get_contents(STDIN), true);
		$fields=[
			"workflow" => $j["name"] ?? "",
			"status" => $j["status"] ?? "",
			"conclusion" => $j["conclusion"] ?? "",
			"event" => $j["event"] ?? "",
			"branch" => $j["head_branch"] ?? "",
			"sha" => $j["head_sha"] ?? "",
			"url" => $j["html_url"] ?? "",
			"created_at" => $j["created_at"] ?? "",
			"updated_at" => $j["updated_at"] ?? "",
		];
		foreach ($fields as $k => $v) { if ($v !== "") { echo $k . ": " . $v . "\n"; } }
	' 2>/dev/null >"$SUMMARY" || true
	[[ -s "$SUMMARY" ]] || printf 'repo: %s\nrun_id: %s\n' "$repo_full" "$run_id" >"$SUMMARY"
fi
if compgen -G "$ARTDIR/*" >/dev/null; then
	latest_art=$(find "$ARTDIR" -type f -printf '%T@ %p\n' | sort -nr | head -n1 | cut -d' ' -f2-)
fi

# Optionally capture a specific job log
# Fetch logs for a requested job, or both 'build' and 'smoke' by default
fetch_job_log() {
	local name="$1" out="$2"
	if [[ "$fetch_mode" == "gh" ]]; then
		local id jobs_json job_id zip_path token

		# Newer gh versions expose a jobs field directly; older ones do not.
		id="$(gh run view "$run_id" --json jobs --jq ".jobs[] | select(.name == \"$name\").databaseId" 2>/dev/null || true)"

		# Fallback for older gh: query the REST API for job ids, then fetch logs.
		if [[ -z "$id" && -n "$repo_full" ]]; then
			jobs_json="$(gh api -H "Accept: application/vnd.github+json" "/repos/$repo_full/actions/runs/$run_id/jobs" 2>/dev/null || true)"
			if [[ -z "$jobs_json" ]]; then
				jobs_json="$(gh api -H "Accept: application/vnd.github+json" "repos/$repo_full/actions/runs/$run_id/jobs" 2>/dev/null || true)"
			fi
			# shellcheck disable=SC2034
			PMSS_CI_JOB_NAME="$name" job_id="$(printf '%s' "$jobs_json" | php -r '
					$j=json_decode(stream_get_contents(STDIN), true);
					$name=getenv("PMSS_CI_JOB_NAME") ?: "";
					foreach (($j["jobs"] ?? []) as $job) {
						if (($job["name"] ?? "") === $name) { echo $job["id"] ?? ""; break; }
					}
				' 2>/dev/null || true)"
			id="$job_id"
		fi

		# Last resort for old gh: parse job ids from the plain `gh run view` summary we already captured.
		if [[ -z "$id" && -s "$SUMMARY" ]]; then
			id="$(awk -v want="$name" '
					{
						line=$0
						sub(/^[^A-Za-z0-9_-]+[[:space:]]*/, "", line)
						split(line, parts, /[[:space:]]+/)
						if (parts[1] != want) next
						if (match($0, /\(ID[[:space:]]+[0-9]+\)/)) {
							id=substr($0, RSTART, RLENGTH)
							gsub(/[^0-9]/, "", id)
							print id
							exit
						}
					}
				' "$SUMMARY" 2>/dev/null || true)"
		fi

		if [[ -n "$id" ]]; then
			gh run view --job "$id" --log >"$out" 2>/dev/null || true
		fi

		# If gh cannot emit job logs, fall back to downloading the zipped REST logs.
		if [[ -n "$id" && ! -s "$out" && -n "$repo_full" ]]; then
			zip_path="$OUTDIR/job-${id}.zip"
			token="${GITHUB_TOKEN:-}"
			if [[ -z "$token" ]]; then
				ci_shell_disable_xtrace
				# gh 2.4.x lacks `gh auth token`; fall back to parsing `gh auth status --show-token`.
				token="$(gh auth token 2>/dev/null || true)"
				if [[ -z "$token" ]]; then
					# gh auth status prints to stderr and prefixes status lines with symbols (✓),
					# so match any line containing "Token:" and grab the last field.
					token="$(gh auth status --show-token 2>&1 |
						awk 'BEGIN{IGNORECASE=1} /token:[[:space:]]/ {print $NF; exit}' || true)"
				fi
				ci_shell_restore_xtrace
			fi
			if [[ -n "$token" ]]; then
				ci_shell_disable_xtrace
				if GITHUB_TOKEN="$token" ci_api_download_zip "$api_base/repos/$repo_full/actions/jobs/$id/logs" "$zip_path" >/dev/null 2>&1; then
					ci_unzip_to_file "$zip_path" "$out" >/dev/null 2>&1 || true
				fi
				ci_shell_restore_xtrace
			fi
		fi
		return 0
	fi

	local jobs_json job_id zip_path
	jobs_json="$(ci_api_get_json "$api_base/repos/$repo_full/actions/runs/$run_id/jobs?per_page=100" 2>/dev/null || true)"
	# shellcheck disable=SC2034
	PMSS_CI_JOB_NAME="$name" job_id="$(printf '%s' "$jobs_json" | php -r '
		$j=json_decode(stream_get_contents(STDIN), true);
		$name=getenv("PMSS_CI_JOB_NAME") ?: "";
		foreach (($j["jobs"] ?? []) as $job) {
			if (($job["name"] ?? "") === $name) { echo $job["id"] ?? ""; break; }
		}
	' 2>/dev/null || true)"
	[[ -n "$job_id" ]] || return 0
	zip_path="$OUTDIR/job-${job_id}.zip"
	if ci_api_download_zip "$api_base/repos/$repo_full/actions/jobs/$job_id/logs" "$zip_path" >/dev/null 2>&1; then
		ci_unzip_to_file "$zip_path" "$out" >/dev/null 2>&1 || true
	fi
}

fetch_run_log() {
	local out="$1"
	if [[ "$fetch_mode" == "gh" ]]; then
		local zip_path token

		# gh 2.4.x often emits empty output for `--log` when stdout is redirected.
		gh run view "$run_id" --log >"$out" 2>/dev/null || true
		if [[ -s "$out" ]]; then
			return 0
		fi

		[[ -n "$repo_full" ]] || return 0
		zip_path="$OUTDIR/run-${run_id}.zip"
		token="${GITHUB_TOKEN:-}"
		if [[ -z "$token" ]]; then
			ci_shell_disable_xtrace
			token="$(gh auth status --show-token 2>&1 |
				awk 'BEGIN{IGNORECASE=1} /token:[[:space:]]/ {print $NF; exit}' || true)"
			ci_shell_restore_xtrace
		fi
		if [[ -n "$token" ]]; then
			ci_shell_disable_xtrace
			if GITHUB_TOKEN="$token" ci_api_download_zip "$api_base/repos/$repo_full/actions/runs/$run_id/logs" "$zip_path" >/dev/null 2>&1; then
				ci_unzip_to_file "$zip_path" "$out" >/dev/null 2>&1 || true
			fi
			ci_shell_restore_xtrace
		fi
		return 0
	fi
	local zip_path
	zip_path="$OUTDIR/run-${run_id}.zip"
	if ci_api_download_zip "$api_base/repos/$repo_full/actions/runs/$run_id/logs" "$zip_path" >/dev/null 2>&1; then
		ci_unzip_to_file "$zip_path" "$out" >/dev/null 2>&1 || true
	fi
}

if [[ -n "$job_name" ]]; then
	echo "[codex-ci] fetching job logs for '$job_name'" >&1
	fetch_job_log "$job_name" "$JOBLOG"
else
	echo "[codex-ci] fetching job logs for 'build' and 'smoke'" >&1
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
	echo "[codex-ci] job logs not ready (attempt $attempt); waiting…" >&1
	sleep 5
done
echo "[codex-ci] job logs present: $nonempty_logs file(s)" >&1

echo "[codex-ci] fetching full run log" >&1
for attempt in {1..5}; do
	fetch_run_log "$RUNLOG"
	if [[ -s "$RUNLOG" || $attempt -ge 5 ]]; then
		break
	fi
	echo "[codex-ci] run log not ready (attempt $attempt); waiting…" >&1
	sleep 3
done
[[ -s "$RUNLOG" ]] || echo "[codex-ci] WARNING: full run log is empty; check gh auth/network" >&1

# If nothing was retrieved, fail fast so callers notice missing QA context.
any_logs=0
for jl in "$OUTDIR"/job-*.log "$JOBLOG" "$RUNLOG"; do
	[[ -s "$jl" ]] && any_logs=$((any_logs + 1))
done
if [[ $any_logs -eq 0 ]]; then
	echo "[codex-ci] ERROR: no CI logs could be fetched; aborting" >&2
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
codex_append_runner_args codex_args "$ROOT" "$agent" "$exec_cmd" "$dry_run" "$autocommit" "$custom_prompt"
bash "$HERE/codex-run.sh" "${codex_args[@]}"
