<?php
/**
 * Package bootstrapper – orchestrates installer stacks defined under packages/.
 */

require_once __DIR__.'/packages/helpers.php';
require_once __DIR__.'/packages/system.php';
require_once __DIR__.'/packages/python.php';
require_once __DIR__.'/packages/docker.php';

$version = isset($distroVersion) ? (int)$distroVersion : 0;

pmssInstallBaseTools();
pmssInstallProftpdStack($version);
pmssInstallSystemUtilities($version);
pmssInstallMediaAndNetworkTools($version);
pmssInstallPythonToolchain($version);
if (!file_exists('/usr/bin/sabnzbdplus')) {
    echo "## Installing Sabnzbdplus\n";
    pmssQueuePackages(['sabnzbdplus']);
}
pmssInstallZncStack($version);
if (!file_exists('/usr/bin/mkvextract')) {
    pmssQueuePackages(['mkvtoolnix']);
}

if (!file_exists('/usr/sbin/openvpn')) {
    pmssQueuePackages(['openvpn', 'easy-rsa']);
}

pmssQueuePackages(['sudo', 'expect']);

if (!file_exists('/sbin/ipset')) {
    pmssQueuePackages(['ipset']);
}

$pmssWireguardDistroVersion = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
// On Debian 12+ WireGuard is built into the kernel, so we only require the
// userland tools. Avoid queueing the legacy DKMS package which can wedge
// kernel upgrades with BUILD_EXCLUSIVE errors.
if ($pmssWireguardDistroVersion >= 12) {
    if (pmssPackageStatus('wireguard-tools') !== 'install ok installed') {
        pmssQueuePackages(['wireguard', 'wireguard-tools']);
    }
} elseif (pmssPackageStatus('wireguard-tools') !== 'install ok installed'
    || pmssPackageStatus('wireguard-dkms') !== 'install ok installed') {
    pmssQueuePackages(['wireguard', 'wireguard-tools', 'wireguard-dkms']);
}
pmssInstallDockerPackages($version);
