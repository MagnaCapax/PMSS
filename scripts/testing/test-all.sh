#!/usr/bin/env bash
set -euo pipefail

scripts/testing/test-php.sh
scripts/testing/test-bash.sh

echo "OK: all checks"

