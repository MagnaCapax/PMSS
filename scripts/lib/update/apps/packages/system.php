<?php
/**
 * Base system package groups.
 *
 * Debian support matrix:
 *   - 10 (buster): queues base/system/media sets plus kernel/firmware from
 *     buster-backports when available.
 *   - 11 (bullseye) and 12 (bookworm): reuse the same package lists; helpers
 *     rely on dpkg baselines to keep drift minimal.
 *   - <10: installers log a warning and skip execution.
 */

require_once __DIR__.'/helpers.php';

function pmssInstallBaseTools(): void
{
    pmssQueuePackages(['lighttpd', 'lighttpd-mod-webdav']);
}

function pmssInstallSystemUtilities(int $distroVersion): void
{
    if ($distroVersion < 10) {
        logmsg('[WARN] Skipping system utility install: unsupported Debian release');
        return;
    }

    pmssQueuePackages(['screen', 'mc', 'wget', 'gawk', 'subversion', 'libtool', 'sqlite', 'locate', 'ntpdate', 'build-essential', 'pkg-config', 'autoconf', 'automake', 'python3', 'python3-pip', 'python3-venv', 'python3-dev']);
    pmssInstallBestEffort([
        'libncurses6',
    ], 'ncurses runtime');
    pmssInstallBestEffort([
        'libncurses-dev',
    ], 'ncurses development headers');
    pmssQueuePackages(['python3-pycurl', 'python3-crypto', 'python3-cheetah']);
    // apache2-utils is required system-wide (htpasswd, ab). Lighttpd user
    // auth and other tooling depend on htpasswd; do NOT remove.
    pmssQueuePackages(['zip', 'unzip', 'bwm-ng', 'sysstat', 'apache2-utils', 'irssi', 'iotop', 'ethtool']);
    pmssQueuePackages(['unrar-free', 'unp']);
}

function pmssInstallMediaAndNetworkTools(int $distroVersion): void
{
    if ($distroVersion < 10) {
        logmsg('[WARN] Skipping media/network tool install: unsupported Debian release');
        return;
    }

    pmssQueuePackages(['libzen0v5', 'sox', 'tmux', 'tree', 'ncdu', 'weechat', 'php-xml', 'php-zip', 'php-sqlite3', 'php-mbstring', 'qbittorrent-nox']);

    pmssQueuePackages(['zsh', 'atop', 'php-cgi', 'php-cli']);
    pmssQueuePackages(['aria2', 'htop', 'mtr', 'mktorrent']);
    pmssQueuePackages(['genisoimage', 'xorriso']);
    pmssQueuePackages(['uidmap']);
    pmssQueuePackages(['net-tools', 'nicstat']);
    pmssQueuePackages(['restic', 'borgbackup', 'borgmatic', 'borgbackup-doc', 'backupninja']);

    pmssQueuePackages(['links', 'elinks', 'lynx', 'ethtool', 'zip', 'p7zip-full', 'smartmontools', 'flac', 'lame', 'lame-doc', 'mp3diags', 'gcc', 'g++', 'gettext', 'fuse3', 'glib-networking', 'libglib2.0-dev', 'libfuse-dev', 'apt-transport-https', 'pigz']);
    pmssQueuePackages(['python3-cheetah']);

    // #TODO revisit curl/libcurl upgrades once a consistent backports policy is defined.
    pmssQueuePackages(['unionfs-fuse', 'sshfs', 's3fs']);
    pmssQueuePackages(['ranger', 'nethack-console']);

    pmssQueuePackages(['libmozjs-52-0', 'libmozjs-60-0']);

    if ($distroVersion == 10) {
        pmssQueuePackages(['linux-image-amd64', 'firmware-bnx2', 'firmware-bnx2x'], 'buster-backports');
    }

    pmssQueuePackages(['libarchive-zip-perl', 'libnet-ssleay-perl', 'libhtml-parser-perl', 'libxml-libxml-perl', 'libjson-perl', 'libjson-xs-perl', 'libxml-libxslt-perl']);
    pmssQueuePackages(['lftp']);
    pmssQueuePackages(['nginx', 'ntp']);
}
