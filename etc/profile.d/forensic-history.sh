# shellcheck shell=sh
# PMSS login-shell history baseline; this file is sourced by /etc/profile.
export HISTTIMEFORMAT="${HISTTIMEFORMAT:-%F %T  }"
export HISTSIZE="${HISTSIZE:-10000}"
export HISTFILESIZE="${HISTFILESIZE:-20000}"

if [ -n "${BASH_VERSION:-}" ]; then
	# shellcheck disable=SC3044 # guarded by BASH_VERSION above.
	shopt -s histappend
fi
