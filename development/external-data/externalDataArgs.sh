# shellcheck shell=bash
# shellcheck disable=SC2034
# Argument parsing helpers for externalData tools.
# Requires usage() and external_data_die() to be defined by the caller.

external_data_parse_check_args() {
	while [[ $# -gt 0 ]]; do
		case "$1" in
		--ignore)
			[[ $# -ge 2 ]] || external_data_die "--ignore needs a value"
			ignore+=("$2")
			shift 2
			;;
		--strict)
			strict=1
			shift
			;;
		--warn-only)
			warn_only=1
			shift
			;;
		-h | --help)
			usage
			exit 0
			;;
		*) external_data_die "unknown flag: $1" ;;
		esac
	done
}

external_data_parse_sanitize_args() {
	while [[ $# -gt 0 ]]; do
		case "$1" in
		--label)
			[[ $# -ge 2 ]] || external_data_die "--label needs a value"
			label="$2"
			shift 2
			;;
		--encode)
			encode=1
			shift
			;;
		--raw)
			raw=1
			shift
			;;
		--ignore)
			[[ $# -ge 2 ]] || external_data_die "--ignore needs a value"
			ignore+=("$2")
			shift 2
			;;
		--strict)
			strict=1
			shift
			;;
		--warn-only)
			warn_only=1
			shift
			;;
		-h | --help)
			usage
			exit 0
			;;
		*) external_data_die "unknown flag: $1" ;;
		esac
	done
}
