#!/usr/bin/env bash
set -euo pipefail

# ci-logs.sh — friendly helper to inspect GitHub Actions runs from your shell
#
# Requires: GitHub CLI (https://cli.github.com/)
#   brew install gh   OR   sudo apt-get install gh
#   gh auth login     (once)
#
# Usage:
#   scripts/cli/ci-logs.sh latest             # stream logs for the latest run (non-interactive)
#   scripts/cli/ci-logs.sh smoke              # stream only the 'smoke' job logs from the latest run
#   scripts/cli/ci-logs.sh job-name <name>    # stream a job by name from the latest run (e.g., build)
#   scripts/cli/ci-logs.sh last-artifacts     # download all artifacts for the latest run into ./ci-artifacts/
#   scripts/cli/ci-logs.sh codex [--job <name>] [--prompt "..."] [--exec 'codex chat --input -']
#   scripts/cli/ci-logs.sh run <run-id>       # stream logs for a specific run id
#   scripts/cli/ci-logs.sh job <job-id>       # stream logs for a specific job id

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT"

have() { command -v "$1" >/dev/null 2>&1; }

if ! have gh; then
  echo "[ci-logs] GitHub CLI not found. Install from https://cli.github.com/ then run 'gh auth login'" >&2
  exit 1
fi

cmd=${1:-latest}
shift || true

case "$cmd" in
  latest)
    echo "[ci-logs] streaming latest run logs (non-interactive)..." >&2
    gh run view --latest --log || { echo "[ci-logs] no runs found" >&2; exit 1; }
    ;;
  last-artifacts)
    outdir="${1:-ci-artifacts}"
    mkdir -p "$outdir"
    echo "[ci-logs] downloading artifacts for latest run into $outdir" >&2
    gh run download --latest --dir "$outdir" || { echo "[ci-logs] failed to download artifacts" >&2; exit 1; }
    ls -la "$outdir" || true
    ;;
  job-name)
    name=${1:-}
    if [[ -z "$name" ]]; then echo "usage: ci-logs.sh job-name <name>" >&2; exit 2; fi
    jobid=$(gh run view --latest --json jobs --jq ".jobs[] | select(.name == \"$name\").databaseId")
    if [[ -z "$jobid" ]]; then echo "[ci-logs] job '$name' not found in latest run" >&2; exit 3; fi
    gh run view --job "$jobid" --log
    ;;
  smoke)
    # convenience alias for the 'smoke' job
    jobid=$(gh run view --latest --json jobs --jq '.jobs[] | select(.name == "smoke").databaseId')
    if [[ -z "$jobid" ]]; then echo "[ci-logs] smoke job not found in latest run" >&2; exit 3; fi
    gh run view --job "$jobid" --log
    ;;
  run)
    runid=${1:-}
    if [[ -z "$runid" ]]; then echo "usage: ci-logs.sh run <run-id>" >&2; exit 2; fi
    gh run view "$runid" --log
    ;;
  job)
    jobid=${1:-}
    if [[ -z "$jobid" ]]; then echo "usage: ci-logs.sh job <job-id>" >&2; exit 2; fi
    gh run view --job "$jobid" --log
    ;;
  codex)
    # proxy to ci-codex.sh to assemble prompt and send to your assistant
    bash "$ROOT/scripts/cli/ci-codex.sh" "$@"
    ;;
  help|-h|--help)
    sed -n '1,40p' "$0" | sed -n '1,20p'
    ;;
  *)
    echo "[ci-logs] unknown command: $cmd" >&2
    exit 2
    ;;
esac
