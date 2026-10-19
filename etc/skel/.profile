# ============================================================================
#  PULSED MEDIA — MANAGED FILE, DO NOT EDIT
#  This file is owned by root and is REPLACED on every host/service update.
#  Any change here will be silently reverted. Put your customizations in
#  ~/.bashrc.user  (sourced via ~/.bashrc, which this file sources on login).
# ============================================================================

# ~/.profile: executed by the command interpreter for login shells.
# This file is not read by bash(1), if ~/.bash_profile or ~/.bash_login
# exists.
# see /usr/share/doc/bash/examples/startup-files for examples.
# the files are located in the bash-doc package.

# Keep newly-created files private even if parent permissions drift.
umask 077

# Login shells other than bash use the same per-account screen directory.
if [ -z "${BASH_VERSION:-}" ]; then
    _pmss_screen_uid=$(/usr/bin/id -u 2>/dev/null || echo 0)
    if [ "$_pmss_screen_uid" -ge 1000 ] 2>/dev/null && [ -n "${HOME:-}" ]; then
        [ -e "$HOME/.screen" ] || /bin/mkdir -m 0700 -- "$HOME/.screen" 2>/dev/null || true
        if [ -d "$HOME/.screen" ] && [ ! -L "$HOME/.screen" ] && /bin/chmod 0700 -- "$HOME/.screen" 2>/dev/null; then
            export SCREENDIR="$HOME/.screen"
        fi
    fi
    unset _pmss_screen_uid
fi

# if running bash
if [ -n "$BASH_VERSION" ]; then
    # include .bashrc if it exists
    if [ -f "$HOME/.bashrc" ]; then
	. "$HOME/.bashrc"
    fi
fi

# set PATH so it includes user's private bin if it exists
if [ -d "$HOME/bin" ] ; then
    PATH="$PATH:$HOME/bin"
fi
