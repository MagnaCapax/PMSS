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
  --sonarr-branch=BRANCH      Override branch (default: main)
  --sonarr-version=MAJOR      Override major (default: 4)

  --radarr-url=URL            Use exact URL for Radarr tar.gz
  --radarr-branch=BRANCH      Override branch (default: main)
  --radarr-version=TAG        Version tag (e.g., v5.10.4.9218) – x64 only
  --radarr-pin=TAG            Alias for --radarr-version

  --prowlarr-url=URL          Use exact URL for Prowlarr tar.gz
  --prowlarr-branch=BRANCH    Override branch (default: main)

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
TS=$(date +%Y%m%d-%H%M%S)
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

check_url(){
  local url="$1"
  if command -v curl >/dev/null 2>&1; then
    # Prefer HEAD to avoid downloading large artifacts, but some APIs reject HEAD
    # (observed with Radarr endpoints). Fall back to a tiny GET.
    curl -fsIL --max-time 10 "$url" >/dev/null 2>&1 && return 0
    curl -fsSL --max-time 10 -r 0-0 -o /dev/null "$url" >/dev/null 2>&1
  elif command -v wget >/dev/null 2>&1; then
    wget -q --spider --timeout=10 "$url" >/dev/null 2>&1 && return 0
    wget -q -O /dev/null --timeout=10 --tries=1 --max-redirect=5 "$url" >/dev/null 2>&1
  else
    return 1
  fi
}

fetch(){
  # fetch <url> <outfile or ->
  local url="$1"; local out="$2"
  log_info "Fetching: $url"
  if [[ $DRY_RUN -eq 1 ]]; then
    if check_url "$url"; then 
      log_ok "URL reachable (dry-run)"
      return 0
    else 
      log_warn "URL not reachable (dry-run)"
      return 1
    fi
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

# Helper to append to .bashrc only if marker not present
append_to_bashrc_if_missing() {
  local content="$1"
  local marker="$2"
  
  if [[ $DRY_RUN -eq 1 ]]; then
    log_info "[dry-run] would check and append to ~/.bashrc (marker: $marker)"
    return 0
  fi
  
  if ! grep -q "$marker" "$HOME/.bashrc" 2>/dev/null; then
    echo "$content" >> "$HOME/.bashrc"
  else
    log_warn "Entry with marker '$marker' already exists in .bashrc, skipping"
  fi
}

# Central configuration (keep multi-use constants here)
SONARR_BRANCH="main"
RADARR_BRANCH="master"
PROWLARR_BRANCH="master"
SONARR_DL_BASE="https://services.sonarr.tv/v1/download"
SONARR_MAJOR="4"
RADARR_UPDATE_BASE="https://radarr.servarr.com/v1/update"
PROWLARR_UPDATE_BASE="https://prowlarr.servarr.com/v1/update"
DOTNET_ROOT_PATH="$HOME/.bin/dotnet"
JELLYFIN_CONFIG_DIR="$HOME/.config/jellyfin/config"
JELLYFIN_DATA_DIR="$HOME/.config/jellyfin/data"
JELLYFIN_LOG_DIR="$HOME/.config/jellyfin/log"
PUBLIC_IP=$(curl -fsS ifconfig.me 2>/dev/null || echo "unavailable")

# Apply arg overrides for branch/version
if [[ -n "$OVR_SONARR_BRANCH" ]]; then SONARR_BRANCH="$OVR_SONARR_BRANCH"; fi
if [[ -n "$OVR_RADARR_BRANCH" ]]; then RADARR_BRANCH="$OVR_RADARR_BRANCH"; fi
if [[ -n "$OVR_PROWLARR_BRANCH" ]]; then PROWLARR_BRANCH="$OVR_PROWLARR_BRANCH"; fi
if [[ -n "$OVR_SONARR_VERSION" ]]; then SONARR_MAJOR="$OVR_SONARR_VERSION"; fi

# Check required dependencies early
log_step "Checking dependencies..."
REQUIRED_CMDS=("ss" "tmux" "git" "python3" "tar" "dpkg" "getconf")
MISSING_CMDS=()

for cmd in "${REQUIRED_CMDS[@]}"; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    MISSING_CMDS+=("$cmd")
  fi
done

if [[ ${#MISSING_CMDS[@]} -gt 0 ]]; then
  log_err "Missing required commands: ${MISSING_CMDS[*]}"
  log_err "Please install missing dependencies and retry."
  exit 1
fi

# Check for curl or wget (at least one needed)
if ! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1; then
  log_err "Neither 'curl' nor 'wget' found. At least one is required."
  exit 1
fi

log_ok "All required dependencies found"

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
	log_err "Architecture '$ARCH' not supported."
	exit 1
	;;
esac

# Safety check for existing .bin
if [ -d "$HOME/.bin" ] && [ "$(ls -A "$HOME/.bin" 2>/dev/null)" ]; then
    if [[ $DRY_RUN -eq 1 ]]; then
      log_warn "~/.bin exists and would be removed (dry-run)."
    else
      printf "WARNING: ~/.bin exists and will be removed. Continue? (y/N): "
      read -r confirm
      [[ $confirm == [yY] ]] || exit 1
      rm -rf "$HOME/.bin"
    fi
elif [ -d "$HOME/.bin" ]; then
  # Directory exists but is empty
  [[ $DRY_RUN -eq 1 ]] || rm -rf "$HOME/.bin"
fi

if [[ $DRY_RUN -eq 0 ]]; then
  mkdir -p "$HOME"/.config/{radarr,sonarr,prowlarr,jellyfin,sabnzbd,cloudplow}
  mkdir -p "$HOME/.bin"
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
  SABNZBD_URL=$(curl -s https://api.github.com/repos/sabnzbd/sabnzbd/releases/latest | grep -E 'browser_download_url' | grep -- '-src' | cut -d '"' -f 4 || true)
fi

# Jellyfin (Repo Scraping)
# Fetches from repo.jellyfin.org structure: files/server/linux/latest-stable/<arch>/
JF_REPO_BASE="https://repo.jellyfin.org/files/server/linux/latest-stable/${JF_ARCH}/"
# Find filename like jellyfin_10.X.Y-amd64.tar.gz
if [[ -n "$OVR_JELLYFIN_URL" ]]; then
  JELLYFIN_URL="$OVR_JELLYFIN_URL"; JF_FILENAME="override"
else
  JF_FILENAME=$(curl -s "$JF_REPO_BASE" | grep -oE "jellyfin_[0-9]+\\.[0-9]+\\.[0-9]+-${JF_ARCH}\\.tar\\.gz" | head -n 1)
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

# If verify-only, check URLs and exit
if [[ $VERIFY_ONLY -eq 1 ]]; then
  log_step "Verifying URLs..."
  urls_to_check=("${SABNZBD_URL:-}" "${JELLYFIN_URL:-}" "${ASPDOTNET_URL:-}")
  all_ok=true
  for url in "${urls_to_check[@]}"; do
    if [[ -n "$url" ]]; then
      if check_url "$url"; then
        log_ok "URL reachable: $url"
      else
        log_err "URL not reachable: $url"
        all_ok=false
      fi
    fi
  done
  if [[ "$all_ok" == true ]]; then
    log_ok "All URLs verified successfully"
    exit 0
  else
    log_err "Some URLs failed verification"
    exit 1
  fi
fi

# Enhanced port randomizer with bind test (for shared env security)
random_open_port() {
	local LOW_BOUND=10000
	local UPPER_BOUND=65000
	local candidate
	local attempts=0
	local max_attempts=100
	
	# Check required commands
	if ! command -v comm >/dev/null 2>&1 || ! command -v shuf >/dev/null 2>&1 || ! command -v seq >/dev/null 2>&1; then
		log_err "Required commands (comm, shuf, seq) not found for port randomization"
		exit 1
	fi
	
	while [ $attempts -lt $max_attempts ]; do
		candidate=$(comm -23 <(seq "${LOW_BOUND}" "${UPPER_BOUND}" | sort -u) <(ss -Htan | awk '{print $4}' | rev | cut -d':' -f1 | rev | sort -u) | shuf | head -n 1)
		if [[ -z "$candidate" ]]; then
			# Fallback: random port in range
			candidate=$((LOW_BOUND + RANDOM % (UPPER_BOUND - LOW_BOUND)))
		fi
		# Test bind with Python (available on Debian 10+)
		if python3 -c "import socket; s=socket.socket(socket.AF_INET, socket.SOCK_STREAM); s.bind(('127.0.0.1', $candidate)); s.close(); exit(0)" 2>/dev/null; then
			echo "$candidate"
			return
		fi
		attempts=$((attempts + 1))
	done
	log_err "Failed to find available port after $max_attempts attempts"
	exit 1
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
	log_err "Error: 'python3-venv' is missing."
	log_err "This script requires Python 3 virtual environment support."
	log_err "Please ask support to install 'python3-venv' or 'python3-full'."
	exit 1
fi

# Kill existing tmux sessions per app first
log_step "Stopping existing sessions..."
if [[ $DRY_RUN -eq 0 ]]; then
	for app in sabnzbd radarr prowlarr sonarr jellyfin cloudplow; do
		tmux kill-session -t "${app}" 2>/dev/null || true
	done
fi

# Install Cloudplow (unchanged, repo active)
app="cloudplow"
log_step "Installing ${app^^}..."
installdir="$HOME/.bin/cloudplow"
datadir="$HOME/.config/cloudplow"
mkdir -p "$datadir"
if [[ $DRY_RUN -eq 0 ]]; then
  python3 -m venv "$installdir"
  cd "$installdir"
  git clone https://github.com/l3uddz/cloudplow.git >/dev/null 2>&1
  # shellcheck disable=SC1091
  source "${installdir}/bin/activate"
  python -m pip install -U pip >/dev/null 2>&1
  python3 -m pip install -r "${installdir}/cloudplow/requirements.txt" >/dev/null 2>&1
  deactivate
  echo "${app^^} Installed"
else
  log_info "[dry-run] would install Cloudplow via git clone and venv"
fi
echo ""

# Install SABnzbd (minor updates)
app="sabnzbd"
log_step "Installing ${app^^}..."
installdir="$HOME/.bin/sabnzbd"
datadir="$HOME/.config/sabnzbd"
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app}" >/dev/null 2>&1 || true
if [[ $DRY_RUN -eq 0 ]]; then
  python3 -m venv "$installdir"
  cd "$installdir"
  echo "Downloading...${app^^} (${SABNZBD_VERSION})"
  if [[ -z "${SABNZBD_URL:-}" ]]; then log_err "SABnzbd URL not resolved"; exit 1; fi
  if ! fetch "${SABNZBD_URL}" "${app}.tar.gz"; then log_err "Failed to download SABnzbd"; exit 1; fi
  mkdir -p "${app}"
  extract_tgz "${app}.tar.gz" "${app}" 1
  # shellcheck disable=SC1091
  source "${installdir}/bin/activate"
  python -m pip install -U pip >/dev/null 2>&1
  python3 -m pip install -r "${installdir}/${app}/requirements.txt" >/dev/null 2>&1
  deactivate
  echo "${app^^} Installed"
else
  log_info "[dry-run] would create SABnzbd venv and install requirements"
fi
echo "Configuring ${app^^}"
if [[ $DRY_RUN -eq 0 ]]; then
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
else
  log_info "[dry-run] would configure ${app^^} (port=${SABNZBD_PORT}, url_base=/public-${USERNAME}/${app})"
fi
echo "${app^^} configured"
echo ""

# Install Radarr (branch master; Debian 11 fallback)
app="radarr"
echo "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$HOME/.config/${app}"
branch="$RADARR_BRANCH"
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app^}" >/dev/null 2>&1 || true
GLIBC_VER=$(getconf GNU_LIBC_VERSION 2>/dev/null | awk '{print $2}')
if [[ -n "$OVR_RADARR_URL" ]]; then
  DLURL="$OVR_RADARR_URL"
elif [[ -n "$OVR_RADARR_VERSION" && "$SERVARR_ARCH" == "x64" ]]; then
  DLURL="https://github.com/Radarr/Radarr/releases/download/${OVR_RADARR_VERSION}/Radarr.master.${OVR_RADARR_VERSION#v}.linux-core-x64.tar.gz"
else
  if dpkg --compare-versions "${GLIBC_VER:-0}" ge 2.33; then
    DLURL="${RADARR_UPDATE_BASE}/${branch}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
  else
    if [[ "$SERVARR_ARCH" == "x64" ]]; then
      DLURL="https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz"
      log_warn "Detected GLIBC ${GLIBC_VER:-unknown} < 2.33 → pinning Radarr to v5.10.4.9218 (linux-core-x64)."
    else
      DLURL="${RADARR_UPDATE_BASE}/${branch}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
    fi
  fi
fi
log_info "Radarr URL: $DLURL"

echo "Downloading...${app^^}"
cd "$installdir"
if ! fetch "$DLURL" "Radarr.tar.gz"; then log_err "Failed to download Radarr"; exit 1; fi
extract_tgz "Radarr.tar.gz"
if [[ $DRY_RUN -eq 0 ]]; then
  touch "$datadir"/update_required
fi
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [[ $DRY_RUN -eq 0 ]]; then
  if [ ! -f "$datadir/config.xml" ]; then
    cat <<EOF >"$datadir/config.xml"
<Config>
  <UrlBase></UrlBase>
  <Port>7878</Port>
  <BindAddress>*</BindAddress>
</Config>
EOF
  fi
  sed -i -E "s|<Port>[^<]*</Port>|<Port>${RADARR_PORT}</Port>|g" "$datadir/config.xml"
  sed -i -E "s|<UrlBase>[^<]*</UrlBase>|<UrlBase>/public-${USERNAME}/${app}</UrlBase>|g" "$datadir/config.xml"
  sed -i -E "s|<BindAddress>[^<]*</BindAddress>|<BindAddress>127.0.0.1</BindAddress>|g" "$datadir/config.xml"
else
  log_info "[dry-run] would configure ${app^^} (port=${RADARR_PORT}, url_base=/public-${USERNAME}/${app})"
fi
echo "${app^^} configured"
echo ""

# Install Prowlarr (branch master)
app="prowlarr"
log_step "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$HOME/.config/${app}"
branch="$PROWLARR_BRANCH" # Stable
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app^}" >/dev/null 2>&1 || true
if [[ -n "$OVR_PROWLARR_URL" ]]; then
  DLURL="$OVR_PROWLARR_URL"
else
  DLURL="${PROWLARR_UPDATE_BASE}/${branch}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
fi
log_info "Prowlarr URL: $DLURL"

echo "Downloading...${app^^}"
cd "$installdir"
if ! fetch "$DLURL" "Prowlarr.tar.gz"; then log_err "Failed to download Prowlarr"; exit 1; fi
extract_tgz "Prowlarr.tar.gz"
if [[ $DRY_RUN -eq 0 ]]; then
  touch "$datadir"/update_required
fi
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [[ $DRY_RUN -eq 0 ]]; then
  if [ ! -f "$datadir/config.xml" ]; then
    cat <<EOF >"$datadir/config.xml"
<Config>
  <UrlBase></UrlBase>
  <Port>9696</Port>
  <BindAddress>*</BindAddress>
</Config>
EOF
  fi
  sed -i -E "s|<Port>[^<]*</Port>|<Port>${PROWLARR_PORT}</Port>|g" "$datadir/config.xml"
  sed -i -E "s|<UrlBase>[^<]*</UrlBase>|<UrlBase>/public-${USERNAME}/${app}</UrlBase>|g" "$datadir/config.xml"
  sed -i -E "s|<BindAddress>[^<]*</BindAddress>|<BindAddress>127.0.0.1</BindAddress>|g" "$datadir/config.xml"
else
  log_info "[dry-run] would configure ${app^^} (port=${PROWLARR_PORT}, url_base=/public-${USERNAME}/${app})"
fi
echo "${app^^} configured"
echo ""

# Install Sonarr (services.sonarr.tv download API; branch main)
app="sonarr"
log_step "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$HOME/.config/${app}"
branch="$SONARR_BRANCH"
mkdir -p "$datadir"
pkill -9 -f -u "$USERNAME" "${app^}" >/dev/null 2>&1 || true
if [[ -n "$OVR_SONARR_URL" ]]; then
  DLURL="$OVR_SONARR_URL"
else
  DLURL="${SONARR_DL_BASE}/${branch}/latest?version=${SONARR_MAJOR}&os=linux&arch=${SERVARR_ARCH}"
fi
log_info "Sonarr URL: $DLURL"

echo "Downloading...${app^^}"
cd "$installdir"
if ! fetch "$DLURL" "Sonarr.tar.gz"; then log_err "Failed to download Sonarr"; exit 1; fi
extract_tgz "Sonarr.tar.gz"
if [[ $DRY_RUN -eq 0 ]]; then
  touch "$datadir"/update_required
fi
echo "${app^^} Installed"
echo "Configuring ${app^^}"
if [[ $DRY_RUN -eq 0 ]]; then
  if [ ! -f "$datadir/config.xml" ]; then
    cat <<EOF >"$datadir/config.xml"
<Config>
  <Port>8989</Port>
  <UrlBase></UrlBase>
  <BindAddress>*</BindAddress>
</Config>
EOF
  fi
  sed -i -E "s|<Port>[^<]*</Port>|<Port>${SONARR_PORT}</Port>|g" "$datadir/config.xml"
  sed -i -E "s|<UrlBase>[^<]*</UrlBase>|<UrlBase>/public-${USERNAME}/${app}</UrlBase>|g" "$datadir/config.xml"
  sed -i -E "s|<BindAddress>[^<]*</BindAddress>|<BindAddress>127.0.0.1</BindAddress>|g" "$datadir/config.xml"
else
  log_info "[dry-run] would configure ${app^^} (port=${SONARR_PORT}, url_base=/public-${USERNAME}/${app})"
fi
echo "${app^^} configured"
echo ""

# Install ASP.NET Core (.NET 8)
app="aspnetcore"
log_step "Installing ${app^^}..."
echo "Downloading...${app^^}"
installdir="$HOME/.bin/dotnet"
mkdir -p "$installdir"
cd "$installdir"
if ! fetch "$ASPDOTNET_URL" "aspnetcore.tar.gz"; then log_err "Failed to download ASP.NET runtime"; exit 1; fi
if [[ $DRY_RUN -eq 0 ]]; then
  if [ ! -f "aspnetcore.tar.gz" ]; then
    log_err "Failed to find downloaded ASP.NET archive"
    exit 1
  fi
  tar -xvzf "aspnetcore.tar.gz" >/dev/null 2>&1
  rm -f "aspnetcore.tar.gz" >/dev/null 2>&1
  echo "Installation files downloaded and extracted"
  echo "${app^^} Installed"
else
  log_info "[dry-run] would extract aspnetcore.tar.gz"
fi
append_to_bashrc_if_missing '# Added by PMSS media stack installer (.NET 8)
export DOTNET_ROOT=$HOME/.bin/dotnet
export PATH=$HOME/.bin:$DOTNET_ROOT:$PATH' 'PMSS media stack installer'
chmod 0640 "$HOME/.bashrc" 2>/dev/null || true
echo ""

# Install Jellyfin (URL, extract, no chmod)
app="jellyfin"
log_step "Installing ${app^^}..."
installdir="$HOME/.bin"
datadir="$JELLYFIN_DATA_DIR"
configdir="$JELLYFIN_CONFIG_DIR"
logdir="$JELLYFIN_LOG_DIR"
mkdir -p "$datadir" "$logdir" "$configdir"
cd "$installdir"
echo "Downloading...${app^^}"
if ! fetch "${JELLYFIN_URL}" "${app}.tar.gz"; then log_err "Failed to download Jellyfin"; exit 1; fi
mkdir -p "${app}"
extract_tgz "${app}.tar.gz" "${app}" 1
[[ $DRY_RUN -eq 1 ]] || echo "${app^^} Installed"

if [[ $DRY_RUN -eq 0 ]]; then
  if [[ -f "$HOME/.bin/jellyfin/jellyfin.dll" ]]; then
    tmux new-session -d -s "jellyfin" "export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; export JELLYFIN_CONFIG_DIR=\"$JELLYFIN_CONFIG_DIR\"; export JELLYFIN_DATA_DIR=\"$JELLYFIN_DATA_DIR\"; export JELLYFIN_LOG_DIR=\"$JELLYFIN_LOG_DIR\"; cd \"$HOME/.bin/jellyfin\" && nice -n 19 \"$DOTNET_ROOT_PATH/dotnet\" jellyfin.dll 2>&1 | tee -a \"$JELLYFIN_LOG_DIR/jellyfin.log\"" || log_warn "Failed to create jellyfin tmux session"
  else
    log_err "Jellyfin DLL not found at $HOME/.bin/jellyfin/jellyfin.dll"
  fi
  tmux kill-session -t "jellyfin" 2>/dev/null || true
fi

echo "Configuring ${app^^}"
if [[ $DRY_RUN -eq 0 ]]; then
  if [ ! -f "$configdir/network.xml" ]; then
    cat <<EOF >"$configdir/network.xml"
<?xml version="1.0" encoding="utf-8"?>
<NetworkConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <EnableUPnP>false</EnableUPnP>
  <PublicHttpPort>$JELLYFIN_PORT</PublicHttpPort>
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
  <InternalHttpPort>$JELLYFIN_PORT</InternalHttpPort>
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
  sed -i -E "s|(<PublicHttpPort>)[^<]*(</PublicHttpPort>)|\1$JELLYFIN_PORT\2|g" "$configdir/network.xml"
  sed -i -E "s|(<InternalHttpPort>)[^<]*(</InternalHttpPort>)|\1$JELLYFIN_PORT\2|g" "$configdir/network.xml"
  sed -i -E "s|<BaseUrl />|<BaseUrl></BaseUrl>|g" "$configdir/network.xml"
  sed -i -E "s|(<BaseUrl>)[^<]*(</BaseUrl>)|\1/public-${USERNAME}/${app}\2|g" "$configdir/network.xml"
  syscfg="$configdir/system.xml"
  if [ ! -f "$syscfg" ]; then
    cat > "$syscfg" <<SYSXML
<?xml version="1.0" encoding="utf-8"?>
<ServerConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <BaseUrl>/public-${USERNAME}/${app}</BaseUrl>
SYSXML
    # Add FFmpegPath if provided, otherwise close the tag
    if [[ -n "$OVR_JELLYFIN_FFMPEG" ]]; then
      echo "  <FFmpegPath>${OVR_JELLYFIN_FFMPEG}</FFmpegPath>" >> "$syscfg"
    fi
    echo "</ServerConfiguration>" >> "$syscfg"
  else
    # Update existing system.xml - merge settings
    if grep -q "<BaseUrl>" "$syscfg"; then
      sed -i -E "s|<BaseUrl>[^<]*</BaseUrl>|<BaseUrl>/public-${USERNAME}/${app}</BaseUrl>|g" "$syscfg"
    else
      sed -i -E "s|</ServerConfiguration>|  <BaseUrl>/public-${USERNAME}/${app}</BaseUrl>\n</ServerConfiguration>|" "$syscfg"
    fi
    # Handle FFmpegPath - merge with existing config
    if [[ -n "$OVR_JELLYFIN_FFMPEG" ]]; then
      if grep -q "<FFmpegPath>" "$syscfg"; then
        sed -i -E "s|<FFmpegPath>[^<]*</FFmpegPath>|<FFmpegPath>${OVR_JELLYFIN_FFMPEG}</FFmpegPath>|g" "$syscfg"
      else
        sed -i -E "s|</ServerConfiguration>|  <FFmpegPath>${OVR_JELLYFIN_FFMPEG}</FFmpegPath>\n</ServerConfiguration>|" "$syscfg"
      fi
    fi
  fi
else
  log_info "[dry-run] would configure ${app^^} (port=${JELLYFIN_PORT}, url_base=/public-${USERNAME}/${app})"
  if [[ -n "$OVR_JELLYFIN_FFMPEG" ]]; then
    log_info "[dry-run] would set Jellyfin FFmpegPath to '$OVR_JELLYFIN_FFMPEG' in $configdir/system.xml"
  fi
fi
echo "${app^^} configured"
echo ""

# Aliases (Sonarr fix, PATH added above)
append_to_bashrc_if_missing '# PMSS Media stack aliases (updated Nov 2025)
alias jellyfin='\''tmux new-session -d -s "jellyfin" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; export JELLYFIN_CONFIG_DIR=\"$HOME/.config/jellyfin/config\"; export JELLYFIN_DATA_DIR=\"$HOME/.config/jellyfin/data\"; export JELLYFIN_LOG_DIR=\"$HOME/.config/jellyfin/log\"; nice -n 19 \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/jellyfin/jellyfin.dll\""'\''
alias sonarr='\''tmux new-session -d -s "sonarr" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/Sonarr/Sonarr.dll\" --data=\"$HOME/.config/sonarr\""'\''
alias radarr='\''tmux new-session -d -s "radarr" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/Radarr/Radarr.dll\" --nobrowser --data=\"$HOME/.config/radarr\""'\''
alias prowlarr='\''tmux new-session -d -s "prowlarr" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/Prowlarr/Prowlarr.dll\" --nobrowser --data=\"$HOME/.config/prowlarr\""'\''

alias cloudplow='\''tmux new-session -d -s "cloudplow" "source $HOME/.bin/cloudplow/bin/activate && python3 $HOME/.bin/cloudplow/cloudplow/cloudplow.py run --config=$HOME/.config/cloudplow/config.json --loglevel=DEBUG --cachefile=$HOME/.config/cloudplow/cache.db --logfile=$HOME/.config/cloudplow/cloudplow.log"'\''
alias sabnzbd='\''tmux new-session -d -s "sabnzbd" "source $HOME/.bin/sabnzbd/bin/activate && nice -n 19 python3 $HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py -b 0 -f $HOME/.config/sabnzbd/sabnzbd.ini"'\''' 'PMSS Media stack aliases'

# Lighttpd config (use $HOSTNAME)
if [[ $DRY_RUN -eq 0 ]]; then
  mkdir -p "$HOME/.lighttpd"
  if [[ -f "$HOME/.lighttpd/custom" ]]; then
    backup_stamp=$(date +%Y%m%d)
    if ! cp "$HOME/.lighttpd/custom" "$HOME/.lighttpd/backup.custom.${backup_stamp}" 2>/dev/null; then
      log_warn "Failed to backup existing lighttpd custom config"
    fi
  fi
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
else
  log_info "[dry-run] would create lighttpd config at ~/.lighttpd/custom"
fi


# shellcheck source=/dev/null
set +u
source "$HOME/.bashrc" || true
set -u

echo ""
log_step "Starting applications"
# Corrected: Point to dotnet binary, not the directory
if [[ $DRY_RUN -eq 0 ]]; then
  # Verify required files exist before starting
  if [[ ! -f "$DOTNET_ROOT_PATH/dotnet" ]]; then
    log_err "dotnet binary not found at $DOTNET_ROOT_PATH/dotnet"
    exit 1
  fi
  
  # Ensure log directories exist
  mkdir -p "$JELLYFIN_DATA_DIR/log" "$HOME/.config/sonarr" "$HOME/.config/radarr" "$HOME/.config/prowlarr" "$HOME/.config/sabnzbd"
  
  # Start Jellyfin
  if [[ -f "$HOME/.bin/jellyfin/jellyfin.dll" ]]; then
    tmux new-session -d -s "jellyfin" "export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; export JELLYFIN_CONFIG_DIR=\"$JELLYFIN_CONFIG_DIR\"; export JELLYFIN_DATA_DIR=\"$JELLYFIN_DATA_DIR\"; export JELLYFIN_LOG_DIR=\"$JELLYFIN_LOG_DIR\"; cd \"$HOME/.bin/jellyfin\" && nice -n 19 \"$DOTNET_ROOT_PATH/dotnet\" jellyfin.dll 2>&1 | tee -a \"$JELLYFIN_LOG_DIR/jellyfin.log\"" || log_warn "Failed to create jellyfin tmux session"
  else
    log_err "Jellyfin DLL not found at $HOME/.bin/jellyfin/jellyfin.dll"
  fi
  
  # Start Sonarr
  if [[ -f "$HOME/.bin/Sonarr/Sonarr.dll" ]]; then
    tmux new-session -d -s "sonarr" "export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; cd \"$HOME/.bin/Sonarr\" && \"$DOTNET_ROOT_PATH/dotnet\" Sonarr.dll --data=\"$HOME/.config/sonarr\" 2>&1 | tee -a \"$HOME/.config/sonarr/sonarr.log\"" || log_warn "Failed to create sonarr tmux session"
  else
    log_err "Sonarr DLL not found at $HOME/.bin/Sonarr/Sonarr.dll"
  fi
  
  # Start Radarr
  if [[ -f "$HOME/.bin/Radarr/Radarr.dll" ]]; then
    tmux new-session -d -s "radarr" "export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; cd \"$HOME/.bin/Radarr\" && \"$DOTNET_ROOT_PATH/dotnet\" Radarr.dll --nobrowser --data=\"$HOME/.config/radarr\" 2>&1 | tee -a \"$HOME/.config/radarr/radarr.log\"" || log_warn "Failed to create radarr tmux session"
  else
    log_err "Radarr DLL not found at $HOME/.bin/Radarr/Radarr.dll"
  fi
  
  # Start Prowlarr
  if [[ -f "$HOME/.bin/Prowlarr/Prowlarr.dll" ]]; then
    tmux new-session -d -s "prowlarr" "export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; cd \"$HOME/.bin/Prowlarr\" && \"$DOTNET_ROOT_PATH/dotnet\" Prowlarr.dll --nobrowser --data=\"$HOME/.config/prowlarr\" 2>&1 | tee -a \"$HOME/.config/prowlarr/prowlarr.log\"" || log_warn "Failed to create prowlarr tmux session"
  else
    log_err "Prowlarr DLL not found at $HOME/.bin/Prowlarr/Prowlarr.dll"
  fi
  
  # Start SABnzbd
  if [[ -f "$HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py" ]]; then
    tmux new-session -d -s "sabnzbd" "source $HOME/.bin/sabnzbd/bin/activate && cd \"$HOME/.bin/sabnzbd/sabnzbd\" && nice -n 19 python3 SABnzbd.py -b 0 -f $HOME/.config/sabnzbd/sabnzbd.ini 2>&1 | tee -a \"$HOME/.config/sabnzbd/sabnzbd.log\"" || log_warn "Failed to create sabnzbd tmux session"
  else
    log_err "SABnzbd not found at $HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py"
  fi
  
  # Start Cloudplow
  if [[ -f "$HOME/.bin/cloudplow/cloudplow/cloudplow.py" ]]; then
    tmux new-session -d -s "cloudplow" "source $HOME/.bin/cloudplow/bin/activate && python3 $HOME/.bin/cloudplow/cloudplow/cloudplow.py run --config=$HOME/.config/cloudplow/config.json --loglevel=DEBUG --cachefile=$HOME/.config/cloudplow/cache.db --logfile=$HOME/.config/cloudplow/cloudplow.log" || log_warn "Failed to create cloudplow tmux session"
  else
    log_err "Cloudplow not found at $HOME/.bin/cloudplow/cloudplow/cloudplow.py"
  fi
  
  # Wait a moment and verify sessions are still running
  sleep 2
  log_step "Verifying started applications..."
  for app in jellyfin sonarr radarr prowlarr sabnzbd cloudplow; do
    if tmux has-session -t "$app" 2>/dev/null; then
      log_ok "$app session is running"
    else
      log_warn "$app session exited immediately - check logs or run: tmux attach -t $app (if session exists)"
      log_info "To diagnose, try: tmux new-session -s ${app}-debug 'cd ~/.bin/${app}* && ...'"
    fi
  done
else
  log_info "[dry-run] would start tmux sessions: jellyfin, sonarr, prowlarr, sabnzbd, cloudplow"
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
