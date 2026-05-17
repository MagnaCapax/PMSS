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
				if (line ~ /(^|[^[:alnum:]_\/.-])timeout([[:space:]]+--[A-Za-z0-9_-]+(=[^[:space:]]+)?)*[[:space:]]+[0-9]+[smhd]?([[:space:]]|$)/ &&
					line !~ /--kill-after/) {
					printf "%s:%d: timeout invocation lacks --kill-after: %s", file, NR, line
				}
			}
		' "$file"
	)
done < <(git ls-files scripts tools docs install.sh 2>/dev/null)

if [[ "$violations" -gt 0 ]]; then
	printf 'timeout audit: %d offender(s)\n' "$violations" >&2
	exit 1
fi

echo "timeout audit: OK"
