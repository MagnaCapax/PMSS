<?php
/**
 * Update app installer: docker.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../distro.php';
require_once __DIR__.'/remoteBinary.php';

/** slirp4netns v1.3.6 (libslirp 4.9.5) checksums from the upstream SHA256SUMS; other architectures are skipped. */
const PMSS_DOCKER_SLIRP_SHA256 = [
    'x86_64' => '411167abceff1fc8a7c562f53ed9f26d3ec8a17df4e2fe6eaa3bd53f1c1e1d48',
    'aarch64' => '045f85dd0bc51a61b99cdfe6bd22282cbc308b73caa64fc8859c11d3775c5817',
    'armv7l' => '2ad03ac074257a451d530e98755518e07ece67ed0a3f8bca23879c0bc2211e4c',
    'ppc64le' => 'c69965d8935e922e264049056247713cb50bf5c0b2db3ff153e323f09e034dee',
    'riscv64' => '2433b75fdd9210790708d146ea5e981ac2ba08f5deb26d458d898ebb8b9142e4',
    's390x' => '4f2cf421fb5f934b365d47a873825ecd6a937662b20f2c8f808d56b8c9fcec1c',
];

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

/** True when the helper is a regular file (not a symlink) with the pinned checksum. */
function pmssDockerSlirpHelperMatchesPin(string $helper, string $sha256): bool
{
    return $sha256 !== '' && is_file($helper) && !is_link($helper) && pmssPinnedRemoteChecksum($helper) === strtolower($sha256);
}

/** Remove regular pmss-slirp4netns-XXXXXX copies left by installs that kept the tempnam() name; symlinks stay. */
function pmssDockerRemoveStraySlirpCopies(string $dir, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }
    foreach (glob(rtrim($dir, '/').'/pmss-slirp4netns-*') ?: [] as $straySlirp) {
        if (preg_match('/\Apmss-slirp4netns-[A-Za-z0-9]{6}\z/', basename($straySlirp)) === 1
            && is_file($straySlirp) && !is_link($straySlirp)) {
            pmssPinnedRemoteStaleBinaryRemove($straySlirp, 'stray slirp4netns');
        }
    }
}

if (pmssEnvFlagEnabled('PMSS_DOCKER_NO_ENTRYPOINT')) {
    return;
}

$dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
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

    // dockerd-rootless.sh only finds the helper under its own name on PATH.
    $slirpSha256 = PMSS_DOCKER_SLIRP_SHA256[$arch] ?? '';
    if ($slirpSha256 === '') {
        logmsg('[docker] Warning: no pinned slirp4netns build for '.$arch.'; skipping helper install.');
    } else {
        $slirpUrl = 'https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.6/slirp4netns-'.$arch;
        pmssInstallPinnedRemoteBinary('slirp4netns v1.3.6', $slirpUrl, $slirpSha256, '/usr/local/bin/slirp4netns', true);
    }
    // Keep stray copies until the pinned helper is in place: a hand-made slirp4netns symlink may point at one.
    if (pmssDockerSlirpHelperMatchesPin('/usr/local/bin/slirp4netns', $slirpSha256)) {
        pmssDockerRemoveStraySlirpCopies('/usr/local/bin', $dryRun);
    }
    runStep('[docker] Docker: creating iptables symlink', pmssBuildCommand('ln', ['-sf', '/usr/sbin/iptables', '/usr/local/bin/iptables']));
} elseif ($version >= 12) {
    pmssDockerRemoveStraySlirpCopies('/usr/local/bin', $dryRun);
}

logmsg('[docker] Docker rootless configuration complete');
