#!/usr/bin/env bash
# Thin wrapper for performance baseline collection helpers.

set -euo pipefail

# shellcheck source=/dev/null
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/performance/baselineCollect.sh"
pmssPerformanceBaselineCollectMain "$@"
