#!/usr/bin/env bash
# shellcheck disable=SC2154
set -euo pipefail
set -o errtrace

# shellcheck disable=SC1091
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/codex-common.sh"
codex_agentic_bootstrap_self "PMSS_REFACTOR_CODEX_DEBUG" "agentic-refactor"

echo "[agentic-refactor] start: assembling refactor context and invoking assistant" >&1

# shellcheck source=development/lib/refactor-options.sh
source "$HERE/lib/refactor-options.sh"

# In dry-run mode, avoid running git/phploc/gh/etc. Only show what would run and
# let codex-run.sh print the final assistant invocation.
if [[ "$dry_run" == "1" ]]; then
	echo "[agentic-refactor] dry-run: skipping git/loc/phploc collection" >&1
	echo "[agentic-refactor] dry-run: would run (best-effort):" >&1
	echo "  git -C '$ROOT' log -n '$commits' --pretty=format:'%h %s'" >&1
	echo "  git -C '$ROOT' log -n '$commits' --name-only --pretty=format:'--- %H' | awk ... | sort -u" >&1
	echo "  '$ROOT/development/loc.sh' > '$LOC_LOG'" >&1
	echo "  bash '$ROOT/scripts/testing/phploc.sh' > '$PHPLC_LOG'" >&1
fi

echo "[agentic-refactor] output directory: $OUTDIR" >&1

# shellcheck source=development/lib/refactor-context.sh
source "$HERE/lib/refactor-context.sh"

codex_context_args=()
[[ -s "$COMMITS_SUMMARY" ]] && codex_context_args+=(--context "$COMMITS_SUMMARY")
[[ -s "$COMMITS_FILES" ]] && codex_context_args+=(--context "$COMMITS_FILES")
[[ -s "$CANDIDATES" ]] && codex_context_args+=(--context "$CANDIDATES")
[[ -s "$LOC_LOG" ]] && codex_context_args+=(--context "$LOC_LOG")
[[ -s "$PHPLC_LOG" ]] && codex_context_args+=(--context "$PHPLC_LOG")
[[ -s "$COOLING_CTX" ]] && codex_context_args+=(--context "$COOLING_CTX")
codex_run_prompt "$HERE" "$HERE/prompts/refactor.txt" "$OUTDIR" "$ROOT" "$agent" "$exec_cmd" "$dry_run" "$autocommit" "$custom_prompt" 1 "${codex_context_args[@]}"

echo "[agentic-refactor] done" >&1
