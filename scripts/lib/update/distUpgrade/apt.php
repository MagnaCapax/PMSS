<?php
/**
 * Dist-upgrade apt execution, lock waiting, and recovery helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../dpkgLocks.php';

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
    foreach ($suffix === '' ? [] : pmssConfigLineColumns($suffix, 0, []) as $token) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.+:~_-]*$/', $token) !== 1) {
            throw new InvalidArgumentException('Unsafe dist-upgrade apt arguments');
        }
    }

    $opts = ' -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';
    return trim($env.' apt-get '.$action.$opts.($suffix !== '' ? ' '.$suffix : ''));
}

function pmssDistUpgradeWaitForLocksOrLog(string $message): bool
{
    if (pmssWaitForDpkgLocks()) {
        return true;
    }

    logMessage($message);
    return false;
}

/**
 * Deadline for one dist-upgrade apt/dpkg step. On a slow host one step can need longer than the
 * 1200 s default, and killing dpkg mid-unpack leaves it interrupted with PHP half-upgraded.
 * ADR 0035 keeps one deadline in the shared layer; this caller sets its own, like userTransfer.
 * PMSS_DIST_UPGRADE_APT_TIMEOUT overrides it; 0 means no deadline.
 */
function pmssDistUpgradeAptTimeoutSeconds(): int
{
    return pmssEnvReadDigits('PMSS_DIST_UPGRADE_APT_TIMEOUT') ?? 7200;
}

function pmssDistUpgradeRunLockedCommand(string $command, string $lockMessage, bool $inheritTty = false): ?int
{
    if (!pmssDistUpgradeWaitForLocksOrLog($lockMessage)) {
        return null;
    }

    return pmssCommandWithTimeout(pmssDistUpgradeAptTimeoutSeconds(), 'runCommand', [$command, true, null, $inheritTty]);
}

function pmssExecuteUpgrade(): bool
{
    list($env, $hasTty) = pmssDistUpgradeAptEnv();
    if (pmssDistUpgradeRunLockedCommand("$env apt-get update", '[ERROR] dist-upgrade: dpkg lock did not clear; aborting apt phase', $hasTty) === null) {
        return false;
    }

    // A failed upgrade can still be resolved by full-upgrade, so only the full-upgrade result decides.
    pmssRunUpgradeWithRecovery(
        pmssDistUpgradeAptCommand($env, 'upgrade'),
        $env,
        'dist-upgrade: upgrade failed, attempting recovery (dpkg --configure -a, apt-get -f install)',
        $hasTty
    );
    if (!pmssRunUpgradeWithRecovery(
        pmssDistUpgradeAptCommand($env, 'full-upgrade'),
        $env,
        'dist-upgrade: full-upgrade failed, attempting recovery',
        $hasTty
    )) {
        logMessage('[ERROR] dist-upgrade: full-upgrade still failing after recovery');
        return false;
    }

    foreach ([["$env apt-get autoremove -y", '[ERROR] dist-upgrade: dpkg lock did not clear; aborting apt autoremove'], ["$env dpkg --configure -a", '[ERROR] dist-upgrade: dpkg lock did not clear; skipping dpkg --configure -a']] as [$command, $lockMessage]) {
        if (pmssDistUpgradeRunLockedCommand($command, $lockMessage, $hasTty) === null) {
            return false;
        }
    }
    return true;
}

/**
 * Run an apt action; on failure repair dpkg and retry it once. True only when the action succeeded.
 */
function pmssRunUpgradeWithRecovery(string $command, string $env, string $recoveryMessage, bool $inheritTty = false): bool
{
    if (($rc = pmssDistUpgradeRunLockedCommand($command, '[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt action', $inheritTty)) === null || $rc === 0) {
        return $rc === 0;
    }

    logMessage($recoveryMessage);
    foreach ([
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping dpkg recovery', "$env dpkg --configure -a"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt recovery', "$env apt-get -f install -y"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt update', "$env apt-get update"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt retry', $command],
    ] as [$lockMessage, $recoveryCommand]) {
        if (($rc = pmssDistUpgradeRunLockedCommand($recoveryCommand, $lockMessage, $inheritTty)) === null) {
            return false;
        }
    }
    return $rc === 0;
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
