#!/usr/bin/env bash
set -euo pipefail

bash scripts/testing/check-tools.sh
scripts/testing/test-php.sh
scripts/testing/test-bash.sh
echo "doctrine lint"
bash scripts/testing/doctrine-lint.sh
echo "short open tag lint (advisory)"
bash scripts/testing/short-open-tag-lint.sh || true
echo "open tag presence lint"
bash scripts/testing/open-tag-lint.sh
echo "cgroup template lint"
bash scripts/testing/cgroup-template-lint.sh
if [[ "${PMSS_LINT_CAMEL:-0}" == "1" ]]; then
  echo "camelCase filename lint"
  bash scripts/testing/camelcase-lint.sh
fi
if [[ "${PMSS_LINT_EXEC:-0}" == "1" ]]; then
  echo "exec-bit lint"
  bash scripts/testing/exec-bit-lint.sh
fi
echo "static include check"
scripts/testing/static-include-check.php
if [[ "${PMSS_LINT_DOCBLOCK:-0}" == "1" ]]; then
  echo "docblock lint"
  bash scripts/testing/docblock-lint.sh
fi
if [[ "${PMSS_LINT_PHPSTAN:-0}" == "1" ]]; then
  echo "phpstan analysis"
  PHPSTAN_DISABLE_PARALLEL=1 bash scripts/testing/phpstan.sh
fi
echo "sharp-edges lint (advisory)"
PMSS_LINT_SHARP_STRICT=0 bash scripts/testing/sharp-edges-lint.sh
echo "net-edges lint (advisory)"
PMSS_LINT_NET_STRICT=0 bash scripts/testing/net-edges-lint.sh

echo "OK: all checks"
echo
echo "LOC snapshot"
scripts/testing/loc.sh || true
