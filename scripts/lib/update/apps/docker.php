<?php
/**
 * Update app installer: docker.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function_exists('logmsg') && logmsg('[docker] Starting Docker rootless configuration');

// Disable Docker system service and remove stray socket
runStep('[docker] Docker: disabling system service', 'systemctl disable --now docker.service docker.socket');
runStep('[docker] Docker: removing socket file', 'rm -f /var/run/docker.sock');

// Enable unprivileged user namespace cloning (rootless requirement)
runStep('[docker] Docker: enabling unprivileged user namespaces', "sh -c 'echo kernel.unprivileged_userns_clone = 1 > /etc/sysctl.d/50-rootless.conf'");
runStep('[docker] Docker: applying sysctl configuration', 'sysctl --system');

// Debian 10 and 11 require additional rootless helpers
$version = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
if ($version > 0 && $version < 12) {
    $archOutput = shell_exec('uname -m 2>/dev/null');
    function_exists('logmsg') && logmsg("[docker] Command 'uname -m 2>/dev/null' output: ".trim((string) $archOutput));
    $arch = trim((string) $archOutput) ?: 'x86_64';

    // #TODO: Generalize this to a version-managed dependency system
    $url  = 'https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.2/slirp4netns-'.$arch;

    runStep('[docker] Docker: downloading slirp4netns helper ('.$arch.')', 'curl -fsSL -o slirp4netns '.escapeshellarg($url));
    runStep('[docker] Docker: installing slirp4netns helper', 'install slirp4netns /usr/local/bin/');
    @unlink('slirp4netns');
    runStep('[docker] Docker: creating iptables symlink', 'ln -sf /usr/sbin/iptables /usr/local/bin/iptables');
}

function_exists('logmsg') && logmsg('[docker] Docker rootless configuration complete');
