#!/bin/bash
# PMSS bootstrap installer.
#
# - Installs minimal prerequisites on a fresh Debian host (PHP CLI, unzip, vim, git, curl, wget, ca-certificates, rsync).
# - Downloads or clones the requested PMSS snapshot and hands off to
#   update.php with any additional arguments provided.
# - Performs initial hostname/quota prompts to keep the legacy workflow intact.
#
# This script has been the entry point for well over a decade—treat it gently.
# Only adjust behaviour when absolutely necessary, and coordinate changes with
# the platform team.
#
# Author: Aleksi Ursin <aleksi@magnacapax.fi>
# Copyright 2010-2025 Magna Capax Finland Oy

DEFAULT_REPOSITORY="https://github.com/MagnaCapax/PMSS"
date=
type=
url=
repository=
branch=
LOG_FILE="/var/log/pmss-install.log"
DRY_RUN=false
FORCE_NONINTERACTIVE=false
SKIP_UPGRADE=false
RUN_UPDATE=true
SCRIPTS_ONLY=false

# Simple colour-aware logging helpers.
if [ -t 1 ]; then
	COLOR_BLUE="$(tput setaf 4)"
	COLOR_GREEN="$(tput setaf 2)"
	COLOR_YELLOW="$(tput setaf 3)"
	COLOR_RED="$(tput setaf 1)"
	COLOR_RESET="$(tput sgr0)"
else
	COLOR_BLUE=""
	COLOR_GREEN=""
	COLOR_YELLOW=""
	COLOR_RED=""
	COLOR_RESET=""
fi

log_file() {
	if [ -z "$LOG_FILE" ]; then
		return
	fi
	mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
	printf '%s\n' "$*" >>"$LOG_FILE"
}

log_step() { local msg="==> $*"; echo -e "${COLOR_BLUE}${msg}${COLOR_RESET}"; log_file "$msg"; }
log_info() { local msg="--> $*"; echo -e "${COLOR_GREEN}${msg}${COLOR_RESET}"; log_file "$msg"; }
log_warn() { local msg="WARN $*"; echo -e "${COLOR_YELLOW}${msg}${COLOR_RESET}"; log_file "$msg"; }
log_error() { local msg="ERR  $*"; echo -e "${COLOR_RED}${msg}${COLOR_RESET}"; log_file "$msg"; }
run_cmd() {
	if [ "$DRY_RUN" = true ]; then
		log_step "[DRY-RUN] Skipping: $*"
		return 0
	fi

	log_step "Running: $*"
	"$@"
	return $?
}

# Installer runtime flags, populated from CLI switches.
hostname_override=
skip_hostname_edit=false
quota_mountpoint=
skip_quota_edit=false
POSITIONAL=()

# Parse CLI options for non-interactive installs and behavioural tweaks.
while [[ $# -gt 0 ]]; do
	case "$1" in
	--hostname)
		hostname_override="$2"
		shift 2
		continue
		;;
	--hostname=*)
		hostname_override="${1#*=}"
		shift
		continue
		;;
	--skip-hostname)
		skip_hostname_edit=true
		shift
		continue
		;;
	--quota-mount)
		quota_mountpoint="$2"
		shift 2
		continue
		;;
	--quota-mount=*)
		quota_mountpoint="${1#*=}"
		shift
		continue
		;;
	--skip-quota)
		skip_quota_edit=true
		shift
		continue
		;;
	--skip-quota=*)
		skip_quota_edit=true
		shift
		continue
		;;
	--non-interactive)
		FORCE_NONINTERACTIVE=true
		shift
		continue
		;;
	--skip-upgrade)
		SKIP_UPGRADE=true
		shift
		continue
		;;
	--dry-run)
		DRY_RUN=true
		shift
		continue
		;;
	--skip-update)
		RUN_UPDATE=false
		shift
		continue
		;;
	--scripts-only)
		SCRIPTS_ONLY=true
		shift
		continue
		;;
	--help)
		log_info "Usage: bash install.sh [update-source] [options...]"
		log_info "  --hostname=<name>      set system hostname non-interactively"
		log_info "  --skip-hostname        skip hostname confirmation"
		log_info "  --quota-mount=<path>   add quota options to specified fstab mount"
		log_info "  --skip-quota           skip quota guidance section"
		log_info "  --non-interactive      skip hostname/quota prompts even on a TTY"
		log_info "  --skip-upgrade         skip initial apt full-upgrade"
		log_info "  --dry-run              parse and report plan without changing the system"
		log_info "  --skip-update          stop after staging files; do not run update.php"
		log_info "  --scripts-only         pass through to update.php to skip phase 2"
		exit 0
		;;
	--*)
		log_error "Unknown option: $1"
		exit 1
		;;
	*)
		POSITIONAL+=("$1")
		shift
		;;
	esac
done

set -- "${POSITIONAL[@]}"

if [ $# -gt 0 ]; then
	SOURCE_SPEC="$1"
	shift
else
	SOURCE_SPEC=""
fi
if [ -n "$SOURCE_SPEC" ]; then
	UPDATE_ARGS=("$SOURCE_SPEC" "$@")
else
	UPDATE_ARGS=("$@")
fi
if [ "$SCRIPTS_ONLY" = true ]; then
	UPDATE_ARGS+=("--scripts-only")
fi

# Auto-disable interactive editors when stdin is not a TTY (common for piped installs).
if [ "$FORCE_NONINTERACTIVE" = true ]; then
	log_info "Non-interactive mode requested; skipping hostname and quota editors"
	skip_hostname_edit=true
	skip_quota_edit=true
elif [ ! -t 0 ]; then
	log_info "Non-interactive stdin detected; skipping hostname and quota editors (use flags to override)"
	skip_hostname_edit=true
	skip_quota_edit=true
fi

parse_version_string() {
	local input_string="$1"

	if [[ $input_string =~ (^git|^release)\/(.*?)[:]?([0-9]{4}-[0-9]{2}-[0-9]{2})?[\ ]?([0-9]{2}[:][0-9]{2})?$ ]]; then
		type="${BASH_REMATCH[1]}"
		url="${BASH_REMATCH[2]}"
		date="${BASH_REMATCH[3]}"
		log_info "Spec type: $type"
		log_info "Spec URL: $url"
		log_info "Spec date: $date"
		if [[ $url =~ (.*[^:])[:](.*[^:])[:]?$ ]]; then
			repository="${BASH_REMATCH[1]}"
			branch="${BASH_REMATCH[2]}"
			log_info "Repository: $repository"
			log_info "Branch: $branch"

		elif [[ $url =~ (^[a-zA-Z]*?)[:]?$ ]]; then
			repository=$DEFAULT_REPOSITORY
			branch="${BASH_REMATCH[1]}"
			log_info "Repository: $repository"
			log_info "Branch: $branch"
		else
			log_warn "Spec URL didn't match expected format, using defaults"
			repository=$DEFAULT_REPOSITORY
			branch="main"
		fi
	else
		log_warn "Invalid version spec, using defaults"
	fi
}

# Idempotently append a snippet to a file if it's not already present.
append_unique_block() {
	local file="$1"
	local marker="$2"
	local content="$3"

	if grep -Fqx "$marker" "$file" 2>/dev/null; then
		return
	fi

	printf '%s\n' "$content" >>"$file"
}

# Install packages only if missing to avoid accidental removals
# Wrapper predates the new package pipeline; keep it lean.
ensure_packages() {
	local pkg
	local missing=()
	# Collect packages that are not installed but available in repositories
	for pkg in "$@"; do
		if dpkg -s "$pkg" >/dev/null 2>&1; then
			continue
		fi
		if apt-cache show "$pkg" >/dev/null 2>&1; then
			missing+=("$pkg")
		else
			log_warn "Package $pkg not found in repositories, skipping"
		fi
	done

	if [ ${#missing[@]} -eq 0 ]; then
		return
	fi

	log_step "Installing missing packages: ${missing[*]}"
	local chunk=()
	local len=0
	local max_len=30000
	for pkg in "${missing[@]}"; do
		chunk+=("$pkg")
		len=$((len + ${#pkg} + 1))
		if [ $len -ge $max_len ]; then
			apt-get install -yq "${chunk[@]}"
			chunk=()
			len=0
		fi
	done

	if [ ${#chunk[@]} -gt 0 ]; then
		apt-get install -yq "${chunk[@]}"
	fi
}

export DEBIAN_FRONTEND=noninteractive

preflight_checks() {
	local required_bytes=$((2 * 1024 * 1024 * 1024)) # 2 GiB
	local free_bytes

	free_bytes=$(df -Pk / | awk 'NR==2 {print $4 * 1024}')
	if [ -n "$free_bytes" ] && [ "$free_bytes" -lt "$required_bytes" ]; then
		log_error "Insufficient disk space on / (need >= 2 GiB free)"
		exit 1
	fi

	if command -v curl >/dev/null 2>&1; then
		if ! curl -fsI https://github.com >/dev/null 2>&1; then
			log_warn "GitHub reachability check failed; installer may not fetch updates"
		fi
	else
		log_warn "curl not available; skipping GitHub reachability check"
	fi
}

print_summary() {
	local spec_display
	if [ "$type" = "git" ]; then
		spec_display="${type}/${repository}:${branch}${date:+:$date}"
	else
		spec_display="${type}${url:+/${url}}${date:+:$date}"
	fi

	log_step "Install summary"
	log_info "Spec: ${spec_display}"
	log_info "Hostname prompt: $([[ "$skip_hostname_edit" == true ]] && echo skipped || echo enabled)"
	log_info "Quota prompt: $([[ "$skip_quota_edit" == true ]] && echo skipped || echo enabled)"
	log_info "Apt full-upgrade: $([[ "$SKIP_UPGRADE" == true ]] && echo skipped || echo enabled)"
	log_info "Run update.php: $([[ "$RUN_UPDATE" == true ]] && echo yes || echo no)"
	log_info "Scripts-only flag: $([[ "$SCRIPTS_ONLY" == true ]] && echo yes || echo no)"
	log_info "Dry-run: $([[ "$DRY_RUN" == true ]] && echo yes || echo no)"
}

preflight_checks
print_summary

if [ "$DRY_RUN" = true ]; then
	exit 0
fi

log_info "Installer bootstrap: leaving existing apt sources untouched"
log_step "Updating package lists"
run_cmd apt update
if [ "$SKIP_UPGRADE" != true ]; then
	log_step "Running apt full-upgrade"
	run_cmd apt-get full-upgrade -yqq
else
	log_info "Skipping apt full-upgrade as requested"
fi

# Ensure baseline sysctl, bashrc, and permissions only once.
install_sysctl_defaults() {
	local target="/etc/sysctl.d/1-pmss-defaults.conf"
	cat <<'CONF' >"$target"
# Pulsed Media Config
block/sda/queue/scheduler = bfq
block/sdb/queue/scheduler = bfq
block/sdc/queue/scheduler = bfq
block/sdd/queue/scheduler = bfq
block/sde/queue/scheduler = bfq
block/sdf/queue/scheduler = bfq

block/sda/queue/read_ahead_kb = 1024
block/sdb/queue/read_ahead_kb = 1024
block/sdc/queue/read_ahead_kb = 1024
block/sdd/queue/read_ahead_kb = 1024
block/sde/queue/read_ahead_kb = 1024
block/sdf/queue/read_ahead_kb = 1024

net.ipv4.ip_forward = 1
CONF
}

install_root_shell_defaults() {
	local bashrc="/root/.bashrc"
	local alias_line="alias ls='ls --color=auto'"
	local path_line="PATH=\$PATH:/scripts"

	grep -Fqx "${alias_line}" "$bashrc" 2>/dev/null || echo "${alias_line}" >>"$bashrc"
	grep -Fqx "${path_line}" "$bashrc" 2>/dev/null || echo "${path_line}" >>"$bashrc"
}

# First Let's verify hostname
ensure_packages nano vim quota

# Update the hostname file and apply it via hostnamectl when available.
update_hostname() {
	local new_host="$1"
	if [[ -z "$new_host" ]]; then
		return
	fi

	if hostnamectl >/dev/null 2>&1; then
		hostnamectl set-hostname "$new_host" >/dev/null 2>&1 &&
			log_info "Hostname set via hostnamectl"
	fi
	echo "$new_host" >/etc/hostname
	log_info "/etc/hostname updated"
}

if [[ -n "$hostname_override" ]]; then
	update_hostname "$hostname_override"
elif [[ "$skip_hostname_edit" == true ]]; then
	log_info "Skipping hostname confirmation"
else
	log_step "Review hostname (press Ctrl+X to exit nano)"
	nano /etc/hostname
fi

# Setup fstab for quota and /home array
log_step "Rechecking kernel quota support"
append_unique_block \
    /etc/fstab \
    "#usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1" \
    $'\nproc            /proc           proc    defaults,hidepid=2        0       0\n\n# You may need on target devices:\n#usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1\n'

quota_options="usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1"
perf_options="noatime,nofail"

# Return 0 if /etc/fstab contains a non-comment line for the mount point
fstab_has_mount() {
    local mp="$1"
    grep -Eq "^[[:space:]]*[^#]+[[:space:]]+${mp//\//\/}[[:space:]]+" /etc/fstab
}

# Return 0 if fstab line for mount contains known quota options
fstab_mount_has_quota() {
    local mp="$1"
    grep -Eq "^[[:space:]]*[^#]+[[:space:]]+${mp//\//\/}[[:space:]]+[^[:space:]]+[[:space:]]+[^[:space:]]*(usrjquota=|grpjquota=|usrquota|grpquota)" /etc/fstab
}

# Ensure the mount options for a mount point contain the given CSV list of options.
# Creates a timestamped backup of /etc/fstab when making changes.
ensure_fstab_options() {
    local mount_point="$1"
    local required_csv="$2"

    if [[ -z "$mount_point" || -z "$required_csv" ]]; then
        return 1
    fi

    local tmpfile backup
    tmpfile=$(mktemp)

    awk -v mp="$mount_point" -v reqcsv="$required_csv" '
        BEGIN {
            split(reqcsv, req, ",");
        }
        /^[ \t]*#/ { print; next }
        NF < 2 { print; next }
        {
            if ($2 == mp) {
                # Normalize option field
                opts = $4;
                if (opts == "" || opts == "-" ) {
                    opts = "defaults";
                }
                n = split(opts, cur, ",");
                delete have;
                keep_defaults = 0;
                for (i = 1; i <= n; i++) {
                    if (cur[i] == "") continue;
                    if (cur[i] == "defaults") { keep_defaults = 1; continue; }
                    have[cur[i]] = 1;
                }
                # Add required
                for (i in req) {
                    o = req[i];
                    if (o == "" ) continue;
                    if (!(o in have)) {
                        have[o] = 1;
                    }
                }
                # Rebuild options list
                newopts = "";
                if (keep_defaults) newopts = "defaults";
                for (o in have) {
                    if (newopts == "") newopts = o; else newopts = newopts","o;
                }
                $4 = newopts;
                # Rebuild standard six columns with tabs
                out = $1"\t"$2"\t"$3"\t"$4;
                if (NF >= 5) out = out"\t"$5; else out = out"\t0";
                if (NF >= 6) out = out"\t"$6; else out = out"\t0";
                print out;
                next;
            }
        }
        { print }
    ' /etc/fstab >"$tmpfile"

    # Only replace if content changed
    if ! cmp -s /etc/fstab "$tmpfile"; then
        backup="/etc/fstab.pmss-backup-$(date +%Y%m%d%H%M%S)"
        cp /etc/fstab "$backup" 2>/dev/null || true
        mv "$tmpfile" /etc/fstab
        log_info "Updated /etc/fstab for $mount_point (backup: ${backup##*/})"
        return 0
    fi

    rm -f "$tmpfile"
    return 0
}

if [[ -n "$quota_mountpoint" ]]; then
    ensure_fstab_options "$quota_mountpoint" "$perf_options,$quota_options" || true
elif [[ "$skip_quota_edit" == true ]]; then
    log_info "Skipping quota configuration as requested"
else
    # Default to /home logic: if /home defined, ensure options automatically; if already has quota, skip editor
    if fstab_has_mount "/home"; then
        if fstab_mount_has_quota "/home"; then
            log_info "Quota already configured in /etc/fstab for /home; skipping editor"
        else
            ensure_fstab_options "/home" "$perf_options,$quota_options" || true
        fi
    else
        log_step "Review /etc/fstab quota options (Ctrl+X to exit editor)"
        nano /etc/fstab
    fi
fi

# Best-effort remount to pick up option changes (may be no-op on fresh installs)
mount -o remount /home 2>/dev/null || true

# Minimal prerequisites; remaining packages arrive via update-step2/pmssApplyDpkgSelections.
log_step "Ensuring minimal prerequisites are present"
ensure_packages git rsync curl wget ca-certificates unzip php php-cli php-xml zip unzip vim tzdata

# Build toolchain bootstrap to keep source-built installers (rtorrent, firehol, iprange) working
# even on fresh hosts before the dpkg baseline is applied.
log_step "Ensuring build toolchain prerequisites are present"
ensure_packages build-essential autoconf automake pkg-config libtool subversion

# Script installs from release by default and uses a specific git branch as the source if given string of "git/branch" format
log_step "Setting up base software"
mkdir ~/compile
cd /tmp || exit
rm -rf PMSS*
echo
parse_version_string "$SOURCE_SPEC"
if [ -z "$type" ]; then
	type="git"
	repository="$DEFAULT_REPOSITORY"
	branch="main"
	log_info "Defaulting to git/main"
fi

if [ "$type" = "git" ]; then
	git clone "$repository" PMSS
	(
		cd PMSS || exit
		git checkout "$branch"
		chmod u+x ./scripts/*.php
	)
	rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /
	rm -rf PMSS
	SOURCE="$type/$repository:$branch"
	VERSION="$SOURCE"
else
	VERSION=$(wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tag_name/ {print $(NF-1)}')
	wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/${VERSION}" -O PMSS.tar.gz
	mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1
	rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /
	rm -rf PMSS
	SOURCE="release"
	VERSION="$SOURCE:${VERSION:-unknown}"
fi

mkdir -p /etc/seedbox/config/
echo "$VERSION" >/etc/seedbox/config/version

log_step "Deploying legacy BFQ/sysctl tuning (ensure rc.local unchanged)"
install_sysctl_defaults

log_step "Configuring root shell defaults"
install_root_shell_defaults

log_step "Adjusting /home permissions"
chmod o-rw /home

log_step "Refreshing package lists (final pass before update.php)"
run_cmd apt update

if [ "$RUN_UPDATE" = true ]; then
	log_step "Handing off to /scripts/update.php"
	run_cmd /scripts/update.php "${UPDATE_ARGS[@]}"
	run_cmd /scripts/util/setupRootCron.php
	run_cmd /scripts/util/setupSkelPermissions.php
	run_cmd /scripts/util/quotaFix.php
	run_cmd /scripts/util/ftpConfig.php
else
	log_info "Skipping update.php hand-off (--skip-update)"
fi
