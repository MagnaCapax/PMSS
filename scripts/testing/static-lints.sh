#!/usr/bin/env bash
# The blocking static checks of CI's build job, without the dev test suite: a quick check before a change is pushed.
# Every check runs so one report lists all failures. StaticLintsTest keeps this list in step with ci.yml.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 2
f=0
for check in php73-compat-scan.sh test-php.sh test-bash.sh doctrine-lint.sh docblock-lint.sh open-tag-lint.sh cgroup-template-lint.sh; do
	echo "== static-lints: $check" >&2
	PMSS_TESTING_SKIP_DEV_TESTS=1 bash "scripts/testing/$check" || {
		echo "static-lints: FAILED $check" >&2
		f=1
	}
done
[[ $f -eq 0 ]] && echo "static-lints: OK" >&2
exit $f
