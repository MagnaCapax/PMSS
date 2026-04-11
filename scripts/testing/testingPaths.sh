#!/usr/bin/env bash
# Shared repository path and file discovery helpers for testing scripts.
pmss_testing_root_dir() { cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd; }
pmss_testing_cd_root_dir() { cd "${1:-$(pmss_testing_root_dir)}"; }
pmss_testing_find_php_files() { find "$1" -type f -name "*.php" -not -path "*/vendor/*" -print0; }
pmss_testing_find_runtime_php_files() { find "$1" -type d \( -path "$1/vendor" -o -path "$1/scripts/lib/tests" \) -prune -o -type f -name "*.php" -print0; }
pmss_testing_find_first_party_php_files() { find "$1" -type f -name "*.php" -not -path "*/.git/*" -not -path "*/vendor/*" -not -path "*/etc/skel/*" -not -path "*/scripts/lib/devristo/*" -print0; }
pmss_testing_find_bash_files() { find "$1" -type f -name "*.sh" -not -path "*/vendor/*" -not -path "*/etc/skel/www/*" -print0; }
pmss_testing_list_tracked_php_files() { git -C "$1" ls-files -z '*.php'; }
[[ -n "${ROOT_DIR:-}" ]] || ROOT_DIR="$(pmss_testing_root_dir)"
