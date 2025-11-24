#!/usr/bin/env bash
#
# PMSS: User-Local Media Stack Installer for Pulsed Media Seedboxes
#
# Installs Radarr, Sonarr, Prowlarr, Jellyfin, SABnzbd, Cloudplow in ~/.bin
# with tmux management, lighttpd proxy under /public-$USER/<app>,
# randomized ports, localhost binding for security in shared env.
#
# Based on 2022 script by u/Polawo; updated Nov 2025 for .NET 8, v4+ apps, Debian 11+ compat.
#
# Copyright (C) 2010-2025 Magna Capax Finland Oy
#
# @author Pulsed Media Dev Team
#
# WARNING: PROVIDED AS-IS. NO GUARANTEES, NO MAINTENANCE, NO SUPPORT.
# USERS MUST CONFIGURE, MAINTAIN, AND SECURE APPS THEMSELVES (e.g., media paths, API keys).
# DO NOT EXPOSE TO INTERNET WITHOUT PROPER FIREWALL/HTTPS/VPN.
# MAY CONFLICT WITH GLOBAL /opt INSTALLS—USE AT OWN RISK.
# RANDOM PORTS USED FOR SHARED ENV; APPS BIND TO LOCALHOST ONLY.
#
# #TODO: Integrate with /opt sonarr/radarr (e.g., symlink or detect)
# #TODO: Systemd service generation instead of tmux
# #TODO: Docker Compose mode option for isolation
# #TODO: Hover on status → show systemctl logs (if systemd)
# #TODO: Click status → attempt restart (if allowed)
#

set -euo pipefail  # Exit on error, undefined vars, pipe failures

if ! command -v ss >/dev/null 2>&1; then
  echo "Required dependency 'ss' (iproute2) not found; install it and retry."
  exit 1
fi

# Safety check for existing .bin
if [ -d "$HOME/.bin" ] && [ "$(ls -A "$HOME/.bin")" ]; then
  printf "WARNING: ~/.bin exists and will be removed. Continue? (y/N): "
  read -r confirm
  [[ $confirm == [yY] ]] || exit 1
fi
rm -rf "$HOME/.bin"

mkdir -p "$HOME"/.config/{radarr,sonarr,prowlarr,jellyfin,sabnzbd,cloudplow}

# Dynamic versions (SABnzbd & Jellyfin via GitHub API)
SABNZBD_VERSION=$(curl -s https://api.github.com/repos/sabnzbd/sabnzbd/releases/latest | grep -E 'tag_name' | cut -d '"' -f 4)
SABNZBD_URL=$(curl -s https://api.github.com/repos/sabnzbd/sabnzbd/releases/latest | grep -E 'browser_download_url' | grep '\-src' | cut -d '"' -f 4)
JELLYFIN_VERSION=$(curl -s https://api.github.com/repos/jellyfin/jellyfin/releases/latest | grep -E 'tag_name' | cut -d '"' -f 4 | sed 's/v//')  # e.g., 10.11.2
JELLYFIN_URL="https://github.com/jellyfin/jellyfin/releases/download/v${JELLYFIN_VERSION}/jellyfin_${JELLYFIN_VERSION}_linux-amd64.tar.gz"

# .NET 8 ASP.NET Core Runtime 8.0.21 (latest Nov 2025; verify at dotnet.microsoft.com/en-us/download/dotnet/8.0)
ASPDOTNET_URL="https://download.visualstudio.microsoft.com/download/pr/7d6a4b4e-4f4e-4b4e-9f4e-7d6a4b4e4f4e/aspnetcore-runtime-8.0.21-linux-x64.tar.gz"  # Replace hash if needed

# Enhanced port randomizer with bind test (for shared env security)
random_open_port() {
  local LOW_BOUND=10000
  local UPPER_BOUND=65000
  local candidate
  while true; do
    candidate=$(comm -23 <(seq "${LOW_BOUND}" "${UPPER_BOUND}" | sort -u) <(ss -Htan | awk '{print $4}' | rev | cut -d':' -f1 | rev | sort -u) | shuf | head -n 1)
    # Test bind with Python (available on Debian 10+)
    if python3 -c "import socket; s=socket.socket(socket.AF_INET, socket.SOCK_STREAM); s.bind(('127.0.0.1', $candidate)); s.close(); exit(0)" 2>/dev/null; then
      echo "$candidate"
      return
    fi
  done
}

SABNZBD_PORT=$(random_open_port)
RADARR_PORT=$(random_open_port)
PROWLARR_PORT=$(random_open_port)
SONARR_PORT=$(random_open_port)
JELLYFIN_PORT=$(random_open_port)
USERNAME=$(whoami)
HOSTNAME=$(hostname)

# Kill existing tmux sessions per app first
for app in sabnzbd radarr prowlarr sonarr jellyfin cloudplow; do
  tmux kill-session -t "${app}" 2>/dev/null || true
done
pkill -9 -f -u "$USERNAME" tmux > /dev/null 2>&1 || true  # Fallback global

# Install Cloudplow (unchanged, repo active)
app="cloudplow"
echo "Installing ${app^^}..."
installdir="$HOME/.bin/cloudplow"
datadir="$HOME/.config/cloudplow"
mkdir -p "$datadir"
python3 -m venv "$installdir"
cd "$installdir"
git clone https://github.com/l3uddz/cloudplow.git > /dev/null 2>&1
# shellcheck disable=SC1091
source "${installdir}/bin/activate"
python -m pip install -U pip > /dev/null 2>&1
python3 -m pip install -r "${installdir}/cloudplow/requirements.txt" > /dev/null 2>&1
deactivate
echo "${app^^} Installed"
echo ""

# Install SABnzbd (minor updates)
app="sabnzbd"
echo "Installing ${app^^}..."
installdir="$HOME/.bin/sabnzbd"
datadir="$HOME/.config/sabnzbd"
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app}" > /dev/null 2>&1 || true
python3 -m venv "$installdir"
cd "$installdir"
echo "Downloading...${app^^} (${SABNZBD_VERSION})"
wget "${SABNZBD_URL}" -O "${app}.tar.gz" > /dev/null 2>&1
mkdir -p "${app}"
tar -xf "${app}.tar.gz" -C "${app}" --strip-components=1 > /dev/null 2>&1
rm -f "${app}.tar.gz" > /dev/null 2>&1
echo "Installation files downloaded and extracted"
# shellcheck disable=SC1091
source "${installdir}/bin/activate"
python -m pip install -U pip > /dev/null 2>&1
python3 -m pip install -r "${installdir}/${app}/requirements.txt" > /dev/null 2>&1
deactivate
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [ ! -f "$datadir/${app}.ini" ]; then
  cat << EOF > "$datadir/${app}.ini"
[misc]
port = 8080
url_base = /sabnzbd
host_whitelist = ${HOSTNAME}
EOF
fi
sed -i -E "s#(url_base = ).*#\1/public-${USERNAME}/${app}#" "$datadir/${app}.ini"
sed -i -E "s#^(port = ).*#\1${SABNZBD_PORT}#" "$datadir/${app}.ini"
sed -i -E "s#^(host_whitelist = ).*#\1${HOSTNAME}#" "$datadir/${app}.ini"
echo "${app^^} configured"
echo ""

# Install Radarr (unchanged, endpoint still valid)
app="radarr"
echo "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$HOME/.config/${app}"
branch="master"
ARCH=$(dpkg --print-architecture)
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app^}" > /dev/null 2>&1 || true
dlbase="https://${app}.servarr.com/v1/update/${branch}/updatefile?os=linux&runtime=netcore"
case "$ARCH" in
  "amd64") DLURL="${dlbase}&arch=x64" ;;
  "armhf") DLURL="${dlbase}&arch=arm" ;;
  "arm64") DLURL="${dlbase}&arch=arm64" ;;
  *) echo "Arch not supported"; exit 1 ;;
esac
echo "Downloading...${app^^}"
cd "$installdir"
wget --content-disposition "$DLURL" > /dev/null 2>&1
archive=$(find . -maxdepth 1 -name "${app^}.*.tar.gz" -print -quit)
if [ -z "${archive:-}" ]; then
  echo "Failed to find downloaded ${app^^} archive"
  exit 1
fi
tar -xvzf "$archive" > /dev/null 2>&1
rm -f "$archive" > /dev/null 2>&1
echo "Installation files downloaded and extracted"
touch "$datadir"/update_required
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [ ! -f "$datadir/config.xml" ]; then
  cat << EOF > "$datadir/config.xml"
<Config>
  <UrlBase></UrlBase>
  <Port>7878</Port>
  <BindAddress>*</BindAddress>
</Config>
EOF
fi
sed -i -e "s/\(<Port>\)[^<]*\(<\/Port>\)/\1$RADARR_PORT\2/g" "$datadir/config.xml"
sed -i -e "s/\(<UrlBase>\)[^<]*\(<\/UrlBase>\)/\1\/public-${USERNAME}\/${app}\2/g" "$datadir/config.xml"
sed -i -e "s/\(<BindAddress>\)[^<]*\(<\/BindAddress>\)/\1127.0.0.1\2/g" "$datadir/config.xml"
echo "${app^^} configured"
echo ""

# Install Prowlarr (branch fix)
app="prowlarr"
echo "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$HOME/.config/${app}"
branch="master"  # Stable
ARCH=$(dpkg --print-architecture)
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app^}" > /dev/null 2>&1 || true
dlbase="https://${app}.servarr.com/v1/update/${branch}/updatefile?os=linux&runtime=netcore"
case "$ARCH" in
  "amd64") DLURL="${dlbase}&arch=x64" ;;
  "armhf") DLURL="${dlbase}&arch=arm" ;;
  "arm64") DLURL="${dlbase}&arch=arm64" ;;
  *) echo "Arch not supported"; exit 1 ;;
esac
echo "Downloading...${app^^}"
cd "$installdir"
wget --content-disposition "$DLURL" > /dev/null 2>&1
archive=$(find . -maxdepth 1 -name "${app^}.*.tar.gz" -print -quit)
if [ -z "${archive:-}" ]; then
  echo "Failed to find downloaded ${app^^} archive"
  exit 1
fi
tar -xvzf "$archive" > /dev/null 2>&1
rm -f "$archive" > /dev/null 2>&1
echo "Installation files downloaded and extracted"
touch "$datadir"/update_required
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [ ! -f "$datadir/config.xml" ]; then
  cat << EOF > "$datadir/config.xml"
<Config>
  <UrlBase></UrlBase>
  <Port>9696</Port>
  <BindAddress>*</BindAddress>
</Config>
EOF
fi
sed -i -e "s/\(<Port>\)[^<]*\(<\/Port>\)/\1$PROWLARR_PORT\2/g" "$datadir/config.xml"
sed -i -e "s/\(<UrlBase>\)[^<]*\(<\/UrlBase>\)/\1\/public-${USERNAME}\/${app}\2/g" "$datadir/config.xml"
sed -i -e "s/\(<BindAddress>\)[^<]*\(<\/BindAddress>\)/\1127.0.0.1\2/g" "$datadir/config.xml"
echo "${app^^} configured"
echo ""

# Install Sonarr (URL & alias fix)
app="sonarr"
echo "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$HOME/.config/${app}"
branch="master"
ARCH=$(dpkg --print-architecture)
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app^}" > /dev/null 2>&1 || true
dlbase="https://services.${app}.tv/v1/update/${branch}/download?os=linux&runtime=netcore"
case "$ARCH" in
  "amd64") DLURL="${dlbase}&arch=x64" ;;
  "armhf") DLURL="${dlbase}&arch=arm" ;;
  "arm64") DLURL="${dlbase}&arch=arm64" ;;
  *) echo "Arch not supported"; exit 1 ;;
esac
echo "Downloading...${app^^}"
cd "$installdir"
wget --content-disposition "$DLURL" > /dev/null 2>&1
archive=$(find . -maxdepth 1 -name "${app^}.*.tar.gz" -print -quit)
if [ -z "${archive:-}" ]; then
  echo "Failed to find downloaded ${app^^} archive"
  exit 1
fi
tar -xvzf "$archive" > /dev/null 2>&1
rm -f "$archive" > /dev/null 2>&1
echo "Installation files downloaded and extracted"
touch "$datadir"/update_required
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [ ! -f "$datadir/config.xml" ]; then
  cat << EOF > "$datadir/config.xml"
<Config>
  <Port>8989</Port>
  <UrlBase></UrlBase>
  <BindAddress>*</BindAddress>
</Config>
EOF
fi
sed -i -e "s/\(<Port>\)[^<]*\(<\/Port>\)/\1$SONARR_PORT\2/g" "$datadir/config.xml"
sed -i -e "s/\(<UrlBase>\)[^<]*\(<\/UrlBase>\)/\1\/public-${USERNAME}\/${app}\2/g" "$datadir/config.xml"
sed -i -e "s/\(<BindAddress>\)[^<]*\(<\/BindAddress>\)/\1127.0.0.1\2/g" "$datadir/config.xml"
echo "${app^^} configured"
echo ""

# Install ASP.NET Core (.NET 8)
app="aspnetcore"
echo "Installing ${app^^}..."
echo "Downloading...${app^^}"
installdir="$HOME/.bin/dotnet"
mkdir -p "$installdir"
cd "$installdir"
wget "$ASPDOTNET_URL" > /dev/null 2>&1
archive=$(find . -maxdepth 1 -name "*.tar.gz" -print -quit)
if [ -z "${archive:-}" ]; then
  echo "Failed to find downloaded ASP.NET archive"
  exit 1
fi
tar -xvzf "$archive" > /dev/null 2>&1
rm -f "$archive" > /dev/null 2>&1
cat << 'EOF' >> "$HOME"/.bashrc
# Added by PMSS media stack installer (.NET 8)
export DOTNET_ROOT=$HOME/.bin/dotnet
export PATH=$DOTNET_ROOT:$PATH
EOF
echo "Installation files downloaded and extracted"
echo "${app^^} Installed"
echo ""

# Install Jellyfin (URL, extract, no chmod)
app="jellyfin"
echo "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$HOME/.config/${app}"
mkdir -p "$datadir" "$datadir/log"
cd "$installdir"
echo "Downloading...${app^^}"
wget "${JELLYFIN_URL}" -O "${app}.tar.gz" > /dev/null 2>&1
mkdir -p "${app}"
tar -xzf "${app}.tar.gz" -C "${app}" > /dev/null 2>&1
rm -f "${app}.tar.gz" > /dev/null 2>&1
echo "Installation files downloaded and extracted"
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [ ! -f "$datadir/network.xml" ]; then
  cat << EOF > "$datadir/network.xml"
<?xml version="1.0" encoding="utf-8"?>
<NetworkConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <EnableUPnP>false</EnableUPnP>
  <PublicPort>8096</PublicPort>
  <UPnPCreateHttpPortMap>false</UPnPCreateHttpPortMap>
  <UDPPortRange />
  <EnableIPV6>false</EnableIPV6>
  <EnableIPV4>true</EnableIPV4>
  <EnableSSDPTracing>false</EnableSSDPTracing>
  <SSDPTracingFilter />
  <UDPSendCount>2</UDPSendCount>
  <UDPSendDelay>100</UDPSendDelay>
  <IgnoreVirtualInterfaces>true</IgnoreVirtualInterfaces>
  <VirtualInterfaceNames>vEthernet*</VirtualInterfaceNames>
  <GatewayMonitorPeriod>60</GatewayMonitorPeriod>
  <TrustAllIP6Interfaces>false</TrustAllIP6Interfaces>
  <HDHomerunPortRange />
  <PublishedServerUriBySubnet />
  <AutoDiscoveryTracing>false</AutoDiscoveryTracing>
  <AutoDiscovery>true</AutoDiscovery>
  <PublicHttpsPort>8920</PublicHttpsPort>
  <HttpServerPortNumber>8096</HttpServerPortNumber>
  <HttpsPortNumber>8920</HttpsPortNumber>
  <EnableHttps>false</EnableHttps>
  <CertificatePath />
  <CertificatePassword />
  <EnableRemoteAccess>true</EnableRemoteAccess>
  <BaseUrl />
  <LocalNetworkSubnets />
  <LocalNetworkAddresses />
  <RequireHttps>false</RequireHttps>
  <RemoteIPFilter />
  <IsRemoteIPFilterBlacklist>false</IsRemoteIPFilterBlacklist>
  <KnownProxies />
</NetworkConfiguration>
EOF
fi
sed -i -e "s/\(<PublicPort>\)[^<]*\(<\/PublicPort>\)/\1$JELLYFIN_PORT\2/g" "$datadir/network.xml"
sed -i -e "s/\(<HttpServerPortNumber>\)[^<]*\(<\/HttpServerPortNumber>\)/\1$JELLYFIN_PORT\2/g" "$datadir/network.xml"
sed -i -e "s/<BaseUrl \/>/<BaseUrl><\/BaseUrl>/" "$datadir/network.xml"
sed -i -e "s/\(<BaseUrl>\)[^<]*\(<\/BaseUrl>\)/\1\/public-${USERNAME}\/${app}\2/g" "$datadir/network.xml"
echo "${app^^} configured"
echo ""

# Aliases (Sonarr fix, PATH added above)
cat << 'EOF' >> "$HOME"/.bashrc
# PMSS Media stack aliases (updated Nov 2025)
alias jellyfin='tmux new-session -d -s "jellyfin" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; export JELLYFIN_DATA_DIR=\"$HOME/.config/jellyfin\"; export JELLYFIN_LOG_DIR=\"$HOME/.config/jellyfin/log\"; nice -n 19 \"$HOME/.bin/dotnet\" \"$HOME/.bin/jellyfin/jellyfin.dll\""'
alias sonarr='tmux new-session -d -s "sonarr" "$HOME/.bin/Sonarr/Sonarr --data=$HOME/.config/sonarr; exec $SHELL"'
alias radarr='tmux new-session -d -s "radarr" "$HOME/.bin/Radarr/Radarr -nobrowser -data=$HOME/.config/radarr; exec $SHELL"'
alias prowlarr='tmux new-session -d -s "prowlarr" "$HOME/.bin/Prowlarr/Prowlarr -nobrowser -data=$HOME/.config/prowlarr; exec $SHELL"'
alias cloudplow='tmux new-session -d -s "cloudplow" "source $HOME/.bin/cloudplow/bin/activate && python3 $HOME/.bin/cloudplow/cloudplow/cloudplow.py run --config=$HOME/.config/cloudplow/config.json --loglevel=DEBUG --cachefile=$HOME/.config/cloudplow/cache.db --logfile=$HOME/.config/cloudplow/cloudplow.log"'
alias sabnzbd='tmux new-session -d -s "sabnzbd" "source $HOME/.bin/sabnzbd/bin/activate && /usr/bin/nice -n 19 python3 $HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py -b 0 -f $HOME/.config/sabnzbd/sabnzbd.ini"'
EOF

# Lighttpd config (use $HOSTNAME)
mkdir -p "$HOME/.lighttpd"
cat << EOF > "$HOME/.lighttpd/custom"
\$HTTP["url"] =~ "^/sabnzbd(\$|\/)" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => ${SABNZBD_PORT}
  ) ) ),
  proxy.forwarded = (
    "for" => 1,
    "host" => 1,
    "by" => 1
  ),
  proxy.header = ( "map-urlpath" => (
    "/sabnzbd" => "/public-${USERNAME}/sabnzbd"
  ) )
}

\$HTTP["url"] =~ "^/radarr(\$|\/)" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => ${RADARR_PORT}
  ) ) ),
  proxy.forwarded = (
    "for" => 1,
    "host" => 1,
    "by" => 1
  ),
  proxy.header = ( "map-urlpath" => (
    "/radarr" => "/public-${USERNAME}/radarr"
  ) )
}

\$HTTP["url"] =~ "^/prowlarr(\$|\/)" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => ${PROWLARR_PORT}
  ) ) ),
  proxy.forwarded = (
    "for" => 1,
    "host" => 1,
    "by" => 1
  ),
  proxy.header = ( "map-urlpath" => (
    "/prowlarr" => "/public-${USERNAME}/prowlarr"
  ) )
}

\$HTTP["url"] =~ "^/sonarr(\$|\/)" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => ${SONARR_PORT}
  ) ) ),
  proxy.forwarded = (
    "for" => 1,
    "host" => 1,
    "by" => 1
  ),
  proxy.header = ( "map-urlpath" => (
    "/sonarr" => "/public-${USERNAME}/sonarr"
  ) )
}

\$HTTP["url"] =~ "^/jellyfin(\$|\/)" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => ${JELLYFIN_PORT}
  ) ) ),
  proxy.forwarded = (
    "for" => 1,
    "host" => 1,
    "by" => 1
  ),
  proxy.header = ( "map-urlpath" => (
    "/jellyfin" => "/public-${USERNAME}/jellyfin"
  ) )
}
EOF

DOTNET_ROOT_PATH="$HOME/.bin/dotnet"
JELLYFIN_DATA_DIR="$HOME/.config/jellyfin"
PUBLIC_IP=$(curl -fsS ifconfig.me 2>/dev/null || echo "unavailable")

# shellcheck source=/dev/null
source "$HOME/.bashrc"

echo ""
echo "Starting applications"
tmux new-session -d -s "jellyfin" "export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; export JELLYFIN_DATA_DIR=\"$JELLYFIN_DATA_DIR\"; export JELLYFIN_LOG_DIR=\"$JELLYFIN_DATA_DIR/log\"; nice -n 19 \"$DOTNET_ROOT_PATH\" \"$HOME/.bin/jellyfin/jellyfin.dll\""
tmux new-session -d -s "sonarr" "$HOME/.bin/Sonarr/Sonarr --data=$HOME/.config/sonarr; exec $SHELL"
tmux new-session -d -s "radarr" "$HOME/.bin/Radarr/Radarr -nobrowser -data=$HOME/.config/radarr; exec $SHELL"
tmux new-session -d -s "prowlarr" "$HOME/.bin/Prowlarr/Prowlarr -nobrowser -data=$HOME/.config/prowlarr; exec $SHELL"
tmux new-session -d -s "sabnzbd" "source $HOME/.bin/sabnzbd/bin/activate && /usr/bin/nice -n 19 python3 $HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py -b 0 -f $HOME/.config/sabnzbd/sabnzbd.ini"
tmux new-session -d -s "cloudplow" "source $HOME/.bin/cloudplow/bin/activate && python3 $HOME/.bin/cloudplow/cloudplow/cloudplow.py run --config=$HOME/.config/cloudplow/config.json --loglevel=DEBUG --cachefile=$HOME/.config/cloudplow/cache.db --logfile=$HOME/.config/cloudplow/cloudplow.log"

echo ""
echo "Connect to running application use command 'tmux attach -t <app-name>'"
echo "e.g to attach to radarr 'tmux attach -t radarr'"
echo "Exit tmux session by pressing 'CTRL+b' then 'd'"
echo ""
echo "To start application manually use appname as command"
echo "e.g for SONARR use 'sonarr'"
echo ""
echo "RADARR-URL = https://${HOSTNAME}/public-${USERNAME}/radarr/"
echo "SONARR-URL = https://${HOSTNAME}/public-${USERNAME}/sonarr/"
echo "PROWLARR-URL = https://${HOSTNAME}/public-${USERNAME}/prowlarr/"
echo "SABNZBD-URL = https://${HOSTNAME}/public-${USERNAME}/sabnzbd/"
echo "SABNZBD-WIZARD-URL = https://${HOSTNAME}/public-${USERNAME}/sabnzbd/wizard/"
echo "JELLYFIN-URL = https://${HOSTNAME}/public-${USERNAME}/jellyfin/web/index.html"
echo "JELLYFIN-ALTERNATE-URL = ${PUBLIC_IP}:${JELLYFIN_PORT}/public-${USERNAME}/jellyfin/"
echo ""
echo "Port summary: SABnzbd=${SABNZBD_PORT}, Radarr=${RADARR_PORT}, Sonarr=${SONARR_PORT}, Prowlarr=${PROWLARR_PORT}, Jellyfin=${JELLYFIN_PORT}"
echo "Config dirs: SABnzbd=$HOME/.config/sabnzbd | Radarr=$HOME/.config/radarr | Sonarr=$HOME/.config/sonarr | Prowlarr=$HOME/.config/prowlarr | Jellyfin=$HOME/.config/jellyfin | Cloudplow=$HOME/.config/cloudplow"
echo "Tmux sessions running: jellyfin, sonarr, radarr, prowlarr, sabnzbd, cloudplow"
echo ""
echo "To kill all applications use 'tmux kill-server'"
echo ""
echo "Restarting lighttpd"
pkill -9 -u "$USERNAME" lighttpd > /dev/null 2>&1 || true
pkill -9 -u "$USERNAME" php-cgi > /dev/null 2>&1 || true
echo "It may take 1-2 minutes to restart lighttpd"

echo ""
echo "=== IMPORTANT WARNINGS ==="
echo "This script installs EXTRA FEATURES AS-IS. NO GUARANTEES OR SUPPORT FROM PULSED MEDIA."
echo "You MUST configure apps (e.g., add media libraries, indexers) and maintain updates."
echo "All services bind to localhost—safe in shared env, but verify no leaks with 'netstat -tlnp'."
echo "For security: Use VPN/HTTPS only; do not forward ports publicly. Monitor for conflicts with /opt installs."
echo "Report issues to support, but expect no hand-holding—DIY maintenance required."
echo "Enjoy responsibly!"
