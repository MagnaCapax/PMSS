<?php
// Rootless Docker post-install configuration for Debian 10, 11, and 12
// Repository + package setup is handled centrally during the package phase.

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

// Lightweight file logger for Docker rootless steps
if (!function_exists('dockerRootlessLog')) {
    function dockerRootlessLog(string $message): void
    {
        $dir = '/var/log/pmss';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = date('[Y-m-d H:i:s] ').$message.PHP_EOL;
        @file_put_contents($dir.'/docker-rootless.log', $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('dockerRootlessStep')) {
    function dockerRootlessStep(string $description, string $command): int
    {
        $rc = runStep($description, $command);
        dockerRootlessLog($description.' rc='.$rc);
        return $rc;
    }
}

dockerRootlessLog('Starting Docker rootless configuration');

// Disable Docker system service and remove stray socket
dockerRootlessStep('Docker: disabling system service', 'systemctl disable --now docker.service docker.socket || true');
dockerRootlessStep('Docker: removing socket file', 'rm -f /var/run/docker.sock');

// Enable unprivileged user namespace cloning (rootless requirement)
dockerRootlessStep('Docker: enabling unprivileged user namespaces', "sh -c 'echo kernel.unprivileged_userns_clone = 1 > /etc/sysctl.d/50-rootless.conf'");
dockerRootlessStep('Docker: applying sysctl configuration', 'sysctl --system');

// Debian 10 and 11 require additional rootless helpers
$version = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
if ($version > 0 && $version < 12) {
    $arch = trim((string) @shell_exec('uname -m 2>/dev/null'));
    if ($arch === '') { $arch = 'x86_64'; }
    $url  = 'https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.2/slirp4netns-'.$arch;
    dockerRootlessStep('Docker: downloading slirp4netns helper ('.$arch.')', 'curl -fsSL -o slirp4netns '.escapeshellarg($url));
    dockerRootlessStep('Docker: installing slirp4netns helper', 'install slirp4netns /usr/local/bin/');
    @unlink('slirp4netns');
    dockerRootlessStep('Docker: creating iptables symlink', 'ln -sf /usr/sbin/iptables /usr/local/bin/iptables || true');
}

dockerRootlessLog('Docker rootless configuration complete');
