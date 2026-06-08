<?php
/**
 * Dist-upgrade apt execution, lock waiting, and recovery helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssDistUpgradeAptEnv(bool $warnWhenInteractiveUnavailable = true): array
{
    $interactiveRequested = pmssEnvFlagEnabled('PMSS_DIST_UPGRADE_INTERACTIVE');
    $hasTty = $interactiveRequested && pmssStandardStreamsAreTty();
    if ($warnWhenInteractiveUnavailable && $interactiveRequested && !$hasTty) {
        logMessage('[WARN] PMSS_DIST_UPGRADE_INTERACTIVE=1 requested, but no TTY detected; continuing in noninteractive mode.');
    }

    return [pmssAptDpkgEnvPrefix(['DEBIAN_FRONTEND' => $hasTty ? 'readline' : 'noninteractive']), $hasTty];
}

function pmssDistUpgradeAptCommand(string $env, string $action, string $arguments = ''): string
{
    if (!in_array($action, ['install', 'upgrade', 'full-upgrade'], true)) {
        throw new InvalidArgumentException('Unsafe dist-upgrade apt action: '.$action);
    }

    $suffix = trim($arguments);
    if (preg_match('/[\r\n\0]/', $arguments) === 1) {
        throw new InvalidArgumentException('Unsafe dist-upgrade apt arguments');
    }
    foreach ($suffix === '' ? [] : (preg_split('/\s+/', $suffix) ?: []) as $token) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.+:~_-]*$/', $token) !== 1) {
            throw new InvalidArgumentException('Unsafe dist-upgrade apt arguments');
        }
    }

    $aptAction = $action === 'install' ? 'apt-get install' : 'apt-get '.$action;
    $opts = ' -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';
    return trim($env.' '.$aptAction.$opts.($suffix !== '' ? ' '.$suffix : ''));
}

function pmssWaitForDpkgLocks(int $timeoutSeconds = 1800, int $sleepSeconds = 5): bool
{
    if (pmssCommandPath('fuser') === '') {
        logMessage('[WARN] dist-upgrade: fuser not available; skipping dpkg lock checks');
        return true;
    }

    $paths = ['/var/lib/dpkg/lock-frontend', '/var/lib/dpkg/lock', '/var/lib/apt/lists/lock', '/var/cache/apt/archives/lock'];
    $start = time();
    $announced = false;
    while (pmssDpkgLockActive($paths)) {
        if (!$announced) {
            logMessage('dist-upgrade: dpkg lock detected; waiting for release');
            $announced = true;
        }
        if ((time() - $start) >= $timeoutSeconds) {
            logMessage('[ERROR] dist-upgrade: dpkg lock did not clear within '.$timeoutSeconds.' seconds');
            return false;
        }
        sleep($sleepSeconds);
    }

    if ($announced) {
        logMessage('dist-upgrade: dpkg lock cleared; continuing');
    }
    return true;
}

function pmssDistUpgradeWaitForLocksOrLog(string $message): bool
{
    if (pmssWaitForDpkgLocks()) {
        return true;
    }

    logMessage($message);
    return false;
}

function pmssDistUpgradeRunLockedCommand(string $command, string $lockMessage, bool $inheritTty = false): ?int
{
    return pmssDistUpgradeWaitForLocksOrLog($lockMessage) ? runCommand($command, true, null, $inheritTty) : null;
}

function pmssDpkgLockActive(array $paths): bool
{
    foreach ($paths as $path) {
        $rc = 1;
        @exec('fuser '.escapeshellarg($path).' 2>/dev/null', $output, $rc);
        if ($rc === 0) {
            return true;
        }
    }
    return false;
}

function pmssExecuteUpgrade(): bool
{
    list($env, $hasTty) = pmssDistUpgradeAptEnv();
    if (pmssDistUpgradeRunLockedCommand("$env apt-get update", '[ERROR] dist-upgrade: dpkg lock did not clear; aborting apt phase', $hasTty) === null) {
        return false;
    }

    pmssRunUpgradeWithRecovery(
        pmssDistUpgradeAptCommand($env, 'upgrade'),
        $env,
        'dist-upgrade: upgrade failed, attempting recovery (dpkg --configure -a, apt-get -f install)',
        $hasTty
    );
    pmssRunUpgradeWithRecovery(
        pmssDistUpgradeAptCommand($env, 'full-upgrade'),
        $env,
        'dist-upgrade: full-upgrade failed, attempting recovery',
        $hasTty
    );

    foreach ([["$env apt-get autoremove -y", '[ERROR] dist-upgrade: dpkg lock did not clear; aborting apt autoremove'], ["$env dpkg --configure -a", '[ERROR] dist-upgrade: dpkg lock did not clear; skipping dpkg --configure -a']] as $lockedStep) {
        if (pmssDistUpgradeRunLockedCommand($lockedStep[0], $lockedStep[1], $hasTty) === null) {
            return false;
        }
    }
    return true;
}

function pmssRunUpgradeWithRecovery(string $command, string $env, string $recoveryMessage, bool $inheritTty = false): void
{
    if (($rc = pmssDistUpgradeRunLockedCommand($command, '[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt action', $inheritTty)) === null || $rc === 0) {
        return;
    }

    logMessage($recoveryMessage);
    foreach ([
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping dpkg recovery', "$env dpkg --configure -a"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt recovery', "$env apt-get -f install -y"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt update', "$env apt-get update"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt retry', $command],
    ] as $recoveryStep) {
        if (pmssDistUpgradeRunLockedCommand($recoveryStep[1], $recoveryStep[0], $inheritTty) === null) {
            return;
        }
    }
}

function pmssRemoveLegacyWireguardDkms(string $fromMajor, string $toMajor): void
{
    if ($fromMajor !== '11' || $toMajor !== '12') {
        return;
    }

    logMessage('dist-upgrade: removing legacy wireguard-dkms before Debian 11 → 12 upgrade');
    $env = pmssAptDpkgEnvPrefix();
    runCommand("$env apt-get purge -y wireguard-dkms", true);
    runCommand('dkms remove wireguard/1.0.20210219 --all || true', true);
}

function pmssEnsureLibcryptBeforeUpgrade(string $fromMajor, string $toMajor): void
{
    if ($fromMajor !== '11' || $toMajor !== '12') {
        return;
    }

    logMessage('dist-upgrade: ensuring libcrypt1 is installed before Debian 11 → 12 upgrade');
    list($env, $hasTty) = pmssDistUpgradeAptEnv();
    if (pmssDistUpgradeRunLockedCommand("$env apt-get update", '[WARN] dist-upgrade: dpkg lock did not clear; skipping libcrypt1 preinstall', $hasTty) === null) {
        return;
    }
    if (($installRc = pmssDistUpgradeRunLockedCommand(pmssDistUpgradeAptCommand($env, 'install', 'libcrypt1'), '[WARN] dist-upgrade: dpkg lock did not clear; skipping libcrypt1 install', $hasTty)) !== null && $installRc !== 0) {
        logMessage('[WARN] dist-upgrade: libcrypt1 preinstall failed; continuing');
    }
}
