<?php
if (!function_exists('dockerRootlessLog')) {
    function dockerRootlessLog(string $message): void
    {
        if (function_exists('logmsg')) {
            logmsg('[docker] '.$message);
        } else {
            echo '[docker] '.$message."\n";
        }
    }
}

if (!function_exists('dockerRootlessStep')) {
    function dockerRootlessStep(string $description, string $command): int
    {
        return runStep('[docker] '.$description, $command);
    }
}

if (!function_exists('dockerRootlessShellExec')) {
    function dockerRootlessShellExec(string $command): string
    {
        $output = shell_exec($command);
        dockerRootlessLog("Command '{$command}' output: " . trim($output));
        return $output;
    }
}

dockerRootlessLog('Starting Docker rootless configuration');

// Disable Docker system service and remove stray socket
dockerRootlessStep('Docker: disabling system service', 'systemctl disable --now docker.service docker.socket');
dockerRootlessStep('Docker: removing socket file', 'rm -f /var/run/docker.sock');

// Enable unprivileged user namespace cloning (rootless requirement)
dockerRootlessStep('Docker: enabling unprivileged user namespaces', "sh -c 'echo kernel.unprivileged_userns_clone = 1 > /etc/sysctl.d/50-rootless.conf'");
dockerRootlessStep('Docker: applying sysctl configuration', 'sysctl --system');

// Debian 10 and 11 require additional rootless helpers
$version = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
if ($version > 0 && $version < 12) {
    $arch = trim((string) dockerRootlessShellExec('uname -m 2>/dev/null'));
    if ($arch === '') { $arch = 'x86_64'; }
    
    // #TODO: Generalize this to a version-managed dependency system
    $url  = 'https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.2/slirp4netns-'.$arch;
    
    dockerRootlessStep('Docker: downloading slirp4netns helper ('.$arch.')', 'curl -fsSL -o slirp4netns '.escapeshellarg($url));
    dockerRootlessStep('Docker: installing slirp4netns helper', 'install slirp4netns /usr/local/bin/');
    @unlink('slirp4netns');
    dockerRootlessStep('Docker: creating iptables symlink', 'ln -sf /usr/sbin/iptables /usr/local/bin/iptables');
}

dockerRootlessLog('Docker rootless configuration complete');
