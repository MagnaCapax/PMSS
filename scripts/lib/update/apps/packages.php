<?php
/**
 * Package bootstrapper – orchestrates installer stacks defined under packages/.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/packages/helpers.php';

$version = isset($distroVersion) ? (int) $distroVersion : 0;

pmssQueuePackages(['lighttpd', 'lighttpd-mod-webdav']);
pmssInstallProftpdStack($version);

if ($version < 10) {
    logmsg('[WARN] Skipping system utility install: unsupported Debian release');
    logmsg('[WARN] Skipping media/network tool install: unsupported Debian release');
} else {
    pmssQueuePackages(['screen', 'mc', 'wget', 'gawk', 'subversion', 'libtool', 'sqlite', 'locate', 'ntpdate', 'build-essential', 'pkg-config', 'autoconf', 'automake', 'python3', 'python3-pip', 'python3-venv', 'python3-dev']);
    pmssInstallBestEffort(['libncurses6'], 'ncurses runtime');
    pmssInstallBestEffort(['libncurses-dev'], 'ncurses development headers');
    // apache2-utils is required system-wide (htpasswd, ab). Lighttpd user
    // auth and other tooling depend on htpasswd; do NOT remove.
    pmssQueuePackages([
        'python3-pycurl', 'python3-crypto', 'python3-cheetah',
        'zip', 'unzip', 'bwm-ng', 'sysstat', 'apache2-utils', 'irssi', 'iotop', 'ioping', 'ethtool',
        'unrar-free', 'unp',
    ]);
    pmssQueuePackages([
        'libzen0v5', 'sox', 'tmux', 'tree', 'ncdu', 'weechat', 'php-xml', 'php-zip', 'php-sqlite3', 'php-mbstring', 'qbittorrent-nox',
        'zsh', 'atop', 'php-cgi', 'php-cli',
        'aria2', 'htop', 'mtr', 'mktorrent',
        'genisoimage', 'xorriso',
        'net-tools', 'nicstat',
        'restic', 'borgbackup', 'borgmatic', 'borgbackup-doc', 'backupninja',
        'links', 'elinks', 'lynx', 'p7zip-full', 'smartmontools', 'flac', 'lame', 'lame-doc', 'mp3diags', 'gcc', 'g++', 'gettext', 'fuse3', 'glib-networking', 'libglib2.0-dev', 'libfuse-dev', 'apt-transport-https', 'pigz',
        // #TODO revisit curl/libcurl upgrades once a consistent backports policy is defined.
        'unionfs-fuse', 'sshfs', 's3fs', 'uidmap',
        'ranger', 'nethack-console',
        'libmozjs-52-0', 'libmozjs-60-0',
        'libarchive-zip-perl', 'libnet-ssleay-perl', 'libhtml-parser-perl', 'libxml-libxml-perl', 'libjson-perl', 'libjson-xs-perl', 'libxml-libxslt-perl',
        'lftp', 'megatools',
        'nginx', 'ntp',
    ]);

    if ($version == 10) {
        pmssQueuePackages(['linux-image-amd64', 'firmware-bnx2', 'firmware-bnx2x'], 'buster-backports');
    }
}

pmssQueuePackages(['libffi-dev', 'libssl-dev', 'libjpeg-dev', 'zlib1g-dev', 'python3-virtualenv', 'python3-setuptools', 'python3-wheel']);
if (!file_exists('/usr/bin/sabnzbdplus')) { echo "## Installing Sabnzbdplus\n"; pmssQueuePackages(['sabnzbdplus']); }

if ($version < 10) {
    logmsg('[WARN] Skipping ZNC stack: unsupported Debian release');
} else {
    pmssQueuePackages(['znc', 'znc-perl', 'znc-tcl', 'znc-python3', 'git', 'intltool', 'librsvg2-common', 'xdg-utils', 'geoip-database', 'python3-notify2', 'python3-pygame', 'python3-gi', 'python3-mako', 'python3-setproctitle', 'python3-openssl', 'python3-twisted', 'python3-chardet', 'python3-xdg', 'python3-libtorrent']);

    // ffmpeg ships via the dpkg baselines; no additional queueing required here.
}

if (!file_exists('/usr/bin/mkvextract')) { pmssQueuePackages(['mkvtoolnix']); }

if (!file_exists('/usr/sbin/openvpn')) { pmssQueuePackages(['openvpn', 'easy-rsa']); }

pmssQueuePackages(['sudo', 'expect']);

if (!file_exists('/sbin/ipset')) { pmssQueuePackages(['ipset']); }

$pmssWireguardDistroVersion = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
// On Debian 12+ WireGuard is built into the kernel, so we only require the
// userland tools. Avoid queueing the legacy DKMS package which can wedge
// kernel upgrades with BUILD_EXCLUSIVE errors.
$pmssWireguardNeedsDkms = $pmssWireguardDistroVersion < 12;
if (pmssPackageStatus('wireguard-tools') !== 'install ok installed'
    || ($pmssWireguardNeedsDkms && pmssPackageStatus('wireguard-dkms') !== 'install ok installed')) {
    pmssQueuePackages($pmssWireguardNeedsDkms ? ['wireguard', 'wireguard-tools', 'wireguard-dkms'] : ['wireguard', 'wireguard-tools']);
}
pmssQueuePackages(['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin', 'dbus-user-session', 'slirp4netns', 'uidmap', 'fuse-overlayfs']);
