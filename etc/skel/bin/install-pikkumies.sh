#!/usr/bin/env bash
# PMSS: Pikkumies Installer
# Installs or updates Pikkumies, the helper agent (https://github.com/MagnaCapax/mcxPikkumies),
# in your own account: ~/.mcxPikkumies and ~/pikkumies. No root, no changes to your shell files.
# Re-run to update; Pikkumies also updates itself daily from your crontab (the line tagged # pikkumies-update).
# Remove: rm -rf ~/.mcxPikkumies ~/pikkumies ~/.pikkumiesKey, then delete that crontab line (ADR 0090).
set -euo pipefail
dir="$HOME/.mcxPikkumies"
if [ -d "$dir/.git" ]; then
    "$dir/pikkumies/bin/pikkumies" update
else
    GIT_TERMINAL_PROMPT=0 git clone -q --depth 1 https://github.com/MagnaCapax/mcxPikkumies "$dir" \
        || { echo "Could not download Pikkumies from GitHub; it may not be released yet." >&2; exit 1; }
fi
# Pulsed Media's model endpoint. Put your key on the key= line; a key file the platform provides replaces this one.
if [ ! -e "$HOME/.pikkumiesKey" ]; then
    (umask 077; printf 'endpoint=https://virsilipas.mcx.fi/v1\nkey=\n' > "$HOME/.pikkumiesKey")
fi
exec "$dir/pikkumies/bin/pikkumies" install
