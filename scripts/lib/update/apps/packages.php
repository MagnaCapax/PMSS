<?php
/**
 * Package bootstrapper – orchestrates installer stacks defined under packages/.
 */

require_once __DIR__.'/packages/system.php';
require_once __DIR__.'/packages/python.php';

$version = isset($distroVersion) ? (int)$distroVersion : 0;

pmssQueuePackages(['lighttpd', 'lighttpd-mod-webdav']);
pmssInstallProftpdStack($version);
pmssInstallSystemUtilities($version);
pmssInstallMediaAndNetworkTools($version);
pmssQueuePackages(['libffi-dev', 'libssl-dev', 'libjpeg-dev', 'zlib1g-dev', 'python3', 'python3-dev', 'python3-venv', 'python3-virtualenv', 'python3-pip', 'python3-setuptools', 'python3-wheel']);
if (!file_exists('/usr/bin/sabnzbdplus')) { echo "## Installing Sabnzbdplus\n"; pmssQueuePackages(['sabnzbdplus']); }
pmssInstallZncStack($version);
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
$dockerPackages = ['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin', 'dbus-user-session', 'slirp4netns', 'uidmap'];
if ($version < 12) { $dockerPackages[] = 'fuse-overlayfs'; }
pmssQueuePackages($dockerPackages);
