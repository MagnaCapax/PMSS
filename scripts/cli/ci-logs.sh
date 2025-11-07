#!/usr/bin/env bash
set -euo pipefail

# ci-logs.sh — friendly helper to inspect GitHub Actions runs from your shell
#
# Requires: GitHub CLI (https://cli.github.com/)
#   brew install gh   OR   sudo apt-get install gh
#   gh auth login     (once)
#
# Usage:
#   scripts/cli/ci-logs.sh latest          # stream logs for the latest run on this branch
#   scripts/cli/ci-logs.sh last-artifacts  # download artifacts for the latest run into ./ci-artifacts/
#   scripts/cli/ci-logs.sh run <run-id>    # stream logs for a specific run id
#   scripts/cli/ci-logs.sh job <job-id>    # stream logs for a specific job id

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
    echo "[ci-logs] streaming latest run logs for current branch..." >&2
    gh run view --log || { echo "[ci-logs] no runs found" >&2; exit 1; }
    ;;
  last-artifacts)
    outdir="${1:-ci-artifacts}"
    mkdir -p "$outdir"
    echo "[ci-logs] downloading artifacts for latest run into $outdir" >&2
    gh run download --dir "$outdir" || { echo "[ci-logs] failed to download artifacts" >&2; exit 1; }
    ls -la "$outdir" || true
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
  help|-h|--help)
    sed -n '1,40p' "$0" | sed -n '1,20p'
    ;;
  *)
    echo "[ci-logs] unknown command: $cmd" >&2
    exit 2
    ;;
esac

