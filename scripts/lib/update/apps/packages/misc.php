<?php
/**
 * Miscellaneous application installers.
 */

require_once __DIR__.'/helpers.php';

function pmssInstallSabnzbd(): void
{
    if (!file_exists('/usr/bin/sabnzbdplus')) {
        echo "## Installing Sabnzbdplus\n";
        pmssQueuePackages(['sabnzbdplus']);
    }
}

function pmssInstallMiscTools(): void
{
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
}

function pmssInstallWireguardPackages(): void
{
    $distroVersion = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);

    // On Debian 12+ WireGuard is built into the kernel, so we only require the
    // userland tools. Avoid queueing the legacy DKMS package which can wedge
    // kernel upgrades with BUILD_EXCLUSIVE errors.
    if ($distroVersion >= 12) {
        if (pmssPackageStatus('wireguard-tools') !== 'install ok installed') {
            pmssQueuePackages(['wireguard', 'wireguard-tools']);
        }
        return;
    }

    // Skip queueing when the runtime already has the necessary tooling in place.
    if (pmssPackageStatus('wireguard-tools') === 'install ok installed'
        && pmssPackageStatus('wireguard-dkms') === 'install ok installed') {
        return;
    }

    pmssQueuePackages(['wireguard', 'wireguard-tools', 'wireguard-dkms']);
}
