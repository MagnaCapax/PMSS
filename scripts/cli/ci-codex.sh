#!/usr/bin/env bash
set -euo pipefail

# ci-codex.sh — Fetch latest CI logs and feed them to a coding assistant (Codex CLI or similar).
#
# Requirements:
#   - GitHub CLI (gh) installed and authenticated: gh auth login
# Optional:
#   - A local assistant CLI that can read prompt from stdin. Provide via --exec
#     e.g. --exec 'codex chat --input -' or --exec 'codex ask -f -'
#
# Usage:
#   scripts/cli/ci-codex.sh                          # assemble prompt + logs into ci-codex/prompt.txt
#   scripts/cli/ci-codex.sh --job smoke               # include only 'smoke' job logs in the prompt
#   scripts/cli/ci-codex.sh --prompt "text..."        # use custom high-level prompt text
#   scripts/cli/ci-codex.sh --exec 'codex chat --input -'   # pipe prompt to Codex CLI directly
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

DEFAULT_PROMPT="Last CI Integration Logs are here. Your objective: make CI (smoke + build) and QC/QA checks pass. If issues or code fails, fix them with the smallest coherent change. First read AGENTS.md, docs, and ADRs to understand the rails; double‑check TODOs and existing tests before any change. Adhere strictly to the Constitution/Doctrine (context‑first naming, PHP 7.3 baseline, hermetic tests, Skel WWW lockdown, no ZFS, idempotence). If multiple tasks arise, do only the first, highest‑value fix, then stop and request a re‑run."

job_name=""
custom_prompt=""
exec_cmd=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --job)
      job_name=${2:-}; shift 2 || true ;;
    --prompt)
      custom_prompt=${2:-}; shift 2 || true ;;
    --exec)
      exec_cmd=${2:-}; shift 2 || true ;;
    -h|--help)
      sed -n '1,60p' "$0"; exit 0 ;;
    *)
      echo "[ci-codex] unknown option: $1" >&2; exit 2 ;;
  esac
done

have() { command -v "$1" >/dev/null 2>&1; }

if ! have gh; then
  echo "[ci-codex] GitHub CLI not found. Install gh and run 'gh auth login'" >&2
  exit 1
fi

mkdir -p "$OUTDIR" "$ARTDIR"

echo "[ci-codex] discovering latest run..." >&2
run_id=$(gh run list --limit 1 --json databaseId --jq '.[0].databaseId')
if [[ -z "$run_id" ]]; then
  echo "[ci-codex] no workflow runs found" >&2
  exit 1
fi

echo "[ci-codex] latest run id: $run_id" >&2

# Download artifacts (smoke logs etc.)
echo "[ci-codex] downloading artifacts to $ARTDIR" >&2
gh run download "$run_id" --dir "$ARTDIR" || true

# Optionally capture a specific job log
if [[ -n "$job_name" ]]; then
  echo "[ci-codex] fetching job logs for '$job_name'" >&2
  job_id=$(gh run view "$run_id" --json jobs --jq ".jobs[] | select(.name == \"$job_name\").databaseId") || true
  if [[ -n "$job_id" ]]; then
    gh run view --job "$job_id" --log > "$JOBLOG" || true
  else
    echo "[ci-codex] job '$job_name' not found in latest run" >&2
  fi
fi

# Build the prompt file
prompt_text=${custom_prompt:-$DEFAULT_PROMPT}
{
  echo "$prompt_text"
  echo
  echo "Context files to read first (repo rails):"
  echo " - AGENTS.md"
  echo " - docs/ (architecture, ADRs, update flow)"
  echo
  echo "=== CI Summary ==="
  gh run view "$run_id" || true
  echo
  if [[ -s "$JOBLOG" ]]; then
    echo "=== Job: $job_name (latest) ==="
    cat "$JOBLOG"
    echo
  fi
  if compgen -G "$ARTDIR/*" >/dev/null; then
    for f in "$ARTDIR"/*; do
      echo "=== Artifact: $(basename "$f") ==="
      sed -n '1,4000p' "$f" || true
      echo
    done
  fi
} > "$PROMPT"

echo "[ci-codex] prompt written: $PROMPT" >&2
echo "[ci-codex] workspace: $OUTDIR" >&2

if [[ -n "$exec_cmd" ]]; then
  echo "[ci-codex] piping prompt to: $exec_cmd" >&2
  # shellcheck disable=SC2086
  cat "$PROMPT" | eval $exec_cmd
else
  echo "[ci-codex] To send to your assistant CLI, try for example:" >&2
  echo "  cat '$PROMPT' | codex chat --input -" >&2
  echo "or specify explicitly: --exec 'codex chat --input -'" >&2
fi
