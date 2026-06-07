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
# SECURITY MODEL
# All apps are reverse-proxied under /public-<user>/<app> which has NO
# proxy-level authentication. App-level auth is the USER's responsibility:
#   - Jellyfin: first-run wizard forces admin account creation
#   - Radarr/Sonarr/Prowlarr: enable Forms auth in Settings > General > Authentication
#   - SABnzbd: first-run wizard sets credentials
# Until users configure auth, Radarr/Sonarr/Prowlarr are accessible to anyone
# who knows the URL. The installer prints a security warning at completion.
#
# #TODO: Integrate with /opt sonarr/radarr (e.g., symlink or detect)
# #TODO: Systemd service generation instead of tmux
# #TODO: Docker Compose mode option for isolation
# #TODO: Hover on status → show systemctl logs (if systemd)
# #TODO: Click status → attempt restart (if allowed)
#

# Self-update unless explicitly skipped, but only when running interactively (TTY).
# Uninstall intentionally uses the local copy so cleanup never turns into install
# if an older remote copy does not know the new mode yet.
PMSS_MEDIA_STACK_SELF_UPDATE=1
for pmss_media_stack_arg in "$@"; do
	case "$pmss_media_stack_arg" in
	--skip-update | --uninstall) PMSS_MEDIA_STACK_SELF_UPDATE=0 ;;
	esac
done
unset pmss_media_stack_arg

if [[ $PMSS_MEDIA_STACK_SELF_UPDATE -eq 1 && -t 0 ]]; then
	REMOTE_RAW_URL="https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/install-media-stack.sh"
	tmp=$(mktemp) || true
	if [[ -n "$tmp" ]]; then
		if command -v curl >/dev/null 2>&1; then
			curl -fsSL "$REMOTE_RAW_URL" -o "$tmp" || true
		elif command -v wget >/dev/null 2>&1; then
			wget -q "$REMOTE_RAW_URL" -O "$tmp" || true
		fi
		if [[ -s "$tmp" ]]; then
			# Verify downloaded script looks like a PMSS installer (not HTML error page, binary, etc.)
			if ! head -1 "$tmp" | grep -q '^#!/usr/bin/env bash$'; then
				echo "[WARN] Downloaded script has unexpected shebang — refusing to exec, using local copy" >&2
				rm -f "$tmp" 2>/dev/null || true
			elif ! grep -q '^# PMSS: .* Installer' "$tmp"; then
				echo "[WARN] Downloaded script missing PMSS marker — refusing to exec, using local copy" >&2
				rm -f "$tmp" 2>/dev/null || true
			else
				chmod +x "$tmp" 2>/dev/null || true
				exec "$tmp" --skip-update "$@"
			fi
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
FORCE_INSTALL=0
UNINSTALL=0
MEDIA_STACK_MIN_MEMORY_MIB=1024
MEDIA_STACK_MIN_MEMORY_BYTES=$((MEDIA_STACK_MIN_MEMORY_MIB * 1024 * 1024))
MEDIA_STACK_UNLIMITED_MEMORY_BYTES=$((1024 * 1024 * 1024 * 1024 * 1024))

# Overrides (initialized empty)
OVR_SONARR_URL=""
OVR_SONARR_BRANCH=""
OVR_SONARR_VERSION=""
OVR_RADARR_URL=""
OVR_RADARR_BRANCH=""
OVR_RADARR_VERSION=""
OVR_PROWLARR_URL=""
OVR_PROWLARR_BRANCH=""
OVR_SAB_URL=""
OVR_SAB_VERSION=""
OVR_JELLYFIN_URL=""
OVR_JELLYFIN_FFMPEG=""
JELLYFIN_INSTALL_ENABLED=1

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
  --force                     Continue below the ${MEDIA_STACK_MIN_MEMORY_MIB} MiB memory guard
  --uninstall                 Stop media-stack sessions and remove PMSS-managed files
USAGE
}

for arg in "$@"; do
	case "$arg" in
	--help | -h)
		print_usage
		exit 0
		;;
	--dry-run) DRY_RUN=1 ;;
	--force) FORCE_INSTALL=1 ;;
	--uninstall) UNINSTALL=1 ;;
	--verify-only)
		VERIFY_ONLY=1
		DRY_RUN=1
		;;
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

# Reserved for future SABnzbd version pinning; keep the arg plumbed.
: "${OVR_SAB_VERSION}"

media_stack_home_path_is_safe() {
	[[ -n "${HOME:-}" && "$HOME" == /* && "$HOME" != "/" ]] && return 0
	return 1
}

if ! media_stack_home_path_is_safe; then
	printf '[ERR ] Refusing to run media stack installer with unsafe HOME: %s\n' "${HOME:-unset}" >&2
	exit 1
fi

# Logging
LOG_FILE="$HOME/.install-media-stack.log"
mkdir -p "$(dirname "$LOG_FILE")" >/dev/null 2>&1 || true
touch "$LOG_FILE" 2>/dev/null || true
chmod 600 "$LOG_FILE" 2>/dev/null || true
exec > >(tee -a "$LOG_FILE") 2>&1

# Colors if tty
if [[ -t 1 ]]; then
	C_RESET="\033[0m"
	C_INFO="\033[1;34m"
	C_OK="\033[1;32m"
	C_WARN="\033[1;33m"
	C_ERR="\033[1;31m"
	C_STEP="\033[1;36m"
else
	C_RESET=""
	C_INFO=""
	C_OK=""
	C_WARN=""
	C_ERR=""
	C_STEP=""
fi

log_step() { echo -e "${C_STEP}==> $*${C_RESET}"; }
log_info() { echo -e "${C_INFO}[INFO]${C_RESET} $*"; }
log_ok() { echo -e "${C_OK}[ OK ]${C_RESET} $*"; }
log_warn() { echo -e "${C_WARN}[WARN]${C_RESET} $*"; }
log_err() { echo -e "${C_ERR}[ERR ]${C_RESET} $*"; }

# Checksums file for download integrity verification
CHECKSUMS_FILE="$HOME/.install-checksums.sha256"
CHECKSUMS_RAW_URL="https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/.install-checksums.sha256"

# Fetch latest checksums file from repo (best-effort, non-blocking)
fetch_checksums_file() {
	local tmp_ck
	tmp_ck=$(mktemp) || return 1
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$CHECKSUMS_RAW_URL" -o "$tmp_ck" 2>/dev/null || true
	elif command -v wget >/dev/null 2>&1; then
		wget -q "$CHECKSUMS_RAW_URL" -O "$tmp_ck" 2>/dev/null || true
	fi
	if [[ -s "$tmp_ck" ]] && head -1 "$tmp_ck" | grep -q '^# PMSS'; then
		mv "$tmp_ck" "$CHECKSUMS_FILE" 2>/dev/null || true
		chmod 600 "$CHECKSUMS_FILE" 2>/dev/null || true
	else
		rm -f "$tmp_ck" 2>/dev/null || true
	fi
}

# Verify SHA256 checksum of a downloaded file against the checksums file.
# - artifact_id not found in checksums file: warn, return 0 (allow new versions)
# - artifact_id found but hash mismatches: error, return 1 (corruption/compromise)
verify_checksum() {
	local file="$1" artifact_id="$2"
	if [[ ! -f "$file" ]]; then
		return 0 # File not present (e.g., dry-run mode) — skip
	fi
	if [[ ! -f "$CHECKSUMS_FILE" ]]; then
		log_warn "No checksums file — skipping verification for $(basename "$file")"
		return 0
	fi
	local expected_hash
	expected_hash=$(grep -F "  ${artifact_id}" "$CHECKSUMS_FILE" 2>/dev/null | grep -v '^#' | awk '{print $1}' | head -1)
	if [[ -z "$expected_hash" ]]; then
		log_warn "No checksum on file for '${artifact_id}' — skipping verification"
		return 0
	fi
	local actual_hash
	actual_hash=$(sha256sum "$file" | cut -d' ' -f1)
	if [[ "$actual_hash" != "$expected_hash" ]]; then
		log_err "CHECKSUM MISMATCH for ${artifact_id}"
		log_err "  Expected: $expected_hash"
		log_err "  Got:      $actual_hash"
		log_err "  This could indicate a corrupted download or supply chain compromise."
		log_err "  Re-run the installer to retry. If this persists, report to support."
		return 1
	fi
	log_ok "Checksum verified: ${artifact_id}"
	return 0
}

# Escape XML special characters in user-supplied values before writing to config.
# Note: in bash ${//pattern/replacement}, & in replacement = matched text; use \& for literal &.
xml_escape() {
	local s="$1"
	s="${s//&/\&amp;}"
	s="${s//</\&lt;}"
	s="${s//>/\&gt;}"
	s="${s//\"/\&quot;}"
	s="${s//\'/\&apos;}"
	printf '%s' "$s"
}

sed_replacement_escape() {
	local s="${1//\\/\\\\}"
	s="${s//&/\\&}"
	printf '%s' "${s//|/\\|}"
}

check_url() {
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

fetch_text() {
	local url="$1"

	if command -v curl >/dev/null 2>&1; then
		curl -fsSL --max-time 20 "$url" 2>/dev/null
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O - --timeout=20 --tries=1 "$url" 2>/dev/null
	else
		return 1
	fi
}

fetch() {
	# fetch <url> <outfile or ->
	local url="$1"
	local out="$2"
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

fetch_verified_archive() {
	local url="$1" archive="$2" label="$3" checksum_id="${4:-}"
	[[ -n "$checksum_id" ]] || checksum_id=$(basename "${url%%\?*}")

	if ! fetch "$url" "$archive"; then
		log_err "Failed to download ${label}"
		exit 1
	fi
	if ! verify_checksum "$archive" "$checksum_id"; then
		log_err "${label} download failed integrity check — aborting"
		exit 1
	fi
}

extract_tgz() {
	# extract_tgz <archive> [target_dir] [strip_components]
	local a="$1" t="${2:-.}" s="${3:-}"
	local tar_args=(-xzf "$a" -C "$t")
	if [[ $DRY_RUN -eq 1 ]]; then
		log_info "[dry-run] would extract $a to $t${s:+ (strip $s)}"
		return
	fi
	[[ -n "$s" ]] && tar_args+=(--strip-components="$s")
	tar "${tar_args[@]}" >/dev/null 2>&1
	rm -f "$a" >/dev/null 2>&1
	echo "Installation files downloaded and extracted"
}

python_venv_install_requirements() {
	local venv_dir="$1" requirements="$2"

	# shellcheck disable=SC1091
	source "${venv_dir}/bin/activate"
	python3 -m pip install -U pip >/dev/null 2>&1
	python3 -m pip install -r "$requirements" >/dev/null 2>&1
	deactivate
}

jellyfin_ffmpeg_binary_version() { [[ -x "$1" ]] && "$1" -version 2>/dev/null | awk 'NR == 1 {print $3; exit}'; }

jellyfin_ffmpeg_version_usable() {
	local version="$1"
	[[ "$version" =~ ^[0-9] ]] || return 1
	dpkg --compare-versions "$version" ge "$JELLYFIN_MIN_FFMPEG_VERSION"
}

jellyfin_ffmpeg_configure_fallback() {
	local home_ffmpeg="$HOME/.bin/ffmpeg"
	local system_ffmpeg=""
	local version=""

	if [[ -n "$OVR_JELLYFIN_FFMPEG" ]]; then
		log_info "Using explicit Jellyfin FFmpeg path: $OVR_JELLYFIN_FFMPEG"
		return 0
	fi

	if [[ -x "$home_ffmpeg" ]]; then
		version=$(jellyfin_ffmpeg_binary_version "$home_ffmpeg" || true)
		if jellyfin_ffmpeg_version_usable "$version"; then
			OVR_JELLYFIN_FFMPEG="$home_ffmpeg"
			log_info "Using existing user FFmpeg ${version} at $home_ffmpeg"
			return 0
		fi
		log_warn "Existing $home_ffmpeg is below Jellyfin FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION} requirement (${version:-unknown})"
	fi

	if command -v ffmpeg >/dev/null 2>&1; then
		system_ffmpeg=$(command -v ffmpeg)
		version=$(jellyfin_ffmpeg_binary_version "$system_ffmpeg" || true)
		if jellyfin_ffmpeg_version_usable "$version"; then
			log_info "System FFmpeg ${version} satisfies Jellyfin startup requirement"
			return 0
		fi
		log_warn "System FFmpeg is below Jellyfin FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION} requirement (${version:-unknown})"
	else
		log_warn "System ffmpeg not found; Jellyfin requires FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+"
	fi

	JELLYFIN_INSTALL_ENABLED=0
	log_err "Skipping Jellyfin: FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+ is required for Jellyfin startup."
	log_err "Install a user-local static FFmpeg at $home_ffmpeg, then rerun:"
	log_err "  bash install-media-stack.sh --jellyfin-ffmpeg=$home_ffmpeg"
	log_warn "Continuing with Radarr, Sonarr, Prowlarr, SABnzbd, and Cloudplow."
}

managed_install_path_reset() {
	local path="$1"

	if ! media_stack_home_path_is_safe ||
		[[ "$path" != "$HOME/.bin/"* || "$path" == "$HOME/.bin" || "$path" == "$HOME/.bin/" ]] ||
		[[ "$path" == *"/../"* || "$path" == *"/.." || "$path" == *"../"* ]]; then
		log_err "Refusing to refresh unsafe managed install path: $path"
		return 1
	fi
	if [[ $DRY_RUN -eq 1 ]]; then
		log_info "[dry-run] would refresh managed install path $path"
		return 0
	fi
	if [[ -L "$path" || -e "$path" ]]; then
		rm -rf "$path"
	fi
}

jellyfin_config_dir_reset() {
	local path="$HOME/.config/jellyfin"

	if ! media_stack_home_path_is_safe || [[ "$path" != "$HOME/.config/jellyfin" ]]; then
		log_err "Refusing to remove unsafe Jellyfin config path: $path"
		return 1
	fi

	rm -rf "$path"
}

servarr_config_xml_converge() {
	local app="$1" datadir="$2" port="$3" default_port="$4"

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
  <Port>${default_port}</Port>
  <BindAddress>127.0.0.1</BindAddress>
</Config>
EOF
		fi
		sed -i -E "s|<Port>[^<]*</Port>|<Port>${port}</Port>|g" "$datadir/config.xml"
		sed -i -E "s|<UrlBase>[^<]*</UrlBase>|<UrlBase>/public-${USERNAME}/${app}</UrlBase>|g" "$datadir/config.xml"
		sed -i -E "s|<BindAddress>[^<]*</BindAddress>|<BindAddress>127.0.0.1</BindAddress>|g" "$datadir/config.xml"
	else
		log_info "[dry-run] would configure ${app^^} (port=${port}, url_base=/public-${USERNAME}/${app})"
	fi
	echo "${app^^} configured"
	echo ""
}

servarr_resolve_download_url() {
	local app="$1"
	local glibc_ver=""

	case "$app" in
	radarr)
		if [[ -n "$OVR_RADARR_URL" ]]; then
			SERVARR_DOWNLOAD_URL="$OVR_RADARR_URL"
		elif [[ -n "$OVR_RADARR_VERSION" && "$SERVARR_ARCH" == "x64" ]]; then
			SERVARR_DOWNLOAD_URL="https://github.com/Radarr/Radarr/releases/download/${OVR_RADARR_VERSION}/Radarr.master.${OVR_RADARR_VERSION#v}.linux-core-x64.tar.gz"
		else
			glibc_ver=$(getconf GNU_LIBC_VERSION 2>/dev/null | awk '{print $2}')
			if dpkg --compare-versions "${glibc_ver:-0}" ge 2.33; then
				SERVARR_DOWNLOAD_URL="${RADARR_UPDATE_BASE}/${RADARR_BRANCH}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
			elif [[ "$SERVARR_ARCH" == "x64" ]]; then
				SERVARR_DOWNLOAD_URL="https://github.com/Radarr/Radarr/releases/download/v5.10.4.9218/Radarr.master.5.10.4.9218.linux-core-x64.tar.gz"
				log_warn "Detected GLIBC ${glibc_ver:-unknown} < 2.33 → pinning Radarr to v5.10.4.9218 (linux-core-x64)."
			else
				SERVARR_DOWNLOAD_URL="${RADARR_UPDATE_BASE}/${RADARR_BRANCH}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
			fi
		fi
		;;
	prowlarr)
		if [[ -n "$OVR_PROWLARR_URL" ]]; then
			SERVARR_DOWNLOAD_URL="$OVR_PROWLARR_URL"
		else
			SERVARR_DOWNLOAD_URL="${PROWLARR_UPDATE_BASE}/${PROWLARR_BRANCH}/updatefile?os=linux&runtime=netcore&arch=${SERVARR_ARCH}"
		fi
		;;
	sonarr)
		if [[ -n "$OVR_SONARR_URL" ]]; then
			SERVARR_DOWNLOAD_URL="$OVR_SONARR_URL"
		else
			SERVARR_DOWNLOAD_URL="${SONARR_DL_BASE}/${SONARR_BRANCH}/latest?version=${SONARR_MAJOR}&os=linux&arch=${SERVARR_ARCH}"
		fi
		;;
	*)
		log_err "Unknown Servarr app: $app"
		return 1
		;;
	esac
}

servarr_install_from_url() {
	local app="$1" install_name="$2" data_dir="$3" port="$4" default_port="$5" download_url="$6"
	local install_dir="$HOME/.bin"
	local archive="${install_name}.tar.gz"

	mkdir -p "$data_dir"
	managed_install_path_reset "$install_dir/$install_name"
	pkill -9 -f -u "$USERNAME" "$install_name" >/dev/null 2>&1 || true
	log_info "${install_name} URL: $download_url"

	echo "Downloading...${app^^}"
	cd "$install_dir"
	fetch_verified_archive "$download_url" "$archive" "$install_name"
	extract_tgz "$archive"
	servarr_config_xml_converge "$app" "$data_dir" "$port" "$default_port"
}

sabnzbd_misc_value_set() {
	local file="$1" key="$2" value
	value=$(sed_replacement_escape "$3")
	if grep -q "^${key} = " "$file"; then
		sed -i -E "s#^(${key} = ).*#\1${value}#" "$file"
		return
	fi
	sed -i "/^\[misc\]/a ${key} = ${value}" "$file"
}

lighttpd_custom_has_legacy_media_stack_rules() {
	local custom_file="$1"
	local app

	[[ -f "$custom_file" ]] || return 1
	grep -Fq '# Keep ARR base paths canonical so missing-slash requests' "$custom_file" || return 1
	for app in sabnzbd radarr prowlarr sonarr jellyfin; do
		grep -Fq '"^/'"$app"'(\$|/)"' "$custom_file" || return 1
	done
}

lighttpd_custom_strip_managed_media_stack_routes() {
	local custom_fragment="$1"
	local temp_fragment=""
	[[ -f "$custom_fragment" ]] || return 0

	temp_fragment="${custom_fragment}.pmss.$$"

	if ! awk '
    function line_is_managed_route_start(line) {
      return line ~ /^[[:space:]]*\$HTTP\["url"\] =~ "\^\/(sabnzbd|radarr|prowlarr|sonarr|jellyfin)[(]\\[$][|]\/[)]"/
    }

    function brace_delta(line, open_count, close_count) {
      open_count = gsub(/\{/, "{", line)
      close_count = gsub(/\}/, "}", line)
      return open_count - close_count
    }

    {
      if (skip_block == 1) {
        block_depth += brace_delta($0)
        if (block_depth <= 0) {
          skip_block = 0
          block_depth = 0
        }
        next
      }

      if (line_is_managed_route_start($0)) {
        skip_block = 1
        block_depth = brace_delta($0)
        if (block_depth <= 0) {
          skip_block = 0
          block_depth = 0
        }
        next
      }

      print
    }
  ' "$custom_fragment" >"$temp_fragment"; then
		rm -f "$temp_fragment"
		log_warn "Failed to sanitize overlapping PMSS lighttpd routes in ~/.lighttpd/custom.d/custom-migrated.conf"
		return 1
	fi

	if cmp -s "$custom_fragment" "$temp_fragment"; then
		rm -f "$temp_fragment"
		return 0
	fi

	if mv "$temp_fragment" "$custom_fragment"; then
		chmod 640 "$custom_fragment" 2>/dev/null || true
		log_info "Removed PMSS-managed media stack proxy routes from ~/.lighttpd/custom.d/custom-migrated.conf"
		return 0
	fi

	rm -f "$temp_fragment"
	log_warn "Failed to update sanitized PMSS lighttpd routes in ~/.lighttpd/custom.d/custom-migrated.conf"
	return 1
}

prepare_lighttpd_media_stack_paths() {
	local lighttpd_dir="$HOME/.lighttpd"
	local custom_file="$lighttpd_dir/custom"
	local custom_dir="$lighttpd_dir/custom.d"
	local managed_fragment="$custom_dir/media-stack.conf"
	local migrated_fragment="$custom_dir/custom-migrated.conf"
	local backup_stamp=""

	mkdir -p "$lighttpd_dir" "$custom_dir"

	if [[ -s "$custom_file" && ! -f "$managed_fragment" ]]; then
		backup_stamp=$(date +%Y%m%d)
		if ! cp "$custom_file" "$lighttpd_dir/backup.custom.${backup_stamp}" 2>/dev/null; then
			log_warn "Failed to backup existing lighttpd custom config"
		fi

		if lighttpd_custom_has_legacy_media_stack_rules "$custom_file"; then
			: >"$custom_file"
			log_info "Migrated legacy PMSS lighttpd rules to ~/.lighttpd/custom.d/media-stack.conf"
		elif [[ ! -e "$migrated_fragment" ]]; then
			mv "$custom_file" "$migrated_fragment"
			: >"$custom_file"
			lighttpd_custom_strip_managed_media_stack_routes "$migrated_fragment" || true
			chmod 640 "$migrated_fragment" 2>/dev/null || true
			log_info "Preserved ~/.lighttpd/custom at ~/.lighttpd/custom.d/custom-migrated.conf"
		else
			log_warn "Leaving ~/.lighttpd/custom unchanged; ~/.lighttpd/custom.d/custom-migrated.conf already exists"
		fi
	elif [[ ! -e "$custom_file" ]]; then
		: >"$custom_file"
	fi
}

lighttpd_media_stack_proxy_block_write() {
	local app="$1" port="$2"

	cat <<-EOF
		\$HTTP["url"] =~ "^/${app}(\\\$|/)" {
			  proxy.server = ( "" => ( (
			    "host" => "127.0.0.1",
			    "port" => ${port}
			  ) ) ),
			  proxy.forwarded = (
			    "for" => 1,
			    "host" => 1,
			    "by" => 1
			  ),
			  proxy.header = ( "map-urlpath" => (
			    "/${app}" => "/public-${USERNAME}/${app}"
			  ) )
			}
	EOF
}

lighttpd_media_stack_config_write() {
	local target="$1"
	local temp_target="${target}.pmss.$$"

	{
		cat <<EOF
# PMSS-managed media stack proxy fragment.
# Keep ARR base paths canonical so missing-slash requests
# redirect to proxy-managed app roots.
# App-specific Location and Set-Cookie Path rewriting belongs here via
# map-urlpath so nginx stays a minimal per-user front door.
url.redirect += (
  "^/radarr$" => "/public-${USERNAME}/radarr/",
  "^/sonarr$" => "/public-${USERNAME}/sonarr/",
  "^/prowlarr$" => "/public-${USERNAME}/prowlarr/"
)

EOF
		for proxy in "sabnzbd|$SABNZBD_PORT" "radarr|$RADARR_PORT" "prowlarr|$PROWLARR_PORT" "sonarr|$SONARR_PORT"; do
			IFS='|' read -r app port <<<"$proxy"
			[[ "$app" == "sabnzbd" ]] || echo ""
			lighttpd_media_stack_proxy_block_write "$app" "$port"
		done
		if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]]; then
			echo ""
			lighttpd_media_stack_proxy_block_write "jellyfin" "$JELLYFIN_PORT"
		fi
	} >"$temp_target" || {
		rm -f "$temp_target"
		return 1
	}

	if ! mv "$temp_target" "$target"; then
		rm -f "$temp_target"
		return 1
	fi
}

# Helper to append to .bashrc.custom only if marker not present
append_to_bashrc_custom_if_missing() {
	local content="$1"
	local marker="$2"
	local target="$HOME/.bashrc.custom"

	if [[ $DRY_RUN -eq 1 ]]; then
		log_info "[dry-run] would check and append to ~/.bashrc.custom (marker: $marker)"
		return 0
	fi

	if [[ ! -f "$target" ]]; then
		: >"$target" || {
			log_err "Failed to create $target"
			return 1
		}
	fi

	if ! grep -q "$marker" "$target" 2>/dev/null; then
		echo "$content" >>"$target"
	else
		log_warn "Entry with marker '$marker' already exists in .bashrc.custom, skipping"
	fi
}

media_stack_memory_value_is_finite() {
	local value="$1"

	[[ "$value" =~ ^[0-9]+$ ]] || return 1
	if ((value > 0 && value < MEDIA_STACK_UNLIMITED_MEMORY_BYTES)); then
		return 0
	fi
	return 1
}

media_stack_cgroup_limit_file_read() {
	local path="$1" value=""

	[[ -r "$path" ]] || return 0
	read -r value <"$path" || true
	if media_stack_memory_value_is_finite "$value"; then
		printf '%s\n' "$value"
	fi
}

media_stack_cgroup_limits_from_path() {
	local base="$1" rel="$2" file dir

	rel="/${rel#/}"
	while :; do
		dir="${base%/}${rel}"
		for file in "${@:3}"; do
			media_stack_cgroup_limit_file_read "$dir/$file"
		done
		[[ "$rel" == "/" ]] && break
		rel=$(dirname "$rel")
		[[ "$rel" == "." ]] && rel="/"
	done
}

media_stack_detected_memory_limit_bytes() {
	local cgroup_file="${PMSS_MEDIA_STACK_CGROUP_FILE:-/proc/self/cgroup}"
	local cgroup_root="${PMSS_MEDIA_STACK_CGROUP_ROOT:-/sys/fs/cgroup}"
	local hierarchy controllers rel candidate min_limit=""

	[[ -r "$cgroup_file" ]] || return 0
	while IFS=: read -r hierarchy controllers rel; do
		: "$hierarchy"
		[[ -n "$rel" ]] || continue
		if [[ "$controllers" == "" ]]; then
			while read -r candidate; do
				[[ -n "$candidate" ]] || continue
				if [[ -z "$min_limit" || "$candidate" -lt "$min_limit" ]]; then
					min_limit="$candidate"
				fi
			done < <(media_stack_cgroup_limits_from_path "$cgroup_root" "$rel" memory.high memory.max)
		elif [[ ",$controllers," == *",memory,"* ]]; then
			while read -r candidate; do
				[[ -n "$candidate" ]] || continue
				if [[ -z "$min_limit" || "$candidate" -lt "$min_limit" ]]; then
					min_limit="$candidate"
				fi
			done < <(media_stack_cgroup_limits_from_path "$cgroup_root/memory" "$rel" memory.soft_limit_in_bytes memory.limit_in_bytes)
		fi
	done <"$cgroup_file"

	if [[ -n "$min_limit" ]]; then
		printf '%s\n' "$min_limit"
	fi
	return 0
}

media_stack_memory_limit_mib_label() {
	local bytes="$1"
	printf '%s' $(((bytes + 1048575) / 1048576))
}

media_stack_memory_preflight_guard() {
	local limit_bytes="" limit_mib=""

	limit_bytes=$(media_stack_detected_memory_limit_bytes || true)
	if [[ -z "$limit_bytes" ]]; then
		log_warn "Could not detect account memory limit; continuing without memory pre-flight enforcement."
		return 0
	fi

	limit_mib=$(media_stack_memory_limit_mib_label "$limit_bytes")
	if ((limit_bytes >= MEDIA_STACK_MIN_MEMORY_BYTES)); then
		log_info "Detected account memory limit: ${limit_mib} MiB"
		return 0
	fi

	log_warn "Detected account memory limit: ${limit_mib} MiB"
	log_warn "This media stack needs roughly ${MEDIA_STACK_MIN_MEMORY_MIB} MiB or more to run without throttling the account."
	log_warn "On low-memory shared plans it can stall rtorrent and the web panel."

	if [[ $DRY_RUN -eq 1 ]]; then
		log_warn "Dry-run mode: reporting memory warning without blocking."
		return 0
	fi
	if [[ $FORCE_INSTALL -eq 1 ]]; then
		log_warn "--force supplied; continuing despite the memory warning."
		return 0
	fi
	if [[ -t 0 ]]; then
		printf "Continue anyway? (y/N): "
		read -r confirm
		[[ $confirm == [yY] ]] && return 0
	fi

	log_err "Aborting. Re-run with --force only if you accept the memory throttling risk."
	exit 1
}

media_stack_managed_paths() {
	printf '%s\n' "$HOME/.bin/cloudplow" "$HOME/.bin/sabnzbd" "$HOME/.bin/Radarr" "$HOME/.bin/Prowlarr" "$HOME/.bin/Sonarr" "$HOME/.bin/dotnet" "$HOME/.bin/jellyfin" \
		"$HOME/.config/cloudplow" "$HOME/.config/sabnzbd" "$HOME/.config/radarr" "$HOME/.config/prowlarr" "$HOME/.config/sonarr" "$HOME/.config/jellyfin" "$HOME/.lighttpd/custom.d/media-stack.conf"
}

media_stack_managed_path_allowed() {
	local candidate="$1" managed_path

	while IFS= read -r managed_path; do
		[[ "$candidate" == "$managed_path" ]] && return 0
	done < <(media_stack_managed_paths)
	return 1
}

media_stack_managed_path_remove() {
	local path="$1" home_real="" parent_real=""

	if ! media_stack_home_path_is_safe || ! media_stack_managed_path_allowed "$path"; then
		log_err "Refusing to remove unmanaged media stack path: $path"
		return 1
	fi
	if command -v realpath >/dev/null 2>&1; then
		home_real=$(realpath -m "$HOME")
		parent_real=$(realpath -m "$(dirname "$path")")
		if [[ "$parent_real" != "$home_real" && "$parent_real" != "$home_real/"* ]]; then
			log_err "Refusing to remove media stack path with unsafe parent: $path"
			return 1
		fi
	fi
	if [[ $DRY_RUN -eq 1 ]]; then
		log_info "[dry-run] would remove $path"
		return 0
	fi
	rm -rf -- "$path"
}

bashrc_custom_media_stack_blocks_strip() {
	local target="$HOME/.bashrc.custom" backup="" temp=""

	[[ -f "$target" ]] || {
		log_info "No ~/.bashrc.custom found; alias cleanup not needed."
		return 0
	}
	if [[ $DRY_RUN -eq 1 ]]; then
		log_info "[dry-run] would backup and strip PMSS media stack blocks from ~/.bashrc.custom"
		return 0
	fi

	backup="$target.pmss-media-stack.$(date +%Y%m%d%H%M%S).bak"
	temp="$target.pmss.$$"
	if ! cp "$target" "$backup"; then
		log_err "Failed to backup ~/.bashrc.custom"
		return 1
	fi

	if ! awk '
    function flush_pending(i) {
      for (i = 1; i <= pending_count; i++) print pending[i]
      pending_count = 0
    }
    function terminal(mode, line) {
      return (mode == "dotnet" && line == "export PATH") ||
        (mode == "jellyfin" && line ~ /^alias jellyfin=/) ||
        (mode == "media" && line ~ /^alias sabnzbd=/)
    }
    {
      if (skip != "") {
        pending[++pending_count] = $0
        if (terminal(skip, $0)) {
          skip = ""
          pending_count = 0
        }
        next
      }
      if ($0 == "# Added by PMSS media stack installer (.NET 8)") {
        skip = "dotnet"
        pending_count = 1
        pending[1] = $0
        next
      }
      if ($0 == "# PMSS Jellyfin alias (updated Nov 2025)") {
        skip = "jellyfin"
        pending_count = 1
        pending[1] = $0
        next
      }
      if ($0 == "# PMSS Media stack aliases (updated Nov 2025)") {
        skip = "media"
        pending_count = 1
        pending[1] = $0
        next
      }
      print
    }
    END {
      flush_pending()
    }
  ' "$target" >"$temp"; then
		rm -f "$temp"
		log_err "Failed to strip PMSS media stack blocks from ~/.bashrc.custom"
		return 1
	fi

	mv "$temp" "$target"
	chmod 0640 "$target" 2>/dev/null || true
	log_info "Backed up ~/.bashrc.custom to $(basename "$backup")"
}

media_stack_uninstall() {
	local app username managed_path

	username=$(whoami)
	log_step "Uninstalling PMSS media stack"
	if [[ $DRY_RUN -eq 0 ]]; then
		for app in jellyfin sabnzbd radarr prowlarr sonarr cloudplow jellyfin-smoke; do
			if command -v tmux >/dev/null 2>&1; then
				tmux kill-session -t "$app" 2>/dev/null || true
			fi
		done
		if command -v pkill >/dev/null 2>&1; then
			for app in jellyfin.dll SABnzbd.py Radarr.dll Prowlarr.dll Sonarr.dll cloudplow.py; do
				pkill -9 -f -u "$username" "$app" >/dev/null 2>&1 || true
			done
		fi
	else
		log_info "[dry-run] would stop media stack tmux sessions and app processes"
	fi

	while IFS= read -r managed_path; do
		media_stack_managed_path_remove "$managed_path"
	done < <(media_stack_managed_paths)
	bashrc_custom_media_stack_blocks_strip
	log_ok "PMSS media stack uninstall complete."
}

# Central configuration (keep multi-use constants here)
SONARR_BRANCH="${OVR_SONARR_BRANCH:-main}"
RADARR_BRANCH="${OVR_RADARR_BRANCH:-master}"
PROWLARR_BRANCH="${OVR_PROWLARR_BRANCH:-master}"
SONARR_DL_BASE="https://services.sonarr.tv/v1/download"
SONARR_MAJOR="${OVR_SONARR_VERSION:-4}"
RADARR_UPDATE_BASE="https://radarr.servarr.com/v1/update"
PROWLARR_UPDATE_BASE="https://prowlarr.servarr.com/v1/update"
# Cloudplow: pinned to last known-good commit (project unmaintained since 2023)
CLOUDPLOW_COMMIT="4a4e9cf7579ce4ba91761c12ad1bc88ae2707f8c"
DOTNET_ROOT_PATH="$HOME/.bin/dotnet"
JELLYFIN_CONFIG_DIR="$HOME/.config/jellyfin/config"
JELLYFIN_DATA_DIR="$HOME/.config/jellyfin/data"
JELLYFIN_LOG_DIR="$HOME/.config/jellyfin/log"
JELLYFIN_MIN_FFMPEG_VERSION="4.4"
MEDIA_STACK_BASE_SESSIONS=(sonarr radarr prowlarr sabnzbd cloudplow)
MEDIA_STACK_STOP_SESSIONS=(sabnzbd radarr prowlarr sonarr cloudplow)
# Determine public IP from default route (no external HTTP request needed)
PUBLIC_IP=$(ip -4 route get 8.8.8.8 2>/dev/null | grep -oP 'src \K\S+' || echo "unavailable")

if [[ $UNINSTALL -eq 1 ]]; then
	media_stack_uninstall
	exit 0
fi

# Check required dependencies early
log_step "Checking dependencies..."
MISSING_CMDS=()

for cmd in ss tmux git python3 tar dpkg getconf; do
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
media_stack_memory_preflight_guard

# Fetch latest checksums for download verification
[[ $DRY_RUN -eq 0 ]] && fetch_checksums_file

# CPU feature detection for compatibility warnings
if ! grep -q ' avx ' /proc/cpuinfo 2>/dev/null; then
	log_warn "CPU lacks AVX instructions — some native libraries may not work"
	log_warn "Jellyfin image processing (SkiaSharp) may crash on very old CPUs"
	log_warn "If any app crashes with 'Illegal instruction', your CPU is too old for that component"
	echo ""
fi

# Detect Architecture
ARCH=$(dpkg --print-architecture)
case "$ARCH" in
"amd64") IFS='|' read -r JF_ARCH DOTNET_ARCH SERVARR_ARCH <<<"amd64|x64|x64" ;;
"arm64") IFS='|' read -r JF_ARCH DOTNET_ARCH SERVARR_ARCH <<<"arm64|arm64|arm64" ;;
"armhf") IFS='|' read -r JF_ARCH DOTNET_ARCH SERVARR_ARCH <<<"armhf|arm|arm" ;;
*)
	log_err "Architecture '$ARCH' not supported."
	exit 1
	;;
esac

jellyfin_ffmpeg_configure_fallback

# Preserve unrelated ~/.bin contents on reruns; only managed media-stack paths are refreshed.
if [ -d "$HOME/.bin" ] && [ "$(ls -A "$HOME/.bin" 2>/dev/null)" ]; then
	log_info "Keeping existing ~/.bin contents outside PMSS-managed app paths."
	log_info "Managed media-stack binaries will be refreshed in place."
fi

# Safety check for existing Jellyfin config/data (can cause DB migration hang on rerun).
if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]] && [ -d "$HOME/.config/jellyfin" ] && [ "$(ls -A "$HOME/.config/jellyfin" 2>/dev/null)" ]; then
	if [[ $DRY_RUN -eq 1 ]]; then
		log_warn "$HOME/.config/jellyfin exists and would be removed (dry-run); Jellyfin users, metadata, and watch state would be lost."
	else
		printf "WARNING: %s exists and will be removed. Jellyfin users, metadata, and watch state will be lost. Continue? (y/N): " "$HOME/.config/jellyfin"
		read -r confirm
		[[ $confirm == [yY] ]] || exit 1
		jellyfin_config_dir_reset
	fi
elif [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]] && [ -d "$HOME/.config/jellyfin" ]; then
	[[ $DRY_RUN -eq 1 ]] || jellyfin_config_dir_reset
elif [[ "$JELLYFIN_INSTALL_ENABLED" -eq 0 ]] && [ -d "$HOME/.config/jellyfin" ]; then
	log_warn "Leaving existing Jellyfin config untouched because this run will skip Jellyfin."
fi

if [[ $DRY_RUN -eq 0 ]]; then
	config_dirs=("$HOME"/.config/{radarr,sonarr,prowlarr,sabnzbd,cloudplow})
	[[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]] && config_dirs+=("$HOME/.config/jellyfin")
	mkdir -p "${config_dirs[@]}"
	chmod 700 "${config_dirs[@]}"
	mkdir -p "$HOME/.bin"
else
	config_dir_label="radarr,sonarr,prowlarr,sabnzbd,cloudplow"
	[[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]] && config_dir_label="radarr,sonarr,prowlarr,jellyfin,sabnzbd,cloudplow"
	log_info "[dry-run] would create ~/.config/{${config_dir_label}}"
	log_info "[dry-run] would create ~/.bin"
fi

log_step "Resolving latest versions..."

# SABnzbd (GitHub API)
if [[ -n "$OVR_SAB_URL" ]]; then
	SABNZBD_URL="$OVR_SAB_URL"
	SABNZBD_VERSION="override"
else
	if ! SABNZBD_RELEASE_JSON=$(fetch_text "https://api.github.com/repos/sabnzbd/sabnzbd/releases/latest"); then
		SABNZBD_RELEASE_JSON=""
	fi
	SABNZBD_VERSION=$(printf '%s\n' "$SABNZBD_RELEASE_JSON" | grep -E 'tag_name' | cut -d '"' -f 4 || true)
	SABNZBD_URL=$(printf '%s\n' "$SABNZBD_RELEASE_JSON" | grep -E 'browser_download_url' | grep -- '-src' | cut -d '"' -f 4 || true)
	if [[ -z "$SABNZBD_URL" ]]; then
		log_err "Could not resolve SABnzbd release metadata from GitHub"
		exit 1
	fi
fi

# Jellyfin (Repo Scraping)
# Fetches from repo.jellyfin.org structure: files/server/linux/latest-stable/<arch>/
JF_REPO_BASE="https://repo.jellyfin.org/files/server/linux/latest-stable/${JF_ARCH}/"
# Find filename like jellyfin_10.X.Y-amd64.tar.gz
if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 0 ]]; then
	JELLYFIN_URL=""
	JF_FILENAME="skipped"
elif [[ -n "$OVR_JELLYFIN_URL" ]]; then
	JELLYFIN_URL="$OVR_JELLYFIN_URL"
	JF_FILENAME="override"
else
	if ! JF_REPO_INDEX=$(fetch_text "$JF_REPO_BASE"); then
		JF_REPO_INDEX=""
	fi
	JF_FILENAME=$(printf '%s\n' "$JF_REPO_INDEX" | grep -oE "jellyfin_[0-9]+\\.[0-9]+\\.[0-9]+-${JF_ARCH}\\.tar\\.gz" | head -n 1)
	if [[ -z "$JF_FILENAME" ]]; then
		log_err "Could not resolve latest Jellyfin tarball from $JF_REPO_BASE"
		exit 1
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
	all_ok=true
	for url in "${SABNZBD_URL:-}" "${JELLYFIN_URL:-}" "${ASPDOTNET_URL:-}"; do
		[[ -n "$url" ]] || continue
		if check_url "$url"; then
			log_ok "URL reachable: $url"
			continue
		fi
		log_err "URL not reachable: $url"
		all_ok=false
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

# Preserve existing ports on reruns so proxy configs stay stable.
existing_port_from_ini() {
	local file="$1" key="$2"
	[[ -f "$file" ]] || return 0
	awk -F'=' -v k="$key" '
			$1 ~ "^[[:space:]]*" k "[[:space:]]*$" {
				gsub(/[[:space:]]/, "", $2);
				print $2;
				exit
			}
		' "$file"
}

existing_port_from_xml_tag() {
	local file="$1" tag="$2"
	[[ -f "$file" ]] || return 0
	sed -n -E "s|.*<${tag}>([0-9]+)</${tag}>.*|\\1|p" "$file" | head -n 1
}

media_stack_port_is_valid() {
	[[ "$1" =~ ^[0-9]{1,5}$ ]] && ((10#$1 >= 1 && 10#$1 <= 65535))
}

pick_existing_or_random_port() {
	local existing="$1"
	if [[ -n "$existing" ]]; then
		if media_stack_port_is_valid "$existing"; then
			printf '%s' "$existing"
			return
		fi
		log_warn "Ignoring invalid existing port '${existing}' and selecting a fresh local port"
	fi
	random_open_port
}

jellyfin_system_xml_tag_set() {
	local file="$1" tag="$2" value=""
	value=$(sed_replacement_escape "$(xml_escape "$3")")
	grep -q "<${tag}>" "$file" &&
		sed -i -E "s|<${tag}>[^<]*</${tag}>|<${tag}>${value}</${tag}>|g" "$file" && return
	sed -i -E "s|</ServerConfiguration>|  <${tag}>${value}</${tag}>\n</ServerConfiguration>|" "$file"
}

media_stack_sessions() {
	[[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]] && printf '%s\n' jellyfin
	printf '%s\n' "$@"
}

media_stack_sessions_label() {
	media_stack_sessions "${MEDIA_STACK_BASE_SESSIONS[@]}" | awk 'NR > 1 { printf ", " } { printf "%s", $0 }'
}

media_stack_app_log_path() {
	case "$1" in
	jellyfin) printf '%s' "$JELLYFIN_LOG_DIR/jellyfin.log" ;;
	sonarr | radarr | prowlarr | sabnzbd) printf '%s' "$HOME/.config/${1}/${1}.log" ;;
	esac
}

media_stack_log_has() {
	[[ -f "$1" ]] || return 1
	grep -qi "$2" "$1" 2>/dev/null
}

media_stack_start_tmux_app() {
	local app="$1" required_file="$2" session_command="$3" missing_message="$4"
	[[ -f "$required_file" ]] || {
		log_err "$missing_message"
		return 0
	}
	tmux new-session -d -s "$app" "$session_command" || log_warn "Failed to create ${app} tmux session"
}

media_stack_start_servarr_app() {
	local app="$1" install_name="$2" dll="$3" extra_args="$4"
	local run_args="${dll}${extra_args:+ $extra_args} --data=\"$HOME/.config/${app}\""
	media_stack_start_tmux_app "$app" "$HOME/.bin/${install_name}/${dll}" \
		"export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; cd \"$HOME/.bin/${install_name}\" && \"$DOTNET_ROOT_PATH/dotnet\" ${run_args} 2>&1 | tee -a \"$HOME/.config/${app}/${app}.log\"" \
		"${install_name} DLL not found at $HOME/.bin/${install_name}/${dll}"
}

media_stack_start_apps() {
	# Point service commands to the dotnet binary, not the runtime directory.
	if [[ ! -f "$DOTNET_ROOT_PATH/dotnet" ]]; then
		log_err "dotnet binary not found at $DOTNET_ROOT_PATH/dotnet"
		exit 1
	fi

	mkdir -p "$HOME/.config/sonarr" "$HOME/.config/radarr" "$HOME/.config/prowlarr" "$HOME/.config/sabnzbd"
	if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]]; then
		mkdir -p "$JELLYFIN_DATA_DIR/log"
		media_stack_start_tmux_app "jellyfin" "$HOME/.bin/jellyfin/jellyfin.dll" \
			"export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; export JELLYFIN_CONFIG_DIR=\"$JELLYFIN_CONFIG_DIR\"; export JELLYFIN_DATA_DIR=\"$JELLYFIN_DATA_DIR\"; export JELLYFIN_LOG_DIR=\"$JELLYFIN_LOG_DIR\"; export ASPNETCORE_URLS=\"http://127.0.0.1:${JELLYFIN_PORT}\"; cd \"$HOME/.bin/jellyfin\" && ionice -c 3 nice -n 19 \"$DOTNET_ROOT_PATH/dotnet\" jellyfin.dll 2>&1 | tee -a \"$JELLYFIN_LOG_DIR/jellyfin.log\"" \
			"Jellyfin DLL not found at $HOME/.bin/jellyfin/jellyfin.dll"
	else
		log_warn "Skipping Jellyfin start; FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+ is not configured"
	fi

	media_stack_start_servarr_app "sonarr" "Sonarr" "Sonarr.dll" ""
	media_stack_start_servarr_app "radarr" "Radarr" "Radarr.dll" "--nobrowser"
	media_stack_start_servarr_app "prowlarr" "Prowlarr" "Prowlarr.dll" "--nobrowser"
	media_stack_start_tmux_app "sabnzbd" "$HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py" \
		"source $HOME/.bin/sabnzbd/bin/activate && cd \"$HOME/.bin/sabnzbd/sabnzbd\" && nice -n 19 python3 SABnzbd.py -b 0 -f $HOME/.config/sabnzbd/sabnzbd.ini 2>&1 | tee -a \"$HOME/.config/sabnzbd/sabnzbd.log\"" \
		"SABnzbd not found at $HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py"
	media_stack_start_tmux_app "cloudplow" "$HOME/.bin/cloudplow/cloudplow/cloudplow.py" \
		"source $HOME/.bin/cloudplow/bin/activate && python3 $HOME/.bin/cloudplow/cloudplow/cloudplow.py run --config=$HOME/.config/cloudplow/config.json --loglevel=DEBUG --cachefile=$HOME/.config/cloudplow/cache.db --logfile=$HOME/.config/cloudplow/cloudplow.log" \
		"Cloudplow not found at $HOME/.bin/cloudplow/cloudplow/cloudplow.py"
}

media_stack_verify_sessions() {
	local app app_log

	sleep 3
	log_step "Verifying started applications..."
	while IFS= read -r app; do
		if tmux has-session -t "$app" 2>/dev/null; then
			log_ok "$app session is running"
			continue
		fi

		log_warn "$app session exited immediately"
		app_log=$(media_stack_app_log_path "$app")
		if [[ -n "$app_log" ]] && media_stack_log_has "$app_log" "illegal instruction\|SIGILL\|signal 4"; then
			log_err "$app crashed: Illegal Instruction — CPU lacks required instruction sets (SSE4.2/AVX)"
		fi
		log_info "To diagnose: tmux new-session -s ${app}-debug 'cd ~/.bin/${app}* && ...'"
	done < <(media_stack_sessions "${MEDIA_STACK_BASE_SESSIONS[@]}")
}

SABNZBD_PORT=$(pick_existing_or_random_port "$(existing_port_from_ini "$HOME/.config/sabnzbd/sabnzbd.ini" "port")")
RADARR_PORT=$(pick_existing_or_random_port "$(existing_port_from_xml_tag "$HOME/.config/radarr/config.xml" "Port")")
PROWLARR_PORT=$(pick_existing_or_random_port "$(existing_port_from_xml_tag "$HOME/.config/prowlarr/config.xml" "Port")")
SONARR_PORT=$(pick_existing_or_random_port "$(existing_port_from_xml_tag "$HOME/.config/sonarr/config.xml" "Port")")
JELLYFIN_PORT="$(existing_port_from_xml_tag "$JELLYFIN_CONFIG_DIR/network.xml" "InternalHttpPort")"
JELLYFIN_PORT="${JELLYFIN_PORT:-$(existing_port_from_xml_tag "$JELLYFIN_CONFIG_DIR/network.xml" "PublicHttpPort")}"
JELLYFIN_PORT="$(pick_existing_or_random_port "$JELLYFIN_PORT")"
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
	while IFS= read -r app; do
		tmux kill-session -t "${app}" 2>/dev/null || true
	done < <(media_stack_sessions "${MEDIA_STACK_STOP_SESSIONS[@]}")
fi

# Install Cloudplow (unchanged, repo active)
app="cloudplow"
log_step "Installing ${app^^}..."
installdir="$HOME/.bin/cloudplow"
datadir="$HOME/.config/cloudplow"
mkdir -p "$datadir"
if [[ $DRY_RUN -eq 0 ]]; then
	managed_install_path_reset "$installdir"
	python3 -m venv "$installdir"
	cd "$installdir"
	git clone https://github.com/l3uddz/cloudplow.git >/dev/null 2>&1
	cd "${installdir}/cloudplow" && git checkout "$CLOUDPLOW_COMMIT" >/dev/null 2>&1
	cd "$installdir"
	python_venv_install_requirements "$installdir" "${installdir}/cloudplow/requirements.txt"
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
	managed_install_path_reset "$installdir"
	python3 -m venv "$installdir"
	cd "$installdir"
	echo "Downloading...${app^^} (${SABNZBD_VERSION})"
	if [[ -z "${SABNZBD_URL:-}" ]]; then
		log_err "SABnzbd URL not resolved"
		exit 1
	fi
	fetch_verified_archive "$SABNZBD_URL" "${app}.tar.gz" "SABnzbd"
	mkdir -p "${app}"
	extract_tgz "${app}.tar.gz" "${app}" 1
	python_venv_install_requirements "$installdir" "${installdir}/${app}/requirements.txt"
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
host = 127.0.0.1
url_base = /sabnzbd
host_whitelist = ${HOSTNAME}
inet_exposure = 4
EOF
	fi
	sabnzbd_misc_value_set "$datadir/${app}.ini" url_base "/public-${USERNAME}/${app}"
	sabnzbd_misc_value_set "$datadir/${app}.ini" port "$SABNZBD_PORT"
	sabnzbd_misc_value_set "$datadir/${app}.ini" host "127.0.0.1"
	sabnzbd_misc_value_set "$datadir/${app}.ini" host_whitelist "$HOSTNAME"
	# The public lighttpd proxy forwards real client IPs; allow the setup wizard so users can set SABnzbd auth.
	sabnzbd_misc_value_set "$datadir/${app}.ini" inet_exposure "4"
else
	log_info "[dry-run] would configure ${app^^} (port=${SABNZBD_PORT}, url_base=/public-${USERNAME}/${app})"
fi
echo "${app^^} configured"
echo ""

for servarr_spec in \
	"radarr|Radarr|$HOME/.config/radarr|$RADARR_PORT|7878|echo" \
	"prowlarr|Prowlarr|$HOME/.config/prowlarr|$PROWLARR_PORT|9696|log_step" \
	"sonarr|Sonarr|$HOME/.config/sonarr|$SONARR_PORT|8989|log_step"; do
	IFS='|' read -r app install_name datadir port default_port step_logger <<<"$servarr_spec"
	"$step_logger" "Installing ${app^^}..."
	servarr_resolve_download_url "$app"
	servarr_install_from_url "$app" "$install_name" "$datadir" "$port" "$default_port" "$SERVARR_DOWNLOAD_URL"
done

# Install ASP.NET Core (.NET 8)
app="aspnetcore"
log_step "Installing ${app^^}..."
echo "Downloading...${app^^}"
installdir="$HOME/.bin/dotnet"
managed_install_path_reset "$installdir"
mkdir -p "$installdir"
cd "$installdir"
fetch_verified_archive "$ASPDOTNET_URL" "aspnetcore.tar.gz" "ASP.NET runtime"
if [[ $DRY_RUN -eq 0 && ! -f "aspnetcore.tar.gz" ]]; then
	log_err "Failed to find downloaded ASP.NET archive"
	exit 1
fi
extract_tgz "aspnetcore.tar.gz" "$installdir"
if [[ $DRY_RUN -eq 0 ]]; then
	echo "${app^^} Installed"
fi
# shellcheck disable=SC2016
append_to_bashrc_custom_if_missing '# Added by PMSS media stack installer (.NET 8)
export DOTNET_ROOT=$HOME/.bin/dotnet
case ":$PATH:" in
  *":$DOTNET_ROOT:"*) ;;
  *) PATH="$PATH:$DOTNET_ROOT" ;;
esac
case ":$PATH:" in
  *":$HOME/.bin:"*) ;;
  *) PATH="$PATH:$HOME/.bin" ;;
esac
export PATH' 'PMSS media stack installer'
chmod 0640 "$HOME/.bashrc.custom" 2>/dev/null || true
echo ""

if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]]; then
	# Install Jellyfin (URL, extract, no chmod)
	app="jellyfin"
	log_step "Installing ${app^^}..."
	installdir="$HOME/.bin"
	datadir="$JELLYFIN_DATA_DIR"
	configdir="$JELLYFIN_CONFIG_DIR"
	logdir="$JELLYFIN_LOG_DIR"
	mkdir -p "$datadir" "$logdir" "$configdir"
	managed_install_path_reset "$installdir/${app}"
	cd "$installdir"
	echo "Downloading...${app^^}"
	fetch_verified_archive "$JELLYFIN_URL" "${app}.tar.gz" "Jellyfin" "${JF_FILENAME:-override}"
	mkdir -p "${app}"
	extract_tgz "${app}.tar.gz" "${app}" 1
	[[ $DRY_RUN -eq 1 ]] || echo "${app^^} Installed"

	# Configure Jellyfin BEFORE first start (security: prevents 0.0.0.0 binding)
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
  <AutoDiscovery>false</AutoDiscovery>
  <PublicHttpsPort>8920</PublicHttpsPort>
  <InternalHttpPort>$JELLYFIN_PORT</InternalHttpPort>
  <HttpsPortNumber>8920</HttpsPortNumber>
  <EnableHttps>false</EnableHttps>
  <CertificatePath />
  <CertificatePassword />
  <EnableRemoteAccess>false</EnableRemoteAccess>
  <BaseUrl />
  <LocalNetworkSubnets />
  <LocalNetworkAddresses>
    <string>127.0.0.1</string>
  </LocalNetworkAddresses>
  <RequireHttps>false</RequireHttps>
  <RemoteIPFilter />
  <IsRemoteIPFilterBlacklist>false</IsRemoteIPFilterBlacklist>
  <KnownProxies />
</NetworkConfiguration>
EOF
		fi
		sed -i -E "s|(<PublicHttpPort>)[^<]*(</PublicHttpPort>)|\1$JELLYFIN_PORT\2|g" "$configdir/network.xml"
		sed -i -E "s|(<InternalHttpPort>)[^<]*(</InternalHttpPort>)|\1$JELLYFIN_PORT\2|g" "$configdir/network.xml"
		sed -i -E "s|(<EnableRemoteAccess>)[^<]*(</EnableRemoteAccess>)|\1false\2|g" "$configdir/network.xml"
		sed -i -E "s|<BaseUrl />|<BaseUrl></BaseUrl>|g" "$configdir/network.xml"
		sed -i -E "s|(<BaseUrl>)[^<]*(</BaseUrl>)|\1/public-${USERNAME}/${app}\2|g" "$configdir/network.xml"
		syscfg="$configdir/system.xml"
		if [ ! -f "$syscfg" ]; then
			cat >"$syscfg" <<SYSXML
<?xml version="1.0" encoding="utf-8"?>
<ServerConfiguration xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
SYSXML
			echo "</ServerConfiguration>" >>"$syscfg"
		fi
		jellyfin_system_xml_tag_set "$syscfg" "BaseUrl" "/public-${USERNAME}/${app}"
		if [[ -n "$OVR_JELLYFIN_FFMPEG" ]]; then
			jellyfin_system_xml_tag_set "$syscfg" "FFmpegPath" "$OVR_JELLYFIN_FFMPEG"
		fi
	else
		log_info "[dry-run] would configure ${app^^} (port=${JELLYFIN_PORT}, url_base=/public-${USERNAME}/${app})"
		if [[ -n "$OVR_JELLYFIN_FFMPEG" ]]; then
			log_info "[dry-run] would set Jellyfin FFmpegPath to '$OVR_JELLYFIN_FFMPEG' in $configdir/system.xml"
		fi
	fi
	echo "${app^^} configured"

	if [[ $DRY_RUN -eq 0 ]] && [[ -f "$HOME/.bin/jellyfin/jellyfin.dll" ]]; then
		jellyfin_smoke_log="$JELLYFIN_LOG_DIR/jellyfin-smoke.log"
		log_info "Running Jellyfin smoke test..."
		tmux new-session -d -s "jellyfin-smoke" \
			"export DOTNET_ROOT=\"$DOTNET_ROOT_PATH\"; export JELLYFIN_CONFIG_DIR=\"$JELLYFIN_CONFIG_DIR\"; export JELLYFIN_DATA_DIR=\"$JELLYFIN_DATA_DIR\"; export JELLYFIN_LOG_DIR=\"$JELLYFIN_LOG_DIR\"; export ASPNETCORE_URLS=\"http://127.0.0.1:${JELLYFIN_PORT}\"; cd \"$HOME/.bin/jellyfin\" && ionice -c 3 nice -n 19 \"$DOTNET_ROOT_PATH/dotnet\" jellyfin.dll 2>&1 | tee -a \"$jellyfin_smoke_log\"" 2>/dev/null || true
		sleep 4
		if tmux has-session -t "jellyfin-smoke" 2>/dev/null; then
			log_ok "Jellyfin smoke test passed (DLL loads, native libs OK)"
			tmux kill-session -t "jellyfin-smoke" 2>/dev/null || true
		else
			log_warn "Jellyfin exited during smoke test — may have crashed"
			if media_stack_log_has "$jellyfin_smoke_log" "FfmpegException\|Failed.*ffmpeg"; then
				log_err "Jellyfin failed FFmpeg validation — pass --jellyfin-ffmpeg=$HOME/.bin/ffmpeg after installing FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+"
			fi
			if media_stack_log_has "$jellyfin_smoke_log" "illegal instruction\|SIGILL\|signal 4"; then
				log_err "Jellyfin crashed with Illegal Instruction — CPU lacks required instruction sets"
				log_err "SkiaSharp or other native libraries need SSE4.2/AVX which this CPU does not have"
				log_warn "Jellyfin will be installed but may crash during image processing"
			fi
		fi
		rm -f "$jellyfin_smoke_log" 2>/dev/null || true
	fi
else
	log_warn "Skipping Jellyfin install; rerun with --jellyfin-ffmpeg=$HOME/.bin/ffmpeg after installing FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+"
fi
echo ""

# Aliases (Sonarr fix, PATH added above)
# shellcheck disable=SC2016
if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]]; then
	append_to_bashrc_custom_if_missing '# PMSS Jellyfin alias (updated Nov 2025)
alias jellyfin='\''tmux new-session -d -s "jellyfin" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; export JELLYFIN_CONFIG_DIR=\"$HOME/.config/jellyfin/config\"; export JELLYFIN_DATA_DIR=\"$HOME/.config/jellyfin/data\"; export JELLYFIN_LOG_DIR=\"$HOME/.config/jellyfin/log\"; ionice -c 3 nice -n 19 \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/jellyfin/jellyfin.dll\""'\''
' 'alias jellyfin='
fi

# shellcheck disable=SC2016
append_to_bashrc_custom_if_missing '# PMSS Media stack aliases (updated Nov 2025)
alias sonarr='\''tmux new-session -d -s "sonarr" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/Sonarr/Sonarr.dll\" --data=\"$HOME/.config/sonarr\""'\''
alias radarr='\''tmux new-session -d -s "radarr" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/Radarr/Radarr.dll\" --nobrowser --data=\"$HOME/.config/radarr\""'\''
alias prowlarr='\''tmux new-session -d -s "prowlarr" "export DOTNET_ROOT=\"$HOME/.bin/dotnet\"; \"$HOME/.bin/dotnet/dotnet\" \"$HOME/.bin/Prowlarr/Prowlarr.dll\" --nobrowser --data=\"$HOME/.config/prowlarr\""'\''

alias cloudplow='\''tmux new-session -d -s "cloudplow" "source $HOME/.bin/cloudplow/bin/activate && python3 $HOME/.bin/cloudplow/cloudplow/cloudplow.py run --config=$HOME/.config/cloudplow/config.json --loglevel=DEBUG --cachefile=$HOME/.config/cloudplow/cache.db --logfile=$HOME/.config/cloudplow/cloudplow.log"'\''
alias sabnzbd='\''tmux new-session -d -s "sabnzbd" "source $HOME/.bin/sabnzbd/bin/activate && nice -n 19 python3 $HOME/.bin/sabnzbd/sabnzbd/SABnzbd.py -b 0 -f $HOME/.config/sabnzbd/sabnzbd.ini"'\''' 'PMSS Media stack aliases'

# Lighttpd config (use $HOSTNAME)
if [[ $DRY_RUN -eq 0 ]]; then
	prepare_lighttpd_media_stack_paths
	lighttpd_media_stack_config_write "$HOME/.lighttpd/custom.d/media-stack.conf"
	chmod 640 "$HOME/.lighttpd/custom.d/media-stack.conf" 2>/dev/null || true
else
	log_info "[dry-run] would create lighttpd config at ~/.lighttpd/custom.d/media-stack.conf"
fi
set +u
# shellcheck disable=SC1091
source "$HOME/.bashrc" || true
set -u

echo ""
log_step "Starting applications"
if [[ $DRY_RUN -eq 0 ]]; then
	media_stack_start_apps
	media_stack_verify_sessions
else
	log_info "[dry-run] would start tmux sessions: $(media_stack_sessions_label)"
fi

echo ""
echo "Connect to running application use command 'tmux attach -t <app-name>'"
echo "e.g to attach to radarr 'tmux attach -t radarr'"
echo "Exit tmux session by pressing 'CTRL+b' then 'd'"
echo ""
echo "To start application manually use appname as command"
echo "e.g for SONARR use 'sonarr'"
echo ""
for app in radarr sonarr prowlarr sabnzbd; do
	echo "${app^^}-URL = https://${HOSTNAME}/public-${USERNAME}/${app}/"
done
echo "SABNZBD-WIZARD-URL = https://${HOSTNAME}/public-${USERNAME}/sabnzbd/wizard/"
if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]]; then
	echo "JELLYFIN-URL = https://${HOSTNAME}/public-${USERNAME}/jellyfin/web/index.html"
	echo "JELLYFIN-LOCAL-URL = http://127.0.0.1:${JELLYFIN_PORT}"
else
	echo "JELLYFIN-SKIPPED = install FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+ under ~/.bin and rerun with --jellyfin-ffmpeg=$HOME/.bin/ffmpeg"
fi
echo "PUBLIC-IP (do not expose ports): ${PUBLIC_IP}"

echo ""
summary_jellyfin_port=""
summary_jellyfin_config=""
[[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]] && summary_jellyfin_port=", Jellyfin=${JELLYFIN_PORT}" && summary_jellyfin_config=" | Jellyfin=$HOME/.config/jellyfin"
echo "Port summary: SABnzbd=${SABNZBD_PORT}, Radarr=${RADARR_PORT}, Sonarr=${SONARR_PORT}, Prowlarr=${PROWLARR_PORT}${summary_jellyfin_port}"
echo "Config dirs: SABnzbd=$HOME/.config/sabnzbd | Radarr=$HOME/.config/radarr | Sonarr=$HOME/.config/sonarr | Prowlarr=$HOME/.config/prowlarr${summary_jellyfin_config} | Cloudplow=$HOME/.config/cloudplow"
echo "Tmux sessions running: $(media_stack_sessions_label)"

echo ""
echo "To kill all applications use 'tmux kill-server'"

echo ""
log_step "Restarting lighttpd"
if [[ $DRY_RUN -eq 0 ]]; then
	pkill -9 -u "$USERNAME" lighttpd >/dev/null 2>&1 || true
	pkill -9 -u "$USERNAME" php-cgi >/dev/null 2>&1 || true
	if command -v lighttpd >/dev/null 2>&1 && [[ -f "$HOME/.lighttpd.conf" ]]; then
		if ! lighttpd -f "$HOME/.lighttpd.conf" >/dev/null 2>&1; then
			log_warn "Direct lighttpd restart failed; watchdog may retry."
		fi
	else
		echo "It may take 1-2 minutes to restart lighttpd"
	fi
else
	log_info "[dry-run] would restart lighttpd/php-cgi"
fi

echo ""
echo "================== SECURITY WARNING =================="
if [[ "$JELLYFIN_INSTALL_ENABLED" -eq 1 ]]; then
	echo "Jellyfin first-run requires creating an admin account."
	echo "Set a STRONG admin password immediately after opening:"
	echo "  https://${HOSTNAME}/public-${USERNAME}/jellyfin/web/index.html"
else
	echo "Jellyfin was skipped because FFmpeg ${JELLYFIN_MIN_FFMPEG_VERSION}+ is not configured."
	echo "Install user-local FFmpeg and rerun with --jellyfin-ffmpeg=$HOME/.bin/ffmpeg."
fi
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
