<?php
/**
 * Dist-upgrade rootless Docker and fuse-overlayfs repairs.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssEnsureFuseOverlayfsAfterDistUpgrade(string $toMajor): void
{
    if ((int) $toMajor < 11) {
        return;
    }
    if (empty(glob('/home/*/.config/docker/daemon.json'))) {
        logMessage('[SKIP] dist-upgrade: no rootless Docker configs found; skipping fuse-overlayfs');
        return;
    }
    if (pmssPackageStatus('fuse-overlayfs') === 'install ok installed') {
        logMessage('[SKIP] dist-upgrade: fuse-overlayfs already installed');
        return;
    }
    if (runCommand('apt-cache show fuse-overlayfs >/dev/null 2>&1') !== 0) {
        $arch = trim((string) @shell_exec('dpkg --print-architecture 2>/dev/null'));
        logMessage('[WARN] dist-upgrade: fuse-overlayfs not available in apt cache'.($arch !== '' ? ' (arch='.$arch.')' : ''));
        return;
    }

    list($env, $hasTty) = pmssDistUpgradeAptEnv(false);
    logMessage('dist-upgrade: ensuring fuse-overlayfs is installed for rootless Docker');
    $rc = runCommand(pmssDistUpgradeAptCommand($env, 'install', 'fuse-overlayfs'), true, null, $hasTty);
    if ($rc !== 0) {
        logMessage('[WARN] dist-upgrade: failed to install fuse-overlayfs; rootless Docker may fail or fall back to a slower storage driver');
    }
}

function pmssRepairDockerRootlessAfterDistUpgrade(string $toMajor): void
{
    if ((int) $toMajor < 11) {
        return;
    }
    if ((string) getenv('PMSS_DISTRO_VERSION') === '') {
        putenv('PMSS_DISTRO_VERSION='.(string) ((int) $toMajor));
    }

    $targets = [];
    foreach (pmssManagedHomeUsersList(true) as $userTrim) {
        $home = '/home/'.$userTrim;
        if (!is_dir($home)) {
            continue;
        }
        if (is_dir($home.'/www-disabled')) {
            pmssUserLog($userTrim, '[SKIP] dist-upgrade: user appears suspended; skipping rootless Docker repair');
            continue;
        }
        if (is_file($home.'/.config/docker/daemon.json') || is_file($home.'/.config/systemd/user/docker.service')) {
            $targets[] = $userTrim;
        }
    }

    logMessage(empty($targets) ? '[SKIP] dist-upgrade: no rootless Docker configs found; skipping rootless repair' : sprintf('dist-upgrade: repairing rootless Docker for %d user(s)', count($targets)));
    foreach ($targets as $user) {
        pmssUserLog($user, 'dist-upgrade: rootless Docker repair start');
        pmssEnsureRootlessDockerInstalled($user);
        pmssEnsureDockerDependencies($user);
    }

    if (is_file('/usr/bin/slirp4netns') && is_file('/usr/local/bin/slirp4netns')) {
        logMessage(@unlink('/usr/local/bin/slirp4netns') ? 'dist-upgrade: removed stale /usr/local/bin/slirp4netns' : '[WARN] dist-upgrade: failed to remove /usr/local/bin/slirp4netns');
    }
}
