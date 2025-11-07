#!/bin/bash
# Set rTorrent upload rate for a given user by sending commands to the existing screen session.
#
# Usage: setRtorrentUploadRate.sh <username> <rate_kib>
# - username: system user running rTorrent
# - rate_kib: integer KiB/s, e.g., 1024

# Return success if the argument is a non-negative integer.
is_int() { [[ "$1" =~ ^[0-9]+$ ]]; }

# Validate args early; fail-soft by exiting without error when inputs are missing.
if [ -z "${1:-}" ]; then
    exit 0
fi
if [ -z "${2:-}" ]; then
    exit 0
fi

user="$1"
rate="$2"

if [ -f "/home/$user/.rtorrent.rc" ]; then
    if is_int "$rate"; then
        # Send Ctrl-X to enter command mode, set rate, then send Enter.
        su "$user" -c "screen -S rtorrent -X stuff $(printf '\\030')"
        su "$user" -c "screen -S rtorrent -X stuff 'upload_rate = $rate'"
        su "$user" -c "screen -S rtorrent -X stuff $(printf '\\r')"
        echo "Upload rate set!"
    fi
else
    echo "user not found"
fi
