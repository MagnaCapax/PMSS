#!/usr/bin/env bash
# Refactor evidence collection and candidate claims; no assistant invocation.
# Sourced after CLI validation with ROOT, OUTDIR, and candidate paths established.
# shellcheck disable=SC2154

# Gather recent commits and touched files (best-effort).
if [[ "$dry_run" != "1" ]] && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "[agentic-refactor] collecting last $commits commits…" >&1
	git -C "$ROOT" log -n "$commits" --pretty=format:'%h %s' >"$COMMITS_SUMMARY" || true
	git -C "$ROOT" log -n "$commits" --name-only --pretty=format:'--- %H' |
		awk '/^--- / { next } NF { print }' |
		sort -u >"$COMMITS_FILES" || true
else
	if [[ "$dry_run" == "1" ]]; then
		echo "[agentic-refactor] dry-run: skipping commit context collection" >&1
	else
		echo "[agentic-refactor] not inside a git repository; skipping commit context" >&1
	fi
fi

# Build a candidate file list from recent commits, optionally narrowed by target.
: >"$CANDIDATES"
if [[ "$dry_run" != "1" && -s "$COMMITS_FILES" ]]; then
	if [[ -n "$target" ]]; then
		awk -v t="$target" 'index($0, t) > 0' "$COMMITS_FILES" >"$CANDIDATES" || true
		if [[ ! -s "$CANDIDATES" ]]; then
			cp "$COMMITS_FILES" "$CANDIDATES"
		fi
	else
		cp "$COMMITS_FILES" "$CANDIDATES"
	fi
fi

# Architectural modes need whole-codebase candidates, not only recent churn.
# Feed the largest maintained runtime PHP files into the candidate set.
if [[ "$dry_run" != "1" && "$custom_prompt" =~ refactor\((decompose|dry)\): ]]; then
	WHOLE_N="${PMSS_REFACTOR_WHOLE_N:-30}"
	[[ "$WHOLE_N" =~ ^[1-9][0-9]*$ ]] || codex_cli_error_exit agentic-refactor 'PMSS_REFACTOR_WHOLE_N must be positive'
	{ git -C "$ROOT" ls-files '*.php' 2>/dev/null |
		grep -E '^(scripts|etc/skel/www)/' |
		grep -vE '/(tests|testing|rutorrent|devristo)/' |
		grep -vE '^etc/skel/www/filemanager\.php$' |
		while IFS= read -r f; do
			[[ -f "$ROOT/$f" ]] && printf '%s %s\n' "$(wc -l <"$ROOT/$f" 2>/dev/null || echo 0)" "$f"
		done |
		# Consume every sorted row to avoid SIGPIPE diagnostics from head closing early.
		sort -rn | awk -v count="$WHOLE_N" 'NR <= count {print $2}'; } >>"$CANDIDATES" || true
	sort -u "$CANDIDATES" -o "$CANDIDATES" 2>/dev/null || true
	echo "[agentic-refactor] whole-codebase mode: added up to $WHOLE_N largest runtime PHP files to candidates" >&1
fi

if [[ "$dry_run" != "1" && -s "$CANDIDATES" ]]; then
	PMSS_REFACTOR_ORIG_COUNT=0
	PMSS_REFACTOR_CLAIMED_COUNT=0
	codex_scope_claim_filter_candidates "$CLAIMS_DIR" "$CANDIDATES" PMSS_REFACTOR_CLAIMED PMSS_REFACTOR_CLAIMED_COUNT PMSS_REFACTOR_ORIG_COUNT
	echo "[agentic-refactor] scope-claim: claimed $PMSS_REFACTOR_CLAIMED_COUNT of $PMSS_REFACTOR_ORIG_COUNT candidates (others held by parallel instances)" >&1
fi

# Ensure advisory complexity snapshots exist (best-effort).
if [[ "$dry_run" != "1" && -x "$ROOT/development/loc.sh" ]]; then
	echo "[agentic-refactor] generating LOC snapshot via development/loc.sh" >&1
	"$ROOT/development/loc.sh" >"$LOC_LOG" 2>&1 || true
fi
if [[ "$dry_run" != "1" && -x "$ROOT/scripts/testing/phploc.sh" ]]; then
	echo "[agentic-refactor] generating phploc snapshot via scripts/testing/phploc.sh" >&1
	bash "$ROOT/scripts/testing/phploc.sh" >"$PHPLC_LOG" 2>&1 || true
fi

# Build cooling period context if files were specified (Joukahainen Round 8 defense)
COOLING_CTX="$OUTDIR/cooling-period.txt"
if [[ -n "$cooling_files" && -s "$cooling_files" ]]; then
	{
		echo "REFACTOR COOLING PERIOD (BINDING)"
		echo "The following files were committed by the issues pass in this cycle."
		echo "DO NOT refactor these files. They need stability before refactoring."
		echo "This prevents a two-stage attack where issues pass introduces code and"
		echo "refactor pass accidentally removes security checks embedded in complexity."
		echo ""
		cat "$cooling_files"
	} >"$COOLING_CTX"
	echo "[agentic-refactor] cooling period: excluding $(wc -l <"$cooling_files" | tr -d ' ') file(s) from refactoring" >&1
fi
