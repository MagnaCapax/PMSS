<?php
/**
 * Debian distribution upgrade helpers.
 */

require_once __DIR__.'/../update.php';
require_once __DIR__.'/distro.php';

/**
 * Entry point used by util/update-dist-upgrade.php.
 */
function pmssRunDistUpgrade(?string $target = null): int
{
    requireRoot();

    if ($target === null) {
        logMessage('Safety error: You must explicitly specify the target Debian major version (e.g., 11 or bullseye).');
        logMessage('Usage: scripts/util/update-dist-upgrade.php <target>');
        return 1;
    }

    $distro = getDistroName();
    if ($distro !== 'debian') {
        logMessage('Unsupported distro for dist-upgrade: '.$distro);
        return 1;
    }

    $current = getDistroVersion();
    $targetMajor = pmssResolveTargetVersion($target);

    if ($targetMajor === '') {
        logMessage("Unknown target version: $target");
        return 1;
    }

    [$from, $next] = pmssDetermineUpgradePath($current);
    if ($from === null || $next === null) {
        logMessage('No upgrade recipe for Debian '.$current);
        return 0;
    }

    if ($targetMajor !== $next) {
        logMessage(sprintf('Safety halt: Current version is %s. The next logical upgrade is to %s, but you requested %s.', $current, $next, $targetMajor));
        logMessage('Skipping versions is not supported.');
        return 1;
    }

    // Debian 11 → 12 upgrades must shed the legacy WireGuard DKMS module. Newer
    // kernels ship WireGuard in-tree and the old wireguard-dkms package fails
    // with BUILD_EXCLUSIVE errors during kernel/headers configuration, leaving
    // linux-image/linux-headers packages half-configured.
    pmssRemoveLegacyWireguardDkms($from, $next);

    logMessage(sprintf('Initiating Debian %s → %s upgrade', $from, $next));
    pmssRewriteSources($from, $next);
    pmssExecuteUpgrade();
    return 0;
}

/**
 * Resolve a target string (number or codename) to a major version string.
 */
function pmssResolveTargetVersion(string $input): string
{
    $key = strtolower($input);
    if ($key === '') {
        return '';
    }

    if (pmssDistUpgradeIsAllowedMajor($key)) {
        return $key;
    }

    $mapped = pmssVersionFromCodename($key);
    if ($mapped === 0) {
        return '';
    }
    $mappedKey = (string) $mapped;
    return pmssDistUpgradeIsAllowedMajor($mappedKey) ? $mappedKey : '';
}

/**
 * Dist-upgrade currently supports Debian 10–13 only.
 *
 * Keep this strict allowlist stable so older codenames and unexpected numeric
 * strings remain rejected.
 */
function pmssDistUpgradeIsAllowedMajor(string $major): bool
{
    static $allowed = [
        '10' => true,
        '11' => true,
        '12' => true,
        '13' => true,
    ];

    return isset($allowed[$major]);
}

/**
 * Map current Debian version to the next supported release.
 */
function pmssDetermineUpgradePath(string $current): array
{
    static $map = [
        '10' => ['10', '11'],
        '11' => ['11', '12'],
        '12' => ['12', '13'],
    ];

    return $map[$current] ?? [null, null];
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

    static $paths = ['/etc/apt/sources.list', '/etc/apt/sources.list.d/*.list'];
    $sedPairs = [
        [sprintf("s/\\<%s\\>/%s/g", $from, $to), $paths[0]],
        [sprintf("s#%s/updates#%s-security#g", $to, $to), $paths[0]],
        [sprintf("s/\\<%s\\>/%s/g", $from, $to), $paths[1]],
        [sprintf("s#%s/updates#%s-security#g", $to, $to), $paths[1]],
    ];

    foreach ($sedPairs as [$expr, $path]) {
        runCommand("sed -i '{$expr}' {$path}");
    }

    // Ensure security repository uses the live security host after upgrade.
    // Older Buster hosts may have been pointed at archive.debian.org; that host
    // does not serve bullseye-security. Rewrite any archived security entries
    // to security.debian.org explicitly.
    static $rewritePairs = [
        // Prefer the live security host after upgrade.
        "sed -i -E 's@https?://archive\\.debian\\.org/debian-security@http://security.debian.org/debian-security@g' %s",
        // Prefer active mirrors for the main archive after the upgrade.
        "sed -i -E 's@https?://archive\\.debian\\.org/debian@http://deb.debian.org/debian@g' %s",
    ];
    foreach ($rewritePairs as $cmdFormat) {
        foreach ($paths as $path) {
            runCommand(sprintf($cmdFormat, $path));
        }
    }
}

/**
 * Execute the apt dist-upgrade sequence in noninteractive mode.
 */
function pmssExecuteUpgrade(): void
{
    $env = 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none UCF_FORCE_CONFDEF=1 UCF_FORCE_CONFOLD=1 NEEDRESTART_MODE=a';
    $opts = '-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';

    // Update package lists
    runCommand("$env apt-get update", true);

    // Upgrade packages (step 1)
    pmssRunUpgradeWithRecovery(
        "$env apt-get upgrade -y $opts",
        $env,
        'dist-upgrade: upgrade failed, attempting recovery (dpkg --configure -a, apt-get -f install)'
    );

    // Dist-upgrade (step 2)
    pmssRunUpgradeWithRecovery(
        "$env apt-get full-upgrade -y $opts",
        $env,
        'dist-upgrade: full-upgrade failed, attempting recovery'
    );

    // Autoremove residuals
    runCommand("$env apt-get autoremove -y", true);
}

/**
 * Wrap an apt action with the standard dpkg/apt recovery sequence.
 *
 * Keep the recovery ordering and the exact log messages stable; operators and
 * tests rely on these markers when dist-upgrades wedge dpkg.
 */
function pmssRunUpgradeWithRecovery(string $command, string $env, string $recoveryMessage): void
{
    $rc = runCommand($command, true);
    if ($rc === 0) {
        return;
    }

    logMessage($recoveryMessage);
    runCommand('dpkg --configure -a', true);
    runCommand("$env apt-get -f install -y", true);
    runCommand("$env apt-get update", true);
    runCommand($command, true);
}

/**
 * Remove legacy WireGuard DKMS module before certain upgrades.
 *
 * Debian 11 → 12 hosts often carry an old wireguard-dkms (e.g. 1.0.20210219)
 * which cannot build against 6.1 kernels due to BUILD_EXCLUSIVE guards. That
 * breaks linux-image/linux-headers postinst scripts and wedges dpkg until the
 * DKMS state is cleared. This helper purges the package and removes its DKMS
 * entries prior to the dist-upgrade so kernel configuration can complete.
 */
function pmssRemoveLegacyWireguardDkms(string $fromMajor, string $toMajor): void
{
    if ($fromMajor !== '11' || $toMajor !== '12') {
        return;
    }

    logMessage('dist-upgrade: removing legacy wireguard-dkms before Debian 11 → 12 upgrade');
    $env = 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none';

    // Best-effort purge; ignore failure if package is already absent.
    runCommand("$env apt-get purge -y wireguard-dkms", true);
    // Clean up DKMS registrations for the legacy module version; tolerate absence.
    runCommand('dkms remove wireguard/1.0.20210219 --all || true', true);
}

/**
 * Translate Debian major version to codename.
 */
function pmssCodenameForMajor(string $major): string
{
    if (!pmssDistUpgradeIsAllowedMajor($major)) {
        return '';
    }

    return pmssDebianCodenameFromMajor((int) $major);
}
