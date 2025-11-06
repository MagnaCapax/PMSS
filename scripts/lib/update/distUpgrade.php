<?php
/**
 * Debian distribution upgrade helpers.
 */

require_once __DIR__.'/../update.php';

/**
 * Entry point used by util/update-dist-upgrade.php.
 */
function pmssRunDistUpgrade(): int
{
    requireRoot();

    $distro = getDistroName();
    if ($distro !== 'debian') {
        logMessage('Unsupported distro for dist-upgrade: '.$distro);
        return 1;
    }

    $version = getDistroVersion();
    [$from, $to] = pmssDetermineUpgradePath($version);
    if ($from === null || $to === null) {
        logMessage('No upgrade recipe for Debian '.$version);
        return 0;
    }

    logMessage(sprintf('Initiating Debian %s → %s upgrade', $from, $to));
    pmssRewriteSources($from, $to);
    pmssExecuteUpgrade();
    return 0;
}

/**
 * Map current Debian version to the next supported release.
 */
function pmssDetermineUpgradePath(string $current): array
{
    switch ($current) {
        case '10':
            return ['10', '11'];
        case '11':
            return ['11', '12'];
        case '12':
            return ['12', '13'];
        default:
            return [null, null];
    }
}

/**
 * Rewrite /etc/apt sources from one codename to another with security adjustments.
 */
function pmssRewriteSources(string $fromMajor, string $toMajor): void
{
    $from = pmssCodenameForMajor($fromMajor);
    $to   = pmssCodenameForMajor($toMajor);
    if ($from === '' || $to === '') {
        logMessage('Unable to resolve codenames for upgrade path');
        return;
    }

    $sedPairs = [
        [sprintf("s/\\<%s\\>/%s/g", $from, $to), '/etc/apt/sources.list'],
        [sprintf("s#%s/updates#%s-security#g", $to, $to), '/etc/apt/sources.list'],
        [sprintf("s/\\<%s\\>/%s/g", $from, $to), '/etc/apt/sources.list.d/*.list'],
        [sprintf("s#%s/updates#%s-security#g", $to, $to), '/etc/apt/sources.list.d/*.list'],
    ];

    foreach ($sedPairs as [$expr, $path]) {
        runCommand("sed -i '{$expr}' {$path}");
    }
}

/**
 * Execute the apt dist-upgrade sequence in noninteractive mode.
 */
function pmssExecuteUpgrade(): void
{
    $commands = [
        'export DEBIAN_FRONTEND=noninteractive',
        'apt-get update',
        'apt-get upgrade -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\"',
        'apt-get full-upgrade -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\"',
        'apt-get autoremove -y',
    ];

    runCommand(implode(' && ', $commands), true);
}

/**
 * Translate Debian major version to codename.
 */
function pmssCodenameForMajor(string $major): string
{
    switch ($major) {
        case '10':
            return 'buster';
        case '11':
            return 'bullseye';
        case '12':
            return 'bookworm';
        case '13':
            return 'trixie';
        default:
            return '';
    }
}
