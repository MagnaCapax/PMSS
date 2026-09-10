#!/usr/bin/env bash
set -euo pipefail
set -o errtrace

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
# shellcheck source=development/lib/codex-common.sh
source "$HERE/lib/codex-common.sh"
codex_enable_debug PMSS_CODEX_RUN_DEBUG "codex-run"
codex_set_error_trap "codex-run"

# CLI parsing, prompt composition, and lifecycle checks each own their contract.
# shellcheck source=development/lib/codex-run-options.sh
source "$HERE/lib/codex-run-options.sh"
# shellcheck source=development/lib/codex-run-lifecycle.sh
source "$HERE/lib/codex-run-lifecycle.sh"
codex_runner_begin
codex_prepare_invocation
# shellcheck source=development/lib/codex-run-prompt.sh
source "$HERE/lib/codex-run-prompt.sh"

prompt_bytes=$(wc -c <"$prompt_out" | tr -d ' ')
prompt_lines=$(wc -l <"$prompt_out" | tr -d ' ')
echo "[codex-run] prompt written: $prompt_out (${prompt_bytes} bytes, ${prompt_lines} lines)"
echo "[codex-run] run id: $run_id"
echo "[codex-run] event log: $event_log"

if [[ "$dry_run" == "1" ]]; then
	run_step=dry_run
	codex_color_line 33 "[codex-run] would invoke: $(codex_exec_preview "$exec_cmd" "$prompt_out")"
	echo "[codex-run] dry-run: not invoking assistant (--dry-run)"
	exit 0
fi

# Launch in the repository discovered from this script, independent of caller cwd.
cd "$ROOT"
run_step=invoke
invoke_start_ms="$(codex_now_ms)"
codex_emit_event_jsonl "$event_log" assistant_invoke_start info "$run_step" "$run_id" "" "" \
	"exec=${exec_cmd%% *}"
# HEAD at run start: post-run guards inspect commits made by this invocation.
pre_head="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || true)"
set +e
codex_invoke "$exec_cmd" "$prompt_out"
invoke_rc=$?
set -e
invoke_level=info
[[ "$invoke_rc" == "0" ]] || invoke_level=error
codex_emit_event_jsonl "$event_log" assistant_invoke_end "$invoke_level" "$run_step" "$run_id" "$invoke_rc" \
	"$(($(codex_now_ms) - invoke_start_ms))" "assistant invocation completed"
[[ "$invoke_rc" == "0" ]] || exit "$invoke_rc"

run_step=post_checks
codex_runner_post_checks
