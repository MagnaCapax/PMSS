<?php
/**
 * Debian distribution upgrade facade.
 *
 * Keep `pmssRunDistUpgrade()` here for the bootstrap contract while the
 * implementation lives in focused helpers under `update/distUpgrade/`.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update.php';
pmssRequireRelativeFiles(__DIR__, [
    'distro.php', 'packageState.php', 'userMaintenance.php', 'distUpgrade/plan.php',
    'distUpgrade/sources.php', 'distUpgrade/apt.php', 'distUpgrade/docker.php', 'distUpgrade/boot.php',
]);

/**
 * Entry point used by scripts/update.php for --dist-upgrade runs.
 */
function pmssRunDistUpgrade(?string $maxTarget = null): int
{
    requireRoot();

    if ($maxTarget === null) {
        logMessage('Safety error: You must explicitly specify the maximum Debian major version (e.g., 11 or bullseye).');
        logMessage('Usage: scripts/update.php --dist-upgrade=<maxTarget>');
        return 1;
    }

    $distro = getDistroName();
    if ($distro !== 'debian') {
        logMessage('Unsupported distro for dist-upgrade: '.$distro);
        return 1;
    }

    $current = getDistroVersion();
    $maxMajor = pmssResolveTargetVersion($maxTarget);

    if ($maxMajor === '') {
        logMessage("Unknown maximum version: $maxTarget");
        return 1;
    }

    $plan = pmssResolveDistUpgradeStep($current, $maxMajor);
    if ($plan['message'] !== '') {
        logMessage($plan['message']);
    }
    if ($plan['action'] === 'noop') {
        return 0;
    }
    if ($plan['action'] !== 'upgrade') {
        return 1;
    }

    $from = $plan['from'];
    $next = $plan['to'];
    if ($from === null || $next === null) {
        logMessage('dist-upgrade internal error: upgrade plan missing version data');
        return 1;
    }

    pmssRemoveLegacyWireguardDkms($from, $next);
    logMessage(sprintf('Initiating Debian %s → %s upgrade', $from, $next));
    pmssRewriteSources($from, $next);
    pmssEnsureLibcryptBeforeUpgrade($from, $next);
    if (!pmssExecuteUpgrade()) {
        logMessage('dist-upgrade aborted: apt phase did not complete');
        return 1;
    }

    pmssEnsureInitrdAfterDistUpgrade();
    pmssRepairNginxAfterDistUpgrade();
    pmssEnsureFuseOverlayfsAfterDistUpgrade($next);
    pmssRepairDockerRootlessAfterDistUpgrade($next);
    pmssEnsureBootDefaults(
        null,
        null,
        null,
        null,
        ['console=tty0', 'console=ttyS0,115200n8'],
        [
            'GRUB_TERMINAL' => 'console serial',
            'GRUB_SERIAL_COMMAND' => 'serial --speed=115200 --unit=0 --word=8 --parity=no --stop=1',
        ]
    );
    pmssVerifyDistUpgradeBootReadiness();
    return 0;
}
