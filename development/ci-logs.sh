#!/usr/bin/env bash
set -euo pipefail

# ci-logs.sh — friendly helper to inspect GitHub Actions runs from your shell
#
# Requires: GitHub CLI (https://cli.github.com/)
#   brew install gh   OR   sudo apt-get install gh
#   gh auth login     (once)
#
# Usage:
#   development/ci-logs.sh latest             # stream logs for the latest run (non-interactive)
#   development/ci-logs.sh smoke              # stream only the 'smoke' job logs from the latest run
#   development/ci-logs.sh job-name <name>    # stream a job by name from the latest run (e.g., build)
#   development/ci-logs.sh last-artifacts     # download all artifacts for the latest run into ./ci-artifacts/
#   development/ci-logs.sh codex [--job <name>] [--prompt "..."] [--exec 'codex --sandbox workspace-write --ask-for-approval untrusted']
#   development/ci-logs.sh run <run-id>       # stream logs for a specific run id
#   development/ci-logs.sh job <job-id>       # stream logs for a specific job id

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
cd "$ROOT"

have() { command -v "$1" >/dev/null 2>&1; }

if ! have gh; then
	echo "[ci-logs] GitHub CLI not found. Install from https://cli.github.com/ then run 'gh auth login'" >&2
	exit 1
fi

latest_run_id() {
	gh run list --limit 1 --json databaseId --jq '.[0].databaseId' 2>/dev/null || true
}

job_id_from_run_by_name() {
	local run_id="$1" name="$2"
	local jobid=""

	# Newer gh versions expose a jobs field directly; older ones do not.
	jobid=$(gh run view "$run_id" --json jobs --jq ".jobs[] | select(.name == \"$name\").databaseId" 2>/dev/null || true)
	if [[ -z "$jobid" ]]; then
		jobid=$(gh run view "$run_id" 2>/dev/null | awk -v want="$name" '
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
		' 2>/dev/null || true)
	fi
	if [[ -z "$jobid" ]]; then
		jobid=$(gh api -H "Accept: application/vnd.github+json" \
			"repos/{owner}/{repo}/actions/runs/$run_id/jobs?per_page=100" \
			--jq ".jobs[] | select(.name == \"$name\").id" 2>/dev/null || true)
	fi
	printf '%s' "$jobid"
}

cmd=${1:-latest}
shift || true

case "$cmd" in
latest)
	echo "[ci-logs] streaming latest run logs (non-interactive)..." >&2
	gh run view --latest --log || {
		echo "[ci-logs] no runs found" >&2
		exit 1
	}
	;;
last-artifacts)
	outdir="${1:-ci-artifacts}"
	mkdir -p "$outdir"
	echo "[ci-logs] downloading artifacts for latest run into $outdir" >&2
	gh run download --latest --dir "$outdir" || {
		echo "[ci-logs] failed to download artifacts" >&2
		exit 1
	}
	ls -la "$outdir" || true
	;;
job-name)
	name=${1:-}
	if [[ -z "$name" ]]; then
		echo "usage: ci-logs.sh job-name <name>" >&2
		exit 2
	fi
	runid=$(latest_run_id)
	if [[ -z "$runid" ]]; then
		echo "[ci-logs] no runs found" >&2
		exit 1
	fi
	jobid=$(job_id_from_run_by_name "$runid" "$name")
	if [[ -z "$jobid" ]]; then
		echo "[ci-logs] job '$name' not found in latest run" >&2
		exit 3
	fi
	gh run view --job "$jobid" --log
	;;
smoke)
	# convenience alias for the 'smoke' job
	runid=$(latest_run_id)
	if [[ -z "$runid" ]]; then
		echo "[ci-logs] no runs found" >&2
		exit 1
	fi
	jobid=$(job_id_from_run_by_name "$runid" "smoke")
	if [[ -z "$jobid" ]]; then
		echo "[ci-logs] smoke job not found in latest run" >&2
		exit 3
	fi
	gh run view --job "$jobid" --log
	;;
run)
	runid=${1:-}
	if [[ -z "$runid" ]]; then
		echo "usage: ci-logs.sh run <run-id>" >&2
		exit 2
	fi
	gh run view "$runid" --log
	;;
job)
	jobid=${1:-}
	if [[ -z "$jobid" ]]; then
		echo "usage: ci-logs.sh job <job-id>" >&2
		exit 2
	fi
	gh run view --job "$jobid" --log
	;;
codex)
	# proxy to codex-ci.sh to assemble prompt and send to your assistant
	bash "$HERE/codex-ci.sh" "$@"
	;;
help | -h | --help)
	sed -n '1,40p' "$0" | sed -n '1,20p'
	;;
*)
	echo "[ci-logs] unknown command: $cmd" >&2
	exit 2
	;;
esac
