#!/usr/bin/env bash
set -euo pipefail
# phpstan runner for PMSS. Honors:
# - PMSS_PHPSTAN_BIN: override path to phpstan (default: vendor/bin/phpstan or phpstan in PATH)
# - PHPSTAN_DISABLE_PARALLEL=1 to disable parallelization in constrained envs
# - ALLOW_TOOL_SKIP=1 to skip when phpstan is unavailable

# shellcheck source=scripts/testing/testingPaths.sh
source "$(cd "$(dirname "$0")" && pwd)/testingPaths.sh"

PHAR_BIN="${PMSS_PHPSTAN_BIN:-}"
if [[ -z "$PHAR_BIN" ]]; then
	if [[ -x "$ROOT_DIR/vendor/bin/phpstan" ]]; then
		PHAR_BIN="$ROOT_DIR/vendor/bin/phpstan"
	elif command -v phpstan >/dev/null 2>&1; then
		PHAR_BIN="phpstan"
	else
		if [[ "${ALLOW_TOOL_SKIP:-0}" == "1" ]]; then
			echo "phpstan not found; skipping (ALLOW_TOOL_SKIP=1)" >&2
			exit 0
		fi
		echo "phpstan not found; install it or set ALLOW_TOOL_SKIP=1" >&2
		exit 127
	fi
fi

ARGS=("analyse" "--no-progress")
[[ "${PHPSTAN_DISABLE_PARALLEL:-0}" == "1" ]] && ARGS+=("--no-parallel")

if [[ -f "$ROOT_DIR/phpstan.neon.dist" ]]; then
	ARGS+=("-c" "$ROOT_DIR/phpstan.neon.dist")
fi

pmss_testing_cd_root_dir "$ROOT_DIR"

has_target=0
after_double_dash=0
skip_next=0
for ((i = 1; i <= $#; i++)); do
	arg="${!i}"
	if ((skip_next)); then
		skip_next=0
		continue
	fi
	if [[ "$arg" == "--" ]]; then
		after_double_dash=1
		continue
	fi
	if ((after_double_dash)); then
		has_target=1
		break
	fi

	case "$arg" in
	-c | --configuration | --autoload-file | --memory-limit | --error-format | --level)
		skip_next=1
		continue
		;;
	-*)
		continue
		;;
	esac

	if [[ -d "$arg" || "$arg" == *.php ]]; then
		has_target=1
		break
	fi
done

if ((has_target == 0)); then
	set -- "$@" scripts
fi

# Forward any extra arguments to allow CI/local overrides (e.g., --level=3)
exec "$PHAR_BIN" "${ARGS[@]}" "$@"
