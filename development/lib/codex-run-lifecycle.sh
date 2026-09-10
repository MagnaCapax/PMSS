#!/usr/bin/env bash
# Terminal events cover prompt failures, missing executables, and post-run guard exits.
# SIGKILL cannot run EXIT traps; incomplete shards remain visible for diagnosis.
# The sourcing runner owns lifecycle state shared with these functions.
# shellcheck disable=SC2034,SC2154

codex_runner_finish() {
	local rc=$? level=info
	trap - EXIT
	[[ "$rc" == "0" ]] || level=error
	if ! codex_emit_event_jsonl "$event_log" runner_end "$level" "$run_step" "$run_id" "$rc" \
		"$(($(codex_now_ms) - run_start_ms))" "run completed"; then
		[[ "$rc" != "0" ]] || rc=1
	fi
	exit "$rc"
}

codex_runner_begin() {
	[[ -n "$outdir" ]] || outdir="$(codex_make_temp_workspace pmss-codex-run)"
	run_id="$(date -u +%Y%m%dT%H%M%SZ)-$$-${RANDOM}"
	run_start_ms="$(codex_now_ms)"
	event_log="${event_log:-${PMSS_CODEX_RUN_EVENT_LOG:-$ROOT/log/codex-run/$(date -u +%Y-%m-%d)/$run_id.jsonl}}"
	prompt_out="$outdir/prompt.txt"
	run_step=build_prompt
	trap codex_runner_finish EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	codex_emit_event_jsonl "$event_log" runner_start info "$run_step" "$run_id" "" "" \
		"dry_run=$dry_run autocommit=$autocommit"
	mkdir -p "$outdir"
}

codex_runner_post_checks() {
	# Security: detect modifications to frozen pipeline paths (CRITICAL) — working tree (preserved)
	# and commits made since run start (reported; the agent commits and pushes in-session).
	# This catches sandbox escape via .github/, development/, AGENTS.md, .codex-prompt, .gitignore
	if ! codex_scan_frozen_paths "$ROOT" "$pre_head"; then
		echo "[codex-run] ERROR: frozen path violation detected; files and staging preserved for review" >&2
		codex_emit_event_jsonl "$event_log" "frozen_path_violation" "error" "scan_frozen_paths" "$run_id" "3" "" \
			"frozen path modifications were detected; all work preserved"
		return 3
	fi

	codex_scan_git_diff_for_dangers "$ROOT"
	codex_emit_event_jsonl "$event_log" "danger_scan_complete" "info" "scan_diff" "$run_id" "0" "" \
		"danger scan completed"

	# Security: scan the commit messages made since run start for PII (public repo). The agent
	# pushes in-session, so origin/main already equals HEAD here — the run-start HEAD is the base.
	if ! codex_scan_commit_messages_for_pii "$ROOT" "${pre_head:-origin/main}"; then
		echo "[codex-run] WARNING: commits made since run start contain PII-like data (already pushed under autocommit) — review and rewrite immediately" >&2
		codex_emit_event_jsonl "$event_log" "commit_pii_warning" "warn" "scan_commit_messages" "$run_id" "0" "" \
			"PII-like data detected in commit messages made since run start"
	fi

	# NOTE: The parent-shell "git add -A" commit fallback that used to live here was REMOVED
	# (2026-05-24). It was an agent-introduced band-aid (2026-05-11, 5d00bc3a) for codex being
	# unable to write .git/index.lock under --sandbox workspace-write. It masked that root cause
	# and destroyed mode attribution — every commit became "refactor(compression): codex sandbox
	# commit fallback" regardless of the actual run mode, with no real change description.
	# Root-cause fix: codex now runs --sandbox danger-full-access and commits DIRECTLY with the
	# correct mode prefix + a real description. If codex makes changes but fails to commit, the
	# working tree is left for the runner's start-guard to triage — untracked files are sacred
	# (never auto-committed, never auto-discarded).

}
