#!/usr/bin/env bash
#
# PMSS: User-Local Media Stack Installer for Pulsed Media Seedboxes
#
# Installs Radarr, Sonarr, Prowlarr, Jellyfin, SABnzbd, Cloudplow in ~/.bin
# with tmux management, lighttpd proxy under /public-$USER/<app>,
# randomized ports, localhost binding for security in shared env.
#
# Based on 2022 script by u/Polawo; updated Nov 2025 for .NET 8, v4+ apps, Debian 11+ compat.
# Community credit: LET user helped to modernize this version.
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

# Self-update unless explicitly skipped, but only when running interactively (TTY)
if [[ "${1:-}" != "--skip-update" ]] && [[ -t 0 ]]; then
  REMOTE_RAW_URL="https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/install-media-stack.sh"
  tmp=$(mktemp) || true
  if [[ -n "$tmp" ]]; then
    if command -v curl >/dev/null 2>&1; then
      curl -fsSL "$REMOTE_RAW_URL" -o "$tmp" || true
    elif command -v wget >/dev/null 2>&1; then
      wget -q "$REMOTE_RAW_URL" -O "$tmp" || true
    fi
    if [[ -s "$tmp" ]]; then
      chmod +x "$tmp" 2>/dev/null || true
      exec "$tmp" --skip-update "$@"
    fi
    rm -f "$tmp" 2>/dev/null || true
  fi
fi

set -euo pipefail # Exit on error, undefined vars, pipe failures

# ----------------------------------------------------------------------------
# CLI args, logging and helpers
# ----------------------------------------------------------------------------

# Defaults
DRY_RUN=0
VERIFY_ONLY=0

# Overrides (initialized empty)
OVR_SONARR_URL=""; OVR_SONARR_BRANCH=""; OVR_SONARR_VERSION=""
OVR_RADARR_URL=""; OVR_RADARR_BRANCH=""; OVR_RADARR_VERSION=""
OVR_PROWLARR_URL=""; OVR_PROWLARR_BRANCH=""
OVR_SAB_URL=""; OVR_SAB_VERSION=""
OVR_JELLYFIN_URL=""
OVR_JELLYFIN_FFMPEG=""

print_usage() {
  cat <<USAGE
Usage: install-media-stack.sh [--skip-update] [--dry-run] [overrides]

Overrides:
  --sonarr-url=URL            Use exact URL for Sonarr tar.gz
  --sonarr-branch=BRANCH      Override Sonarr branch (default: main)
  --sonarr-version=MAJOR      Override major (default: 4)

  --radarr-url=URL            Use exact URL for Radarr tar.gz
  --radarr-branch=BRANCH      Override Radarr branch (default: master; stable)
  --radarr-version=TAG        Version tag (e.g., v5.10.4.9218) – x64 only
  --radarr-pin=TAG            Alias for --radarr-version

  --prowlarr-url=URL          Use exact URL for Prowlarr tar.gz
  --prowlarr-branch=BRANCH    Override Prowlarr branch (default: master; stable)

  --sab-url=URL               Use exact URL for SABnzbd src archive
  --sab-version=TAG           Override SABnzbd tag (advisory)

  --jellyfin-url=URL          Use exact URL for Jellyfin server tar.gz
  --jellyfin-ffmpeg=PATH      Set Jellyfin FFmpegPath in system.xml to PATH

Modes:
  --dry-run                   Verify endpoints and show actions; do not modify system
  --verify-only               Only verify URLs (alias: implies --dry-run) and exit
USAGE
}

for arg in "$@"; do
  case "$arg" in
    --help|-h) print_usage; exit 0 ;;
    --dry-run) DRY_RUN=1 ;;
    --verify-only) VERIFY_ONLY=1; DRY_RUN=1 ;;
    --sonarr-url=*) OVR_SONARR_URL=${arg#*=} ;;
    --sonarr-branch=*) OVR_SONARR_BRANCH=${arg#*=} ;;
    --sonarr-version=*) OVR_SONARR_VERSION=${arg#*=} ;;
    --radarr-url=*) OVR_RADARR_URL=${arg#*=} ;;
    --radarr-branch=*) OVR_RADARR_BRANCH=${arg#*=} ;;
    --radarr-version=*) OVR_RADARR_VERSION=${arg#*=} ;;
    --radarr-pin=*) OVR_RADARR_VERSION=${arg#*=} ;;
    --prowlarr-url=*) OVR_PROWLARR_URL=${arg#*=} ;;
    --prowlarr-branch=*) OVR_PROWLARR_BRANCH=${arg#*=} ;;
    --sab-url=*) OVR_SAB_URL=${arg#*=} ;;
    --sab-version=*) OVR_SAB_VERSION=${arg#*=} ;;
    --jellyfin-url=*) OVR_JELLYFIN_URL=${arg#*=} ;;
    --jellyfin-ffmpeg=*) OVR_JELLYFIN_FFMPEG=${arg#*=} ;;
    --skip-update) : ;; # handled above
    *) ;;
  esac
done

# Logging
LOG_FILE="$HOME/.install-media-stack.log"
mkdir -p "$(dirname "$LOG_FILE")" >/dev/null 2>&1 || true
touch "$LOG_FILE" 2>/dev/null || true
exec > >(tee -a "$LOG_FILE") 2>&1

# Colors if tty
if [[ -t 1 ]]; then
  C_RESET="\033[0m"; C_INFO="\033[1;34m"; C_OK="\033[1;32m"; C_WARN="\033[1;33m"; C_ERR="\033[1;31m"; C_STEP="\033[1;36m"
else
  C_RESET=""; C_INFO=""; C_OK=""; C_WARN=""; C_ERR=""; C_STEP=""
fi

log_step(){ echo -e "${C_STEP}==> $*${C_RESET}"; }
log_info(){ echo -e "${C_INFO}[INFO]${C_RESET} $*"; }
log_ok(){ echo -e "${C_OK}[ OK ]${C_RESET} $*"; }
log_warn(){ echo -e "${C_WARN}[WARN]${C_RESET} $*"; }
log_err(){ echo -e "${C_ERR}[ERR ]${C_RESET} $*"; }

if [[ $VERIFY_ONLY -eq 1 ]]; then
  log_info "[verify-only] URL verification mode enabled (dry-run)."
fi

check_url(){
  local url="$1"
  if command -v curl >/dev/null 2>&1; then
    curl -fsIL "$url" >/dev/null 2>&1
  elif command -v wget >/dev/null 2>&1; then
    wget -q --spider "$url" >/dev/null 2>&1
  else
    return 1
  fi
}

fetch(){
  # fetch <url> <outfile or ->
  local url="$1"; local out="$2"
  log_info "Fetching: $url"
  if [[ $DRY_RUN -eq 1 ]]; then
    if check_url "$url"; then log_ok "URL reachable (dry-run)"; else log_warn "URL not reachable (dry-run)"; fi
    return 0
  fi
  if command -v wget >/dev/null 2>&1; then
    if [[ "$out" == "-" ]]; then wget -qO - "$url"; else wget -q "$url" -O "$out"; fi
  else
    if [[ "$out" == "-" ]]; then curl -fsSL "$url"; else curl -fsSL "$url" -o "$out"; fi
  fi
}

# Extraction helper
extract_tgz(){
  # extract_tgz <archive> [target_dir] [strip_components]
  local a="$1" t="${2:-.}" s="${3:-}"
  if [[ $DRY_RUN -eq 1 ]]; then
    log_info "[dry-run] would extract $a to $t${s:+ (strip $s)}"; return
  fi
  if [[ -n "$s" ]]; then
    tar -xzf "$a" -C "$t" --strip-components="$s" >/dev/null 2>&1
  else
    tar -xzf "$a" -C "$t" >/dev/null 2>&1
  fi
  rm -f "$a" >/dev/null 2>&1
  echo "Installation files downloaded and extracted"
}

# Central configuration (keep multi-use constants here)
SONARR_BRANCH="main"
RADARR_BRANCH="master"
PROWLARR_BRANCH="master"
SONARR_DL_BASE="https://services.sonarr.tv/v1/download"
SONARR_MAJOR="4"
RADARR_UPDATE_BASE="https://radarr.servarr.com/v1/update"
PROWLARR_UPDATE_BASE="https://prowlarr.servarr.com/v1/update"
BIN_ROOT="$HOME/.bin"
CONFIG_ROOT="$HOME/.config"
DOTNET_ROOT_PATH="$BIN_ROOT/dotnet"
JELLYFIN_CONFIG_DIR="$CONFIG_ROOT/jellyfin"
JELLYFIN_DATA_DIR="$JELLYFIN_CONFIG_DIR"
JELLYFIN_LOG_DIR="$JELLYFIN_CONFIG_DIR/log"

# Apply arg overrides for branch/version
if [[ -n "$OVR_SONARR_BRANCH" ]]; then SONARR_BRANCH="$OVR_SONARR_BRANCH"; fi
if [[ -n "$OVR_RADARR_BRANCH" ]]; then RADARR_BRANCH="$OVR_RADARR_BRANCH"; fi
if [[ -n "$OVR_PROWLARR_BRANCH" ]]; then PROWLARR_BRANCH="$OVR_PROWLARR_BRANCH"; fi
if [[ -n "$OVR_SONARR_VERSION" ]]; then SONARR_MAJOR="$OVR_SONARR_VERSION"; fi

if ! command -v ss >/dev/null 2>&1; then
	log_err "Required dependency 'ss' (iproute2) not found; install it and retry."
	exit 1
fi

# Detect Architecture
ARCH=$(dpkg --print-architecture)
case "$ARCH" in
"amd64")
	JF_ARCH="amd64"
	DOTNET_ARCH="x64"
	SERVARR_ARCH="x64"
	;;
"arm64")
	JF_ARCH="arm64"
	DOTNET_ARCH="arm64"
	SERVARR_ARCH="arm64"
	;;
"armhf")
	JF_ARCH="armhf"
	DOTNET_ARCH="arm"
	SERVARR_ARCH="arm"
	;;
*)
	echo "Architecture '$ARCH' not supported."
	exit 1
	;;
esac

# Safety check for existing .bin
if [ -d "$BIN_ROOT" ] && [ "$(ls -A "$BIN_ROOT")" ]; then
    if [[ $DRY_RUN -eq 1 ]]; then
      log_warn "$BIN_ROOT exists and would be removed (dry-run)."
    else
      printf "WARNING: ~/.bin exists and will be removed. Continue? (y/N): "
      read -r confirm
      [[ $confirm == [yY] ]] || exit 1
      rm -rf "$BIN_ROOT"
    fi
else
  [[ $DRY_RUN -eq 1 ]] || rm -rf "$BIN_ROOT"
fi

if [[ $DRY_RUN -eq 0 ]]; then
  mkdir -p "$CONFIG_ROOT/radarr" "$CONFIG_ROOT/sonarr" "$CONFIG_ROOT/prowlarr" "$CONFIG_ROOT/jellyfin" "$CONFIG_ROOT/sabnzbd" "$CONFIG_ROOT/cloudplow"
  mkdir -p "$BIN_ROOT"
else
  log_info "[dry-run] would create ~/.config/{radarr,sonarr,prowlarr,jellyfin,sabnzbd,cloudplow}"
  log_info "[dry-run] would create ~/.bin"
fi

log_step "Resolving latest versions..."

# SABnzbd (GitHub API)
if [[ -n "$OVR_SAB_URL" ]]; then
  SABNZBD_URL="$OVR_SAB_URL"; SABNZBD_VERSION="override"
else
  SABNZBD_VERSION=$(curl -s https://api.github.com/repos/sabnzbd/sabnzbd/releases/latest | grep -E 'tag_name' | cut -d '"' -f 4 || true)
  SABNZBD_URL=$(curl -s https://api.github.com/repos/sabnzbd/sabnzbd/releases/latest | grep -E 'browser_download_url' | grep '\-src' | cut -d '"' -f 4 || true)
fi
if [[ -n "$OVR_SAB_VERSION" ]]; then SABNZBD_VERSION="$OVR_SAB_VERSION"; fi

# Jellyfin (Repo Scraping)
# Fetches from repo.jellyfin.org structure: files/server/linux/latest-stable/<arch>/
JF_REPO_BASE="https://repo.jellyfin.org/files/server/linux/latest-stable/${JF_ARCH}/"
# Find filename like jellyfin_10.X.Y-amd64.tar.gz
if [[ -n "$OVR_JELLYFIN_URL" ]]; then
  JELLYFIN_URL="$OVR_JELLYFIN_URL"; JF_FILENAME="override"
else
  JF_FILENAME=$(curl -s "$JF_REPO_BASE" | grep -oE "jellyfin_[0-9]+\.[0-9]+\.[0-9]+-${JF_ARCH}\.tar\.gz" | head -n 1)
  if [[ -z "$JF_FILENAME" ]]; then
    log_err "Could not resolve latest Jellyfin tarball from $JF_REPO_BASE"; exit 1
  fi
  JELLYFIN_URL="${JF_REPO_BASE}${JF_FILENAME}"
fi

# ASP.NET Core Runtime (Microsoft aka.ms Links)
# These redirect to the latest patch version of .NET 8 (LTS)
ASPDOTNET_URL="https://aka.ms/dotnet/8.0/aspnetcore-runtime-linux-${DOTNET_ARCH}.tar.gz"

log_info "SABnzbd: ${SABNZBD_VERSION:-unknown}"
log_info "Jellyfin: ${JF_FILENAME}"
log_info "ASP.NET: .NET 8 LTS (${DOTNET_ARCH})"

# If verify-only, we will exit after checking URLs in each block

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

# Guardrail: Check for python3-venv
if ! python3 -m venv --help >/dev/null 2>&1; then
	echo "Error: 'python3-venv' is missing."
	echo "This script requires Python 3 virtual environment support."
	echo "Please ask support to install 'python3-venv' or 'python3-full'."
	exit 1
fi

# Servarr installer helpers to avoid per-app copy/paste
resolve_servarr_url() {
  local app="$1" branch="$2" override_url="$3" override_version="$4" glibc_ver
  if [[ -n "$override_url" ]]; then
    echo "$override_url"
    return 0
  fi
  case "$app" in
    radarr)
      glibc_ver=$(getconf GNU_LIBC_VERSION 2>/dev/null | awk '{print $2}')
      if [[ -n "$override_version" && "$SERVARR_ARCH" == "x64" ]]; then
        echo "https://github.com/Radarr/Radarr/releases/download/${override_version}/Radarr.master.${override_version#v}.linux-core-x64.tar.gz"
        return 0
      fi
      if dpkg --compare-versions "${glibc_ver:-0}" ge 2.33; then
        echo "${RADARR_UPDATE_BASE}/${branch}/updatefile?os=linux&arch=${SERVARR_ARCH}"
        return 0
      fi
      if [[ "$SERVARR_ARCH" == "x64" ]]; then
        log_warn "Detected GLIBC ${glibc_ver:-unknown} < 2.33 → pinning Radarr to v5.10.4.9218 (linux-core-x64)."
        echo "https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz"
        return 0
      fi
      echo "${RADARR_UPDATE_BASE}/${branch}/updatefile?os=linux&arch=${SERVARR_ARCH}"
      ;;
    prowlarr)
      echo "${PROWLARR_UPDATE_BASE}/${branch}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
      ;;
    sonarr)
      echo "${SONARR_DL_BASE}/${branch}/latest?version=${SONARR_MAJOR}&os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
      ;;
    *)
      return 1
      ;;
  esac
}

install_servarr_app() {
  local app="$1" branch="$2" port="$3" datadir="$4" override_url="$5" override_version="$6"
  log_step "Installing ${app^^}..."
  mkdir -p "$datadir"
  pkill -9 -f -u "$USERNAME" "${app^}" >/dev/null 2>&1 || true
  local dlurl
  dlurl=$(resolve_servarr_url "$app" "$branch" "$override_url" "$override_version") || { log_err "Failed to resolve download URL for ${app^^}"; exit 1; }
  log_info "${app^} URL: $dlurl"
  cd "$BIN_ROOT"
  local archive="${app^}.tar.gz"
  if ! fetch "$dlurl" "$archive"; then log_err "Failed to download ${app^}"; exit 1; fi
  extract_tgz "$archive"
  touch "$datadir"/update_required
  echo "${app^^} Installed"
  echo "Configuring ${app^^}"
  if [ ! -f "$datadir/config.xml" ]; then
	cat <<EOF >"$datadir/config.xml"
<Config>
  <UrlBase></UrlBase>
  <Port>${port}</Port>
  <BindAddress>*</BindAddress>
</Config>
EOF
  fi
  sed -i -e "s|<Port>[^<]*</Port>|<Port>${port}</Port>|g" "$datadir/config.xml"
  sed -i -e "s|<UrlBase>[^<]*</UrlBase>|<UrlBase>/public-${USERNAME}/${app}</UrlBase>|g" "$datadir/config.xml"
  sed -i -e "s|<BindAddress>[^<]*</BindAddress>|<BindAddress>127.0.0.1</BindAddress>|g" "$datadir/config.xml"
  echo "${app^^} configured"
  echo ""
}

# Kill existing tmux sessions per app first
log_step "Stopping existing sessions..."
for app in sabnzbd radarr prowlarr sonarr jellyfin cloudplow; do
	tmux kill-session -t "${app}" 2>/dev/null || true
done
pkill -9 -f -u "$USERNAME" tmux >/dev/null 2>&1 || true # Fallback global

# Install Cloudplow (unchanged, repo active)
app="cloudplow"
log_step "Installing ${app^^}..."
installdir="$BIN_ROOT/cloudplow"
datadir="$CONFIG_ROOT/cloudplow"
mkdir -p "$datadir"
[[ $DRY_RUN -eq 1 ]] || python3 -m venv "$installdir"
cd "$installdir"
git clone https://github.com/l3uddz/cloudplow.git >/dev/null 2>&1
# shellcheck disable=SC1091
source "${installdir}/bin/activate"
python -m pip install -U pip >/dev/null 2>&1
python3 -m pip install -r "${installdir}/cloudplow/requirements.txt" >/dev/null 2>&1
deactivate
echo "${app^^} Installed"
echo ""

# Install SABnzbd (minor updates)
app="sabnzbd"
log_step "Installing ${app^^}..."
installdir="$BIN_ROOT/sabnzbd"
datadir="$CONFIG_ROOT/sabnzbd"
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app}" >/dev/null 2>&1 || true
python3 -m venv "$installdir"
cd "$installdir"
echo "Downloading...${app^^} (${SABNZBD_VERSION})"
if [[ -z "${SABNZBD_URL:-}" ]]; then log_err "SABnzbd URL not resolved"; exit 1; fi
if ! fetch "${SABNZBD_URL}" "${app}.tar.gz"; then log_err "Failed to download SABnzbd"; exit 1; fi
mkdir -p "${app}"
extract_tgz "${app}.tar.gz" "${app}" 1
# shellcheck disable=SC1091
if [[ $DRY_RUN -eq 0 ]]; then
  source "${installdir}/bin/activate"
  python -m pip install -U pip >/dev/null 2>&1
  python3 -m pip install -r "${installdir}/${app}/requirements.txt" >/dev/null 2>&1
  deactivate
  echo "${app^^} Installed"
else
  log_info "[dry-run] would create SABnzbd venv and install requirements"
fi
echo "Configuring ${app^^}"
if [ ! -f "$datadir/${app}.ini" ]; then
	cat <<EOF >"$datadir/${app}.ini"
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

# Install Radarr (branch master; Debian 11 fallback)
install_servarr_app "radarr" "$RADARR_BRANCH" "$RADARR_PORT" "$CONFIG_ROOT/radarr" "$OVR_RADARR_URL" "$OVR_RADARR_VERSION"

# Install Prowlarr (branch master)
install_servarr_app "prowlarr" "$PROWLARR_BRANCH" "$PROWLARR_PORT" "$CONFIG_ROOT/prowlarr" "$OVR_PROWLARR_URL" ""

# Install Sonarr (services.sonarr.tv download API; branch main)
install_servarr_app "sonarr" "$SONARR_BRANCH" "$SONARR_PORT" "$CONFIG_ROOT/sonarr" "$OVR_SONARR_URL" ""

# Install ASP.NET Core (.NET 8)
app="aspnetcore"
log_step "Installing ${app^^}..."
echo "Downloading...${app^^}"
installdir="$DOTNET_ROOT_PATH"
mkdir -p "$installdir"
cd "$installdir"
if ! fetch "$ASPDOTNET_URL" "aspnetcore.tar.gz"; then log_err "Failed to download ASP.NET runtime"; exit 1; fi
if [[ $DRY_RUN -eq 0 ]]; then
  if [ ! -f "aspnetcore.tar.gz" ]; then
    echo "Failed to find downloaded ASP.NET archive"; exit 1; fi
  tar -xvzf "aspnetcore.tar.gz" >/dev/null 2>&1
  rm -f "aspnetcore.tar.gz" >/dev/null 2>&1
else
  log_info "[dry-run] would extract aspnetcore.tar.gz"
fi
cat <<EOF >>"$HOME"/.bashrc
# Added by PMSS media stack installer (.NET 8)
export DOTNET_ROOT=${DOTNET_ROOT_PATH}
export PATH=${BIN_ROOT}:\$DOTNET_ROOT:\$PATH
EOF
chmod 0640 "$HOME/.bashrc" 2>/dev/null || true
[[ $DRY_RUN -eq 1 ]] || echo "Installation files downloaded and extracted"
[[ $DRY_RUN -eq 1 ]] || echo "${app^^} Installed"
echo ""

# Install Jellyfin (URL, extract, no chmod)
app="jellyfin"
log_step "Installing ${app^^}..."
installdir="$BIN_ROOT"
datadir="$JELLYFIN_DATA_DIR"
mkdir -p "$datadir" "$JELLYFIN_LOG_DIR"
cd "$installdir"
echo "Downloading...${app^^}"
if ! fetch "${JELLYFIN_URL}" "${app}.tar.gz"; then log_err "Failed to download Jellyfin"; exit 1; fi
mkdir -p "${app}"
extract_tgz "${app}.tar.gz" "${app}" 1
[[ $DRY_RUN -eq 1 ]] || echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [ ! -f "$datadir/network.xml" ]; then
	cat <<EOF >"$datadir/network.xml"
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
sed -i -e "s|<PublicPort>[^<]*</PublicPort>|<PublicPort>${JELLYFIN_PORT}</PublicPort>|g" "$datadir/network.xml"
sed -i -e "s|<HttpServerPortNumber>[^<]*</HttpServerPortNumber>|<HttpServerPortNumber>${JELLYFIN_PORT}</HttpServerPortNumber>|g" "$datadir/network.xml"
sed -i -E "s|<BaseUrl[[:space:]]*/>|<BaseUrl></BaseUrl>|g" "$datadir/network.xml"
sed -i -e "s|<BaseUrl>[^<]*</BaseUrl>|<BaseUrl>/public-${USERNAME}/${app}</BaseUrl>|g" "$datadir/network.xml"
syscfg="$datadir/system.xml"
if [ ! -f "$syscfg" ]; then
  cat > "$syscfg" <<SYSXML
<?xml version="1.0" encoding="utf-8"?>
<ServerConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <BaseUrl>/public-${USERNAME}/${app}</BaseUrl>
  <PublicPort>$JELLYFIN_PORT</PublicPort>
  <HttpServerPortNumber>$JELLYFIN_PORT</HttpServerPortNumber>
</ServerConfiguration>
SYSXML
else
  if grep -q "<BaseUrl>" "$syscfg"; then
    sed -i -e "s|<BaseUrl>[^<]*</BaseUrl>|<BaseUrl>/public-${USERNAME}/${app}</BaseUrl>|g" "$syscfg"
  else
    sed -i -e "s|</ServerConfiguration>|  <BaseUrl>/public-${USERNAME}/${app}</BaseUrl>\n</ServerConfiguration>|" "$syscfg"
  fi
  if grep -q "<PublicPort>" "$syscfg"; then
    sed -i -e "s|<PublicPort>[^<]*</PublicPort>|<PublicPort>${JELLYFIN_PORT}</PublicPort>|g" "$syscfg"
  else
    sed -i -e "s|</ServerConfiguration>|  <PublicPort>${JELLYFIN_PORT}</PublicPort>\n</ServerConfiguration>|" "$syscfg"
  fi
  if grep -q "<HttpServerPortNumber>" "$syscfg"; then
    sed -i -e "s|<HttpServerPortNumber>[^<]*</HttpServerPortNumber>|<HttpServerPortNumber>${JELLYFIN_PORT}</HttpServerPortNumber>|g" "$syscfg"
  else
    sed -i -e "s|</ServerConfiguration>|  <HttpServerPortNumber>${JELLYFIN_PORT}</HttpServerPortNumber>\n</ServerConfiguration>|" "$syscfg"
  fi
fi
if [[ -n "$OVR_JELLYFIN_FFMPEG" ]]; then
  syscfg="$datadir/system.xml"
  if [[ $DRY_RUN -eq 1 ]]; then
    log_info "[dry-run] would set Jellyfin FFmpegPath to '$OVR_JELLYFIN_FFMPEG' in $syscfg"
  else
    if [ ! -f "$syscfg" ]; then
      cat > "$syscfg" <<SYSXML
<?xml version="1.0" encoding="utf-8"?>
<ServerConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <FFmpegPath>$OVR_JELLYFIN_FFMPEG</FFmpegPath>
</ServerConfiguration>
SYSXML
    else
      if grep -q "<FFmpegPath>" "$syscfg"; then
        sed -i -e "s|<FFmpegPath>[^<]*</FFmpegPath>|<FFmpegPath>${OVR_JELLYFIN_FFMPEG}</FFmpegPath>|g" "$syscfg"
      else
        sed -i -e "s|</ServerConfiguration>|  <FFmpegPath>${OVR_JELLYFIN_FFMPEG}</FFmpegPath>\n</ServerConfiguration>|" "$syscfg"
      fi
    fi
  fi
fi
echo "${app^^} configured"
echo ""

SONARR_CMD="export DOTNET_ROOT=\"${DOTNET_ROOT_PATH}\"; \"${DOTNET_ROOT_PATH}/dotnet\" \"${BIN_ROOT}/Sonarr/Sonarr.dll\" --data=\"${CONFIG_ROOT}/sonarr\""
RADARR_CMD="export DOTNET_ROOT=\"${DOTNET_ROOT_PATH}\"; \"${DOTNET_ROOT_PATH}/dotnet\" \"${BIN_ROOT}/Radarr/Radarr.dll\" --nobrowser --data=\"${CONFIG_ROOT}/radarr\""
PROWLARR_CMD="export DOTNET_ROOT=\"${DOTNET_ROOT_PATH}\"; \"${DOTNET_ROOT_PATH}/dotnet\" \"${BIN_ROOT}/Prowlarr/Prowlarr.dll\" --nobrowser --data=\"${CONFIG_ROOT}/prowlarr\""
JELLYFIN_CMD="export DOTNET_ROOT=\"${DOTNET_ROOT_PATH}\"; export JELLYFIN_CONFIG_DIR=\"${JELLYFIN_DATA_DIR}\"; export JELLYFIN_DATA_DIR=\"${JELLYFIN_DATA_DIR}\"; export JELLYFIN_LOG_DIR=\"${JELLYFIN_LOG_DIR}\"; nice -n 19 \"${DOTNET_ROOT_PATH}/dotnet\" \"${BIN_ROOT}/jellyfin/jellyfin.dll\""
SABNZBD_CMD="source ${BIN_ROOT}/sabnzbd/bin/activate && /usr/bin/nice -n 19 python3 ${BIN_ROOT}/sabnzbd/sabnzbd/SABnzbd.py -b 0 -f ${CONFIG_ROOT}/sabnzbd/sabnzbd.ini"
CLOUDPLOW_CMD="source ${BIN_ROOT}/cloudplow/bin/activate && python3 ${BIN_ROOT}/cloudplow/cloudplow/cloudplow.py run --config=${CONFIG_ROOT}/cloudplow/config.json --loglevel=DEBUG --cachefile=${CONFIG_ROOT}/cloudplow/cache.db --logfile=${CONFIG_ROOT}/cloudplow/cloudplow.log"

# Aliases (Sonarr fix, PATH added above)
cat <<EOF >>"$HOME"/.bashrc
# PMSS Media stack aliases (updated Nov 2025)
alias jellyfin='tmux new-session -d -s "jellyfin" "'"$JELLYFIN_CMD"'"'
alias sonarr='tmux new-session -d -s "sonarr" "'"$SONARR_CMD"'"'
alias radarr='tmux new-session -d -s "radarr" "'"$RADARR_CMD"'"'
alias prowlarr='tmux new-session -d -s "prowlarr" "'"$PROWLARR_CMD"'"'

alias cloudplow='tmux new-session -d -s "cloudplow" "'"$CLOUDPLOW_CMD"'"'
alias sabnzbd='tmux new-session -d -s "sabnzbd" "'"$SABNZBD_CMD"'"'
EOF

# Lighttpd config (use $HOSTNAME)
mkdir -p "$HOME/.lighttpd"
cat <<EOF >"$HOME/.lighttpd/custom"
\$HTTP["url"] =~ "^/sabnzbd(\$|/)" {
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

\$HTTP["url"] =~ "^/radarr(\$|/)" {
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

\$HTTP["url"] =~ "^/prowlarr(\$|/)" {
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

\$HTTP["url"] =~ "^/sonarr(\$|/)" {
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

\$HTTP["url"] =~ "^/jellyfin(\$|/)" {
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

PUBLIC_IP=$(curl -fsS ifconfig.me 2>/dev/null || echo "unavailable")

set +u
# shellcheck source=/dev/null
source "$HOME/.bashrc" || true
set -u

echo ""
log_step "Starting applications"
# Corrected: Point to dotnet binary, not the directory
if [[ $DRY_RUN -eq 0 ]]; then
  tmux new-session -d -s "jellyfin" "$JELLYFIN_CMD"
  tmux new-session -d -s "sonarr" "$SONARR_CMD"
  tmux new-session -d -s "radarr" "$RADARR_CMD"
  tmux new-session -d -s "prowlarr" "$PROWLARR_CMD"
  tmux new-session -d -s "sabnzbd" "$SABNZBD_CMD"
  tmux new-session -d -s "cloudplow" "$CLOUDPLOW_CMD"
else
  log_info "[dry-run] would start tmux sessions: jellyfin, sonarr, radarr, prowlarr, sabnzbd, cloudplow"
fi

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
log_step "Restarting lighttpd"
if [[ $DRY_RUN -eq 0 ]]; then
  pkill -9 -u "$USERNAME" lighttpd >/dev/null 2>&1 || true
  pkill -9 -u "$USERNAME" php-cgi >/dev/null 2>&1 || true
  echo "It may take 1-2 minutes to restart lighttpd"
else
  log_info "[dry-run] would restart lighttpd/php-cgi"
fi

echo ""
echo "================== SECURITY WARNING =================="
echo "Jellyfin first-run requires creating an admin account."
echo "Set a STRONG admin password immediately after opening:"
echo "  https://${HOSTNAME}/public-${USERNAME}/jellyfin/web/index.html"
echo "Services are bound to 127.0.0.1 via per-user lighttpd,"
echo "but do NOT expose them publicly without authentication."
echo "======================================================="
echo ""
echo "=== IMPORTANT WARNINGS ==="
echo "This script installs EXTRA FEATURES AS-IS. NO GUARANTEES OR SUPPORT FROM PULSED MEDIA."
echo "You MUST configure apps (e.g., add media libraries, indexers) and maintain updates."
echo "All services bind to localhost—safe in shared env, but verify no leaks with 'netstat -tlnp'."
echo "For security: Use VPN/HTTPS only; do not forward ports publicly. Monitor for conflicts with /opt installs."
echo "Report issues to support, but expect no hand-holding—DIY maintenance required."
echo "Enjoy responsibly!"
