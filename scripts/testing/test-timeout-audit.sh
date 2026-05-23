#!/usr/bin/env bash
set -euo pipefail

# Fail on GNU timeout calls that omit a SIGKILL backstop.

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

pmss_testing_cd_root_dir "$ROOT_DIR"

violations=0
while IFS= read -r file; do
	[[ -f "$file" ]] || continue
	while IFS= read -r hit; do
		[[ -n "$hit" ]] || continue
		printf '%s\n' "$hit" >&2
		violations=$((violations + 1))
	done < <(
		awk -v file="$file" '
			/^[[:space:]]*(#|\/\/|\*)/ { next }
			{
				line = $0
				command = "(^|[^[:alnum:]_.-])((/[[:alnum:]_.-]+)+/)?timeout"
				invocation = command "[[:space:]]+([0-9]|-[A-Za-z-])"
				duration = "[[:space:]]+[0-9]+[smhd]?([[:space:]]|$)"
				if (line ~ invocation && line ~ command "[^;&|#]*" duration &&
					line !~ /--kill-after/) {
					printf "%s:%d: timeout invocation lacks --kill-after: %s\n", file, NR, line
				}
			}
		' "$file"
	)
done < <(git ls-files scripts tools docs install.sh 2>/dev/null)

if [[ "$violations" -gt 0 ]]; then
	printf 'timeout audit: %d offender(s)\n' "$violations" >&2
	exit 1
fi

start_seconds=$SECONDS
set +e
sh -c 'timeout --kill-after=1s 1s bash -c '\''trap "" TERM; sleep 10'\'' >/dev/null 2>&1'
timeout_rc=$?
set -e
elapsed_seconds=$((SECONDS - start_seconds))

if [[ "$timeout_rc" -ne 137 ]]; then
	printf 'timeout audit: SIGTERM-ignoring probe exited rc=%d, expected 137\n' "$timeout_rc" >&2
	exit 1
fi

if [[ "$elapsed_seconds" -gt 5 ]]; then
	printf 'timeout audit: SIGTERM-ignoring probe took %ds, expected bounded SIGKILL\n' "$elapsed_seconds" >&2
	exit 1
fi

echo "timeout audit: OK"
