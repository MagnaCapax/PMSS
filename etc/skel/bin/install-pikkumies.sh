#!/usr/bin/env bash
# PMSS: Pikkumies Installer
# Installs or updates Pikkumies, the helper agent (https://github.com/MagnaCapax/mcxPikkumies),
# in your own account: ~/.mcxPikkumies and ~/pikkumies. No root. It changes your shell files only when
# neither ~/bin nor ~/.local/bin is on your PATH (one marked block in ~/.bashrc and ~/.profile).
# Re-run to update; Pikkumies also updates itself daily from your crontab (the line tagged # pikkumies-update).
# This installer first fetches its own newest copy from PMSS and runs that; --no-self-update keeps this copy.
# Remove: rm -rf ~/.mcxPikkumies ~/pikkumies, then delete that crontab line; ~/.virsilipasKey holds your AI key (ADR 0090).
[ -n "${PIKKUMIES_SELF_TMP:-}" ] && [ "$PIKKUMIES_SELF_TMP" = "$0" ] && rm -f "$0"
if [[ " $* " != *" --no-self-update "* && " $* " != *" --skip-update "* ]] && tmp=$(mktemp 2>/dev/null); then
    url="https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/bin/install-pikkumies.sh"
    curl -fsSL "$url" -o "$tmp" 2>/dev/null || wget -q "$url" -O "$tmp" 2>/dev/null || true
    if head -1 "$tmp" | grep -qx '#!/usr/bin/env bash' && sed -n 2p "$tmp" | grep -qx '# PMSS: Pikkumies Installer'; then
        PIKKUMIES_SELF_TMP="$tmp" exec bash "$tmp" --skip-update "$@"
    fi
    rm -f "$tmp"
fi
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
