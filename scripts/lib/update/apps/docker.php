<?php
/**
 * Update app installer: docker.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../distro.php';

/** Convert uname output into the single URL path component expected upstream. */
function pmssDockerSlirpArchitectureFromOutput($archOutput, ?callable $logger = null): string
{
    $arch = trim((string) $archOutput);
    if ($arch === '') {
        return 'x86_64';
    }
    if (preg_match('/\A[A-Za-z0-9._-]+\z/', $arch) !== 1 || substr($arch, 0, 1) === '-') {
        ($logger ?: 'logmsg')('[docker] Warning: unsafe uname architecture output; falling back to x86_64.');
        return 'x86_64';
    }

    return $arch;
}

/** Allocate a root-owned temporary download path before installing the helper. */
function pmssDockerSlirpTemporaryPath(bool $dryRun = false, ?callable $logger = null): string
{
    if ($dryRun) {
        return sys_get_temp_dir().'/pmss-slirp4netns-dry-run';
    }

    $path = @tempnam(sys_get_temp_dir(), 'pmss-slirp4netns-');
    if (!is_string($path) || $path === '') {
        ($logger ?: 'logmsg')('[docker] Warning: unable to allocate temporary slirp4netns download path.');
        return '';
    }

    return $path;
}

logmsg('[docker] Starting Docker rootless configuration');
// Disable Docker system service and remove stray socket
runStep('[docker] Docker: disabling system service', pmssBuildCommand('systemctl', ['disable', '--now', 'docker.service', 'docker.socket']));
runStep('[docker] Docker: removing socket file', pmssBuildCommand('rm', ['-f', '/var/run/docker.sock']));
// Enable unprivileged user namespace cloning (rootless requirement)
runStep('[docker] Docker: enabling unprivileged user namespaces', "sh -c 'echo kernel.unprivileged_userns_clone = 1 > /etc/sysctl.d/50-rootless.conf'");
runStep('[docker] Docker: applying sysctl configuration', pmssBuildCommand('sysctl', ['--system']));
// Debian 10 and 11 require additional rootless helpers
$version = pmssDistroVersionFromEnv();
if ($version > 0 && $version < 12) {
    $archOutput = shell_exec('uname -m 2>/dev/null');
    logmsg("[docker] Command 'uname -m 2>/dev/null' output: ".trim((string) $archOutput));
    $arch = pmssDockerSlirpArchitectureFromOutput($archOutput);

    // #TODO: Generalize this to a version-managed dependency system
    $url  = 'https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.2/slirp4netns-'.$arch;

    $dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
    $downloadPath = pmssDockerSlirpTemporaryPath($dryRun);
    if ($downloadPath !== '') {
        $downloadRc = runStep('[docker] Docker: downloading slirp4netns helper ('.$arch.')', pmssBuildCommand('curl', ['-fsSL', '-o', $downloadPath, $url]));
        if ($downloadRc === 0 && ($dryRun || is_file($downloadPath))) {
            runStep('[docker] Docker: installing slirp4netns helper', pmssBuildCommand('install', [$downloadPath, '/usr/local/bin/']));
        } else {
            logmsg('[docker] Warning: slirp4netns download failed; skipping helper install.');
        }
        if (!$dryRun && file_exists($downloadPath) && !@unlink($downloadPath)) {
            logmsg('[docker] Warning: unable to remove temporary slirp4netns helper from download directory.');
        }
    }
    runStep('[docker] Docker: creating iptables symlink', pmssBuildCommand('ln', ['-sf', '/usr/sbin/iptables', '/usr/local/bin/iptables']));
}

logmsg('[docker] Docker rootless configuration complete');
