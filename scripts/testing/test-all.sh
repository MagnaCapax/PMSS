#!/usr/bin/env bash
set -euo pipefail

bash scripts/testing/check-tools.sh
scripts/testing/test-php.sh
scripts/testing/test-bash.sh

runIfEnabled() {
  local envVar="$1" label="$2"
  shift 2
  if [[ "${!envVar:-0}" == "1" ]]; then
    echo "$label"
    "$@"
  fi
}

echo "doctrine lint"
bash scripts/testing/doctrine-lint.sh
echo "short open tag lint (advisory)"
bash scripts/testing/short-open-tag-lint.sh || true
echo "open tag presence lint"
bash scripts/testing/open-tag-lint.sh
echo "cgroup template lint"
bash scripts/testing/cgroup-template-lint.sh
runIfEnabled PMSS_LINT_CAMEL "camelCase filename lint" bash scripts/testing/camelcase-lint.sh
runIfEnabled PMSS_LINT_EXEC "exec-bit lint" bash scripts/testing/exec-bit-lint.sh
echo "static include check"
scripts/testing/static-include-check.php
runIfEnabled PMSS_LINT_DOCBLOCK "docblock lint" bash scripts/testing/docblock-lint.sh
runIfEnabled PMSS_LINT_PHPSTAN "phpstan analysis" env PHPSTAN_DISABLE_PARALLEL=1 bash scripts/testing/phpstan.sh
runIfEnabled PMSS_LINT_PHPSTAN_UPDATE "phpstan update advisory" bash scripts/testing/phpstan-update-advisory.sh
echo "sharp-edges lint (advisory unless strict mode enabled)"
PMSS_LINT_SHARP_STRICT="${PMSS_LINT_SHARP_STRICT:-0}" bash scripts/testing/sharp-edges-lint.sh
echo "net-edges lint (advisory unless strict mode enabled)"
PMSS_LINT_NET_STRICT="${PMSS_LINT_NET_STRICT:-0}" bash scripts/testing/net-edges-lint.sh

echo "OK: all checks"
echo
echo "LOC snapshot"
development/loc.sh || true
