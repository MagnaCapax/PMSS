#!/usr/bin/env bash
set -euo pipefail

scripts/testing/test-php.sh
scripts/testing/test-bash.sh
echo "doctrine lint"
bash scripts/testing/doctrine-lint.sh
if [[ "${PMSS_LINT_CAMEL:-0}" == "1" ]]; then
  echo "camelCase filename lint"
  bash scripts/testing/camelcase-lint.sh
fi
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

echo "OK: all checks"
echo
echo "LOC snapshot"
scripts/testing/loc.sh || true
