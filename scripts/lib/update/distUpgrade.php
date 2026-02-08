<?php
/**
 * Debian distribution upgrade helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update.php';
require_once __DIR__.'/distro.php';

/**
 * Entry point used by util/update-dist-upgrade.php.
 */
function pmssRunDistUpgrade(?string $maxTarget = null): int
{
    requireRoot();

    if ($maxTarget === null) {
        logMessage('Safety error: You must explicitly specify the maximum Debian major version (e.g., 11 or bullseye).');
        logMessage('Usage: scripts/util/update-dist-upgrade.php <maxTarget>');
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

    // Debian 11 → 12 upgrades must shed the legacy WireGuard DKMS module. Newer
    // kernels ship WireGuard in-tree and the old wireguard-dkms package fails
    // with BUILD_EXCLUSIVE errors during kernel/headers configuration, leaving
    // linux-image/linux-headers packages half-configured.
    pmssRemoveLegacyWireguardDkms($from, $next);

    logMessage(sprintf('Initiating Debian %s → %s upgrade', $from, $next));
    pmssRewriteSources($from, $next);
    pmssExecuteUpgrade();
    pmssEnsureFuseOverlayfsAfterDistUpgrade($next);
    return 0;
}

/**
 * Ensure fuse-overlayfs is present after dist-upgrade so rootless Docker keeps working.
 *
 * Best-effort only: dist-upgrade must not fail solely because this package is missing
 * for the current suite/architecture.
 */
function pmssEnsureFuseOverlayfsAfterDistUpgrade(string $toMajor): void
{
    // Rootless Docker is only supported on Debian releases that PMSS targets.
    if ((int) $toMajor < 11) {
        return;
    }

    // Only install if at least one user has rootless Docker configured.
    // The daemon.json in user's .config/docker/ indicates rootless Docker setup.
    $dockerConfigs = glob('/home/*/.config/docker/daemon.json');
    if (empty($dockerConfigs)) {
        logMessage('[SKIP] dist-upgrade: no rootless Docker configs found; skipping fuse-overlayfs');
        return;
    }

    $status = trim((string) @shell_exec("dpkg-query -W -f='\\${Status}' fuse-overlayfs 2>/dev/null"));
    if ($status === 'install ok installed') {
        logMessage('[SKIP] dist-upgrade: fuse-overlayfs already installed');
        return;
    }

    $availableRc = runCommand('apt-cache show fuse-overlayfs >/dev/null 2>&1');
    if ($availableRc !== 0) {
        $arch = trim((string) @shell_exec('dpkg --print-architecture 2>/dev/null'));
        $archLabel = $arch !== '' ? (' (arch='.$arch.')') : '';
        logMessage('[WARN] dist-upgrade: fuse-overlayfs not available in apt cache'.$archLabel);
        return;
    }

    $interactiveRequested = getenv('PMSS_DIST_UPGRADE_INTERACTIVE') === '1';
    $hasTty = $interactiveRequested
        && function_exists('posix_isatty')
        && posix_isatty(STDIN)
        && posix_isatty(STDOUT)
        && posix_isatty(STDERR);
    $frontend = $hasTty ? 'readline' : 'noninteractive';
    $inheritTty = $hasTty;
    $env = 'DEBIAN_FRONTEND='.$frontend.' APT_LISTCHANGES_FRONTEND=none UCF_FORCE_CONFDEF=1 UCF_FORCE_CONFOLD=1 NEEDRESTART_MODE=a';
    $opts = '-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';

    logMessage('dist-upgrade: ensuring fuse-overlayfs is installed for rootless Docker');
    $rc = runCommand("$env apt-get install -y $opts fuse-overlayfs", true, null, $inheritTty);
    if ($rc !== 0) {
        logMessage('[WARN] dist-upgrade: failed to install fuse-overlayfs; rootless Docker may fail or fall back to a slower storage driver');
    }
}

/**
 * Determine the safe, single-step dist-upgrade action for the current Debian major,
 * capped at the requested maximum.
 *
 * @return array{action:string, from:?string, to:?string, message:string}
 */
function pmssResolveDistUpgradeStep(string $currentMajor, string $maxMajor): array
{
    [$from, $next] = pmssDetermineUpgradePath($currentMajor);
    if ($from === null || $next === null) {
        return [
            'action'  => 'noop',
            'from'    => null,
            'to'      => null,
            'message' => 'No upgrade recipe for Debian '.$currentMajor,
        ];
    }

    $currentMajorInt = (int) $currentMajor;
    $maxMajorInt     = (int) $maxMajor;

    if ($currentMajorInt > $maxMajorInt) {
        return [
            'action'  => 'error',
            'from'    => $from,
            'to'      => null,
            'message' => sprintf('Safety halt: Current version is %s but the requested maximum is %s.', $currentMajor, $maxMajor),
        ];
    }
    if ($currentMajorInt === $maxMajorInt) {
        return [
            'action'  => 'noop',
            'from'    => $from,
            'to'      => null,
            'message' => sprintf('No dist-upgrade required: current version is %s and requested maximum is %s.', $currentMajor, $maxMajor),
        ];
    }

    if ((int) $next > $maxMajorInt) {
        return [
            'action'  => 'error',
            'from'    => $from,
            'to'      => null,
            'message' => sprintf('Safety halt: Current version is %s. The next logical upgrade is to %s, but your maximum is %s.', $currentMajor, $next, $maxMajor),
        ];
    }

    return [
        'action'  => 'upgrade',
        'from'    => $from,
        'to'      => $next,
        'message' => $maxMajor !== $next
            ? sprintf('Requested maximum is %s; performing safe incremental upgrade to %s.', $maxMajor, $next)
            : '',
    ];
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

    $mapped = (string) pmssVersionFromCodename($key);
    return pmssDistUpgradeIsAllowedMajor($mapped) ? $mapped : '';
}

/**
 * Dist-upgrade currently supports Debian 10–13 only.
 *
 * Keep this strict allowlist stable so older codenames and unexpected numeric
 * strings remain rejected.
 */
function pmssDistUpgradeIsAllowedMajor(string $major): bool
{
    static $allowed = ['10' => true, '11' => true, '12' => true, '13' => true];
    return isset($allowed[$major]);
}

/**
 * Map current Debian version to the next supported release.
 */
function pmssDetermineUpgradePath(string $current): array
{
    static $map = ['10' => ['10', '11'], '11' => ['11', '12'], '12' => ['12', '13']];
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
 * Execute the apt dist-upgrade sequence.
 *
 * Default behaviour is noninteractive (safe for automation). Operators may set
 * `PMSS_DIST_UPGRADE_INTERACTIVE=1` when running from a real TTY to allow debconf
 * prompts during the upgrade.
 */
function pmssExecuteUpgrade(): void
{
    $interactiveRequested = getenv('PMSS_DIST_UPGRADE_INTERACTIVE') === '1';
    $hasTty = $interactiveRequested
        && function_exists('posix_isatty')
        && posix_isatty(STDIN)
        && posix_isatty(STDOUT)
        && posix_isatty(STDERR);
    if ($interactiveRequested && !$hasTty) {
        logMessage('[WARN] PMSS_DIST_UPGRADE_INTERACTIVE=1 requested, but no TTY detected; continuing in noninteractive mode.');
    }
    $frontend = $hasTty ? 'readline' : 'noninteractive';
    $inheritTty = $hasTty;
    $env = 'DEBIAN_FRONTEND='.$frontend.' APT_LISTCHANGES_FRONTEND=none UCF_FORCE_CONFDEF=1 UCF_FORCE_CONFOLD=1 NEEDRESTART_MODE=a';
    $opts = '-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';

    // Update package lists
    runCommand("$env apt-get update", true, null, $inheritTty);

    // Upgrade packages (step 1)
    pmssRunUpgradeWithRecovery(
        "$env apt-get upgrade -y $opts",
        $env,
        'dist-upgrade: upgrade failed, attempting recovery (dpkg --configure -a, apt-get -f install)',
        $inheritTty
    );

    // Dist-upgrade (step 2)
    pmssRunUpgradeWithRecovery(
        "$env apt-get full-upgrade -y $opts",
        $env,
        'dist-upgrade: full-upgrade failed, attempting recovery',
        $inheritTty
    );

    // Autoremove residuals
    runCommand("$env apt-get autoremove -y", true, null, $inheritTty);
}

/**
 * Wrap an apt action with the standard dpkg/apt recovery sequence.
 *
 * Keep the recovery ordering and the exact log messages stable; operators and
 * tests rely on these markers when dist-upgrades wedge dpkg.
 */
function pmssRunUpgradeWithRecovery(string $command, string $env, string $recoveryMessage, bool $inheritTty = false): void
{
    if (runCommand($command, true, null, $inheritTty) === 0) {
        return;
    }

    logMessage($recoveryMessage);
    runCommand('dpkg --configure -a', true, null, $inheritTty);
    runCommand("$env apt-get -f install -y", true, null, $inheritTty);
    runCommand("$env apt-get update", true, null, $inheritTty);
    runCommand($command, true, null, $inheritTty);
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
    return pmssDistUpgradeIsAllowedMajor($major) ? pmssDebianCodenameFromMajor((int) $major) : '';
}
