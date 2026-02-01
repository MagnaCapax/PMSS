<?php
/**
 * Base system package groups.
 *
 * Debian support matrix:
 *   - 10 (buster): queues base/system/media sets plus kernel/firmware from
 *     buster-backports when available.
 *   - 11 (bullseye) and 12 (bookworm): reuse the same package lists; helpers
 *     rely on dpkg baselines to keep drift minimal.
 *   - #TODO #Debian13: validate whether these lists remain correct on Debian 13 (trixie)
 *     once the Debian 13 dpkg baseline is captured.
 *   - <10: installers log a warning and skip execution.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/helpers.php';

function pmssInstallSystemUtilities(int $distroVersion): void
{
    if ($distroVersion < 10) {
        logmsg('[WARN] Skipping system utility install: unsupported Debian release');
        return;
    }

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
}

function pmssInstallMediaAndNetworkTools(int $distroVersion): void
{
    if ($distroVersion < 10) {
        logmsg('[WARN] Skipping media/network tool install: unsupported Debian release');
        return;
    }

    pmssQueuePackages([
        'libzen0v5', 'sox', 'tmux', 'tree', 'ncdu', 'weechat', 'php-xml', 'php-zip', 'php-sqlite3', 'php-mbstring', 'qbittorrent-nox',
        'zsh', 'atop', 'php-cgi', 'php-cli',
        'aria2', 'htop', 'mtr', 'mktorrent',
        'genisoimage', 'xorriso',
        'uidmap',
        'net-tools', 'nicstat',
        'restic', 'borgbackup', 'borgmatic', 'borgbackup-doc', 'backupninja',
        'links', 'elinks', 'lynx', 'ethtool', 'zip', 'p7zip-full', 'smartmontools', 'flac', 'lame', 'lame-doc', 'mp3diags', 'gcc', 'g++', 'gettext', 'fuse3', 'glib-networking', 'libglib2.0-dev', 'libfuse-dev', 'apt-transport-https', 'pigz',
        'python3-cheetah',
        // #TODO revisit curl/libcurl upgrades once a consistent backports policy is defined.
        'unionfs-fuse', 'sshfs', 's3fs',
        'ranger', 'nethack-console',
        'libmozjs-52-0', 'libmozjs-60-0',
        'libarchive-zip-perl', 'libnet-ssleay-perl', 'libhtml-parser-perl', 'libxml-libxml-perl', 'libjson-perl', 'libjson-xs-perl', 'libxml-libxslt-perl',
        'lftp', 'megatools',
        'nginx', 'ntp',
    ]);

    if ($distroVersion == 10) {
        pmssQueuePackages(['linux-image-amd64', 'firmware-bnx2', 'firmware-bnx2x'], 'buster-backports');
    }
}
