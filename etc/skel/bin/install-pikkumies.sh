#!/usr/bin/env bash
# PMSS: Pikkumies Installer
# Installs or updates Pikkumies, the helper agent (https://github.com/MagnaCapax/mcxPikkumies),
# in your own account: ~/.mcxPikkumies and ~/pikkumies. No root, no changes to your shell files.
# Re-run to update; Pikkumies also updates itself daily from your crontab (the line tagged # pikkumies-update).
# Remove: rm -rf ~/.mcxPikkumies ~/pikkumies, then delete that crontab line; ~/.virsilipasKey holds your AI key (ADR 0090).
set -euo pipefail
dir="$HOME/.mcxPikkumies"
if [ -d "$dir/.git" ]; then
    "$dir/pikkumies/bin/pikkumies" update
else
    GIT_TERMINAL_PROMPT=0 git clone -q --depth 1 https://github.com/MagnaCapax/mcxPikkumies "$dir" \
        || { echo "Could not download Pikkumies from GitHub; it may not be released yet." >&2; exit 1; }
fi
# Your Pulsed Media AI key (client area: AI credits, Virsilipas AI key) goes on the key= line of ~/.virsilipasKey.
# Created only when absent; the platform fills the same file when it delivers keys.
if [ ! -e "$HOME/.virsilipasKey" ] && [ ! -L "$HOME/.virsilipasKey" ]; then
    (umask 077; printf 'endpoint=https://virsilipas.mcx.fi/v1\nkey=\n' > "$HOME/.virsilipasKey")
fi
exec "$dir/pikkumies/bin/pikkumies" install
