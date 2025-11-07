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

DEFAULT_PROMPT="PMSS CI Assist — Strict Rails Mode

Goal: Make required CI jobs pass with the smallest coherent change. Read AGENTS.md first; obey Doctrine/Constitution and ADRs.

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
- NEVER CREATE GIT BRANCHES. Commits allowed only when explicitly instructed; default to uncommitted workspace edits.
- ABSOLUTELY NO ZFS — unacceptable for our workload (poor 100% random I/O performance and elevated data-loss risk). Do not propose or introduce it here.
- Keep diffs small, idempotent, backward‑compatible; reuse existing helpers; no aliases; context‑first naming.

Proceed to triage the CI summary, job logs, and artifacts. Propose a minimal patch and verification commands."

job_name=""
custom_prompt=""
exec_cmd=""
include_agents=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --job)
      job_name=${2:-}; shift 2 || true ;;
    --prompt)
      custom_prompt=${2:-}; shift 2 || true ;;
    --exec)
      exec_cmd=${2:-}; shift 2 || true ;;
    --include-agents)
      include_agents=1; shift 1 || true ;;
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

# Wait for run completion (up to 180s) for logs/artifacts to be ready
status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
deadline=$(( $(date +%s) + 180 ))
while [[ "$status" != "completed" && $(date +%s) -lt $deadline ]]; do
  echo "[ci-codex] run status: $status (waiting)" >&2
  sleep 5
  status=$(gh run view "$run_id" --json status --jq .status 2>/dev/null || echo queued)
done

# Download artifacts (best-effort)
echo "[ci-codex] downloading artifacts to $ARTDIR" >&2
gh run download "$run_id" --dir "$ARTDIR" || echo "no valid artifacts found to download" >&2

# Optionally capture a specific job log
# Fetch logs for a requested job, or both 'build' and 'smoke' by default
fetch_job_log() {
  local name="$1" out="$2"
  local id
  id=$(gh run view "$run_id" --json jobs --jq ".jobs[] | select(.name == \"$name\").databaseId") || true
  if [[ -n "$id" ]]; then
    gh run view --job "$id" --log > "$out" || true
  fi
}

if [[ -n "$job_name" ]]; then
  echo "[ci-codex] fetching job logs for '$job_name'" >&2
  fetch_job_log "$job_name" "$JOBLOG"
else
  echo "[ci-codex] fetching job logs for 'build' and 'smoke'" >&2
  fetch_job_log "build" "$OUTDIR/job-build.log"
  fetch_job_log "smoke" "$OUTDIR/job-smoke.log"
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
  if [[ $include_agents -eq 1 && -f "$ROOT/AGENTS.md" ]]; then
    echo "=== AGENTS.md (inline) ==="
    sed -n '1,4000p' "$ROOT/AGENTS.md" || true
    echo
  fi
  echo "----- LOG FOLLOWS:"
  echo
  echo "=== CI Summary ==="
  gh run view "$run_id" || true
  echo
  # Include job logs if present
  for jl in "$OUTDIR"/job-*.log "$JOBLOG"; do
    [[ -s "$jl" ]] || continue
    echo "=== Job Log: $(basename "$jl") ==="
    sed -n '1,4000p' "$jl" || true
    echo
  done
  if compgen -G "$ARTDIR/*" >/dev/null; then
    # Print up to 4000 lines of each file inside the artifact tree
    while IFS= read -r -d '' f; do
      echo "=== Artifact: $(basename "$f") ==="
      sed -n '1,4000p' "$f" || true
      echo
    done < <(find "$ARTDIR" -type f -print0 | sort -z)
  fi
} > "$PROMPT"

echo "[ci-codex] prompt written: $PROMPT" >&2
echo "[ci-codex] workspace: $OUTDIR" >&2

if [[ -n "$exec_cmd" ]]; then
  echo "[ci-codex] piping prompt to: $exec_cmd" >&2
  # shellcheck disable=SC2086
  cat "$PROMPT" | eval $exec_cmd
else
  if command -v codex >/dev/null 2>&1; then
    echo "[ci-codex] piping prompt to: codex (stdin)" >&2
    # Prefer stdin to avoid arg length limits; try simple form first, then known subcommands
    if ! cat "$PROMPT" | codex; then
      echo "[ci-codex] fallback: codex chat --input -" >&2
      if ! cat "$PROMPT" | codex chat --input -; then
        echo "[ci-codex] fallback: codex ask -f -" >&2
        if ! cat "$PROMPT" | codex ask -f -; then
          echo "[ci-codex] all codex invocation methods failed. Try explicitly: --exec 'codex'" >&2
          exit 1
        fi
      fi
    fi
  else
    echo "[ci-codex] Codex CLI not found. To send to your assistant, try:" >&2
    echo "  scripts/cli/ci.sh --exec 'codex'" >&2
    echo "or pipe manually: cat '$PROMPT' | codex" >&2
  fi
fi
