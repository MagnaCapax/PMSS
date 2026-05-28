<?php
/**
 * Debian distribution upgrade helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update.php';
require_once __DIR__.'/distro.php';
require_once __DIR__.'/userMaintenance.php';

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

    // Debian 11 → 12 upgrades must shed the legacy WireGuard DKMS module. Newer
    // kernels ship WireGuard in-tree and the old wireguard-dkms package fails
    // with BUILD_EXCLUSIVE errors during kernel/headers configuration, leaving
    // linux-image/linux-headers packages half-configured.
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

/** Build the apt environment and interactive flag for dist-upgrade commands. */
function pmssDistUpgradeAptEnv(bool $warnWhenInteractiveUnavailable = true): array
{
    $interactiveRequested = pmssEnvFlagEnabled('PMSS_DIST_UPGRADE_INTERACTIVE');
    $hasTty = $interactiveRequested && pmssStandardStreamsAreTty();
    if ($warnWhenInteractiveUnavailable && $interactiveRequested && !$hasTty) {
        logMessage('[WARN] PMSS_DIST_UPGRADE_INTERACTIVE=1 requested, but no TTY detected; continuing in noninteractive mode.');
    }

    return ['DEBIAN_FRONTEND='.($hasTty ? 'readline' : 'noninteractive')
        .' APT_LISTCHANGES_FRONTEND=none UCF_FORCE_CONFDEF=1 UCF_FORCE_CONFOLD=1 NEEDRESTART_MODE=a', $hasTty];
}

/** Build apt commands with dist-upgrade's shared force-conf policy. */
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
    return trim($env.' '.$aptAction.' -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold'.($suffix !== '' ? ' '.$suffix : ''));
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
    if (empty(glob('/home/*/.config/docker/daemon.json'))) {
        logMessage('[SKIP] dist-upgrade: no rootless Docker configs found; skipping fuse-overlayfs');
        return;
    }

    $status = trim((string) @shell_exec('dpkg-query -W -f=\'${Status}\' fuse-overlayfs 2>/dev/null'));
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

    list($env, $hasTty) = pmssDistUpgradeAptEnv(false);

    logMessage('dist-upgrade: ensuring fuse-overlayfs is installed for rootless Docker');
    $rc = runCommand(pmssDistUpgradeAptCommand($env, 'install', 'fuse-overlayfs'), true, null, $hasTty);
    if ($rc !== 0) {
        logMessage('[WARN] dist-upgrade: failed to install fuse-overlayfs; rootless Docker may fail or fall back to a slower storage driver');
    }
}

/**
 * Repair rootless Docker state for existing users after a dist-upgrade.
 */
function pmssRepairDockerRootlessAfterDistUpgrade(string $toMajor): void
{
    if ((int) $toMajor < 11) {
        return;
    }

    if ((string) getenv('PMSS_DISTRO_VERSION') === '') {
        putenv('PMSS_DISTRO_VERSION='.(string) ((int) $toMajor));
    }

    $users = users::listHomeUsers();
    sort($users, SORT_NATURAL | SORT_FLAG_CASE);
    $targets = [];

    foreach ($users as $user) {
        $userTrim = trim($user);
        if ($userTrim === '') {
            continue;
        }
        if (!pmssValidateUsername($userTrim)) {
            logMessage(sprintf('[WARN] dist-upgrade: skipping invalid username: %s', $userTrim));
            continue;
        }

        $home = '/home/'.$userTrim;
        if (!is_dir($home)) {
            continue;
        }
        if (is_dir($home.'/www-disabled')) {
            pmssUserLog($userTrim, '[SKIP] dist-upgrade: user appears suspended; skipping rootless Docker repair');
            continue;
        }

        $configFile = $home.'/.config/docker/daemon.json';
        $unitFile   = $home.'/.config/systemd/user/docker.service';
        if (!is_file($configFile) && !is_file($unitFile)) {
            continue;
        }
        $targets[] = $userTrim;
    }

    if (empty($targets)) {
        logMessage('[SKIP] dist-upgrade: no rootless Docker configs found; skipping rootless repair');
    } else {
        logMessage(sprintf('dist-upgrade: repairing rootless Docker for %d user(s)', count($targets)));
    }

    foreach ($targets as $user) {
        pmssUserLog($user, 'dist-upgrade: rootless Docker repair start');
        pmssEnsureRootlessDockerInstalled($user);
        pmssEnsureDockerDependencies($user);
    }

    if (is_file('/usr/bin/slirp4netns') && is_file('/usr/local/bin/slirp4netns')) {
        if (@unlink('/usr/local/bin/slirp4netns')) {
            logMessage('dist-upgrade: removed stale /usr/local/bin/slirp4netns');
        } else {
            logMessage('[WARN] dist-upgrade: failed to remove /usr/local/bin/slirp4netns');
        }
    }
}

/**
 * Ensure the newest kernel has a matching initrd before rebooting.
 */
function pmssEnsureInitrdAfterDistUpgrade(): void
{
    $kernelImages = glob('/boot/vmlinuz-*');
    if (empty($kernelImages)) {
        logMessage('[WARN] dist-upgrade: no kernel images found under /boot; skipping initrd check');
        return;
    }

    $versions = [];
    foreach ($kernelImages as $path) {
        $base = basename($path);
        if (strpos($base, 'vmlinuz-') !== 0) {
            continue;
        }
        $version = substr($base, strlen('vmlinuz-'));
        if ($version === '' || $version === 'old') {
            continue;
        }
        $versions[] = $version;
    }

    if (empty($versions)) {
        logMessage('[WARN] dist-upgrade: no usable kernel versions detected; skipping initrd check');
        return;
    }

    usort($versions, 'version_compare');
    $latest = end($versions);
    if ($latest === false || $latest === '') {
        logMessage('[WARN] dist-upgrade: unable to resolve newest kernel version; skipping initrd check');
        return;
    }

    $initrd = '/boot/initrd.img-'.$latest;
    if (file_exists($initrd)) {
        logMessage('[SKIP] dist-upgrade: initrd present for kernel '.$latest);
        return;
    }

    logMessage('dist-upgrade: initrd missing for kernel '.$latest.'; generating with update-initramfs');
    if (!pmssDistUpgradeWaitForLocksOrLog('[WARN] dist-upgrade: dpkg lock did not clear; skipping initrd generation')) {
        return;
    }
    if (runCommand('update-initramfs -c -k '.escapeshellarg($latest), true) !== 0) {
        logMessage('[WARN] dist-upgrade: update-initramfs failed for kernel '.$latest);
        return;
    }

    if (!file_exists($initrd)) {
        logMessage('[WARN] dist-upgrade: initrd still missing for kernel '.$latest.' after update-initramfs');
        return;
    }

    logMessage('dist-upgrade: initrd generated for kernel '.$latest);
}

/**
 * Verify common post-upgrade boot prerequisites and warn loudly when drift is
 * detected.
 *
 * These checks are intentionally non-fatal because the dist-upgrade flow does
 * not reboot automatically. Warnings are surfaced so operators can resolve
 * boot risk before scheduling the reboot window.
 */
function pmssVerifyDistUpgradeBootReadiness(
    ?string $mdstatPath = null,
    ?string $grubConfigPath = null,
    ?string $mdadmConfigPath = null,
    ?string $initramfsMdadmPath = null
): void
{
    $mdstatPath = $mdstatPath ?? '/proc/mdstat';
    $grubConfigPath = $grubConfigPath ?? '/boot/grub/grub.cfg';
    $mdadmConfigPath = $mdadmConfigPath ?? '/etc/mdadm/mdadm.conf';
    $initramfsMdadmPath = $initramfsMdadmPath ?? '/etc/initramfs-tools/conf.d/mdadm';

    pmssDistUpgradeVerifyReadablePattern($mdstatPath, '[WARN] dist-upgrade: unable to read '.$mdstatPath.'; RAID health check skipped', '/\[[U_]*_[U_]*\]/', true, '[WARN] dist-upgrade: degraded RAID array detected in '.$mdstatPath.'; inspect before reboot', '[SKIP] dist-upgrade: RAID arrays appear healthy');

    if (!is_file($grubConfigPath)) {
        logMessage('[WARN] dist-upgrade: missing '.$grubConfigPath.'; run update-grub before reboot');
    } else {
        $grubSize = @filesize($grubConfigPath);
        if ($grubSize === false || $grubSize < 1000) {
            logMessage('[WARN] dist-upgrade: grub config looks too small ('.(int) $grubSize.' bytes); verify '.$grubConfigPath);
        } else {
            logMessage('[SKIP] dist-upgrade: grub config present at '.$grubConfigPath);
        }
    }

    pmssDistUpgradeVerifyReadablePattern($mdadmConfigPath, '[WARN] dist-upgrade: unable to read '.$mdadmConfigPath.'; mdadm config check skipped', '/^\s*ARRAY\s+\S+/m', false, '[WARN] dist-upgrade: '.$mdadmConfigPath.' lacks ARRAY definitions; regenerate before reboot', '[SKIP] dist-upgrade: mdadm ARRAY definitions found');
    pmssDistUpgradeVerifyReadablePattern($initramfsMdadmPath, '[WARN] dist-upgrade: unable to read '.$initramfsMdadmPath.'; BOOT_DEGRADED verification skipped', '/^\s*BOOT_DEGRADED\s*=\s*true\s*$/mi', false, '[WARN] dist-upgrade: '.$initramfsMdadmPath.' missing BOOT_DEGRADED=true; degraded RAID boot may fail', '[SKIP] dist-upgrade: BOOT_DEGRADED=true is configured');
}

/**
 * Apply a readable-file boot check and emit the existing warning/skip message.
 */
function pmssDistUpgradeVerifyReadablePattern(
    string $path,
    string $unreadableMessage,
    string $pattern,
    bool $warnWhenMatched,
    string $warnMessage,
    string $skipMessage
): void
{
    if (!is_readable($path)) {
        logMessage($unreadableMessage);
        return;
    }

    $matched = preg_match($pattern, (string) @file_get_contents($path)) === 1;
    logMessage($matched === $warnWhenMatched ? $warnMessage : $skipMessage);
}

/**
 * Detect degraded md arrays from /proc/mdstat content.
 */
function pmssMdstatHasDegradedArrays(string $mdstat): bool
{
    return $mdstat !== '' && preg_match('/\[[U_]*_[U_]*\]/', $mdstat) === 1;
}

/**
 * Repair nginx ABI mismatches after dist-upgrade.
 */
function pmssRepairNginxAfterDistUpgrade(): void
{
    $nginxPath = pmssCommandPath('nginx');
    if ($nginxPath === '') {
        logMessage('[SKIP] dist-upgrade: nginx not installed; skipping ABI check');
        return;
    }

    $testRc = runCommand('nginx -t', true);
    if ($testRc === 0) {
        logMessage('[SKIP] dist-upgrade: nginx config test passed');
        return;
    }

    $last = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? ['stderr' => '', 'stdout' => ''];
    $combined = (string) ($last['stderr'] ?? '')."\n".(string) ($last['stdout'] ?? '');
    if (stripos($combined, 'module is not binary compatible') === false) {
        logMessage('[WARN] dist-upgrade: nginx -t failed, but no ABI mismatch detected; skipping reinstall');
        return;
    }

    logMessage('dist-upgrade: nginx ABI mismatch detected; purging and reinstalling nginx packages');
    if (!pmssDistUpgradeWaitForLocksOrLog('[WARN] dist-upgrade: dpkg lock did not clear; skipping nginx reinstall')) {
        return;
    }

    list($env, $hasTty) = pmssDistUpgradeAptEnv();

    runCommand("$env apt-get purge -y 'nginx*'", true, null, $hasTty);
    if (!pmssDistUpgradeWaitForLocksOrLog('[WARN] dist-upgrade: dpkg lock did not clear; skipping nginx reinstall')) {
        return;
    }
    if (runCommand(pmssDistUpgradeAptCommand($env, 'install', 'nginx nginx-full nginx-common'), true, null, $hasTty) !== 0) {
        logMessage('[WARN] dist-upgrade: nginx reinstall failed; leaving existing config in place');
        return;
    }

    runCommand('/scripts/util/createNginxConfig.php --restart', true, null, $hasTty);
    if (runCommand('nginx -t', true, null, $hasTty) !== 0) {
        logMessage('[WARN] dist-upgrade: nginx -t still failing after reinstall');
    }
}

/**
 * Build the normalized dist-upgrade plan shape used by callers and tests.
 *
 * @return array{action:string, from:?string, to:?string, message:string}
 */
function pmssDistUpgradePlan(string $action, ?string $from, ?string $to, string $message = ''): array
{
    return ['action' => $action, 'from' => $from, 'to' => $to, 'message' => $message];
}

/**
 * Determine the safe, single-step dist-upgrade action for the current Debian major,
 * capped at the requested maximum.
 *
 * @return array{action:string, from:?string, to:?string, message:string}
 */
function pmssResolveDistUpgradeStep(string $currentMajor, string $maxMajor): array
{
    $currentMajorInt = (int) $currentMajor;
    $maxMajorInt     = (int) $maxMajor;

    if ($currentMajorInt > $maxMajorInt) {
        return pmssDistUpgradePlan('error', $currentMajor, null, sprintf('Safety halt: Current version is %s but the requested maximum is %s.', $currentMajor, $maxMajor));
    }
    if ($currentMajorInt === $maxMajorInt) {
        return pmssDistUpgradePlan('noop', $currentMajor, null, sprintf('No dist-upgrade required: current version is %s and requested maximum is %s.', $currentMajor, $maxMajor));
    }

    if (!pmssDistUpgradeIsAllowedMajor($currentMajor) || $currentMajorInt >= 13) {
        return pmssDistUpgradePlan('noop', null, null, 'No upgrade recipe for Debian '.$currentMajor);
    }

    $from = (string) $currentMajorInt;
    $next = (string) ($currentMajorInt + 1);
    if ((int) $next > $maxMajorInt) {
        return pmssDistUpgradePlan('error', $from, null, sprintf('Safety halt: Current version is %s. The next logical upgrade is to %s, but your maximum is %s.', $currentMajor, $next, $maxMajor));
    }

    return pmssDistUpgradePlan(
        'upgrade',
        $from,
        $next,
        $maxMajor !== $next ? sprintf('Requested maximum is %s; performing safe incremental upgrade to %s.', $maxMajor, $next) : ''
    );
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

    $target = pmssDistUpgradeIsAllowedMajor($key) ? $key : (string) pmssVersionFromCodename($key);
    return pmssDistUpgradeIsAllowedMajor($target) ? $target : '';
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
 * Rewrite /etc/apt sources from one codename to another with security adjustments.
 */
function pmssRewriteSources(string $fromMajor, string $toMajor): void
{
    $from = pmssDistUpgradeIsAllowedMajor($fromMajor) ? pmssDebianCodenameFromMajor((int) $fromMajor) : '';
    $to   = pmssDistUpgradeIsAllowedMajor($toMajor) ? pmssDebianCodenameFromMajor((int) $toMajor) : '';
    if ($from === '' || $to === '') {
        logMessage('Unable to resolve codenames for upgrade path');
        return;
    }

    static $paths = ['/etc/apt/sources.list', '/etc/apt/sources.list.d/*.list'];
    $sedExpressions = [
        sprintf("s/\\<%s\\>/%s/g", $from, $to),
        sprintf("s#%s/updates#%s-security#g", $to, $to),
    ];
    foreach ($paths as $path) {
        foreach ($sedExpressions as $expr) {
            runCommand("sed -i '{$expr}' {$path}");
        }
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
 * Wait for dpkg/apt locks to clear before running apt commands.
 */
function pmssWaitForDpkgLocks(int $timeoutSeconds = 1800, int $sleepSeconds = 5): bool
{
    $fuser = pmssCommandPath('fuser');
    if ($fuser === '') {
        logMessage('[WARN] dist-upgrade: fuser not available; skipping dpkg lock checks');
        return true;
    }

    $paths = [
        '/var/lib/dpkg/lock-frontend',
        '/var/lib/dpkg/lock',
        '/var/lib/apt/lists/lock',
        '/var/cache/apt/archives/lock',
    ];

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

/**
 * Wait for dpkg locks and emit the caller's stable failure message on timeout.
 */
function pmssDistUpgradeWaitForLocksOrLog(string $message): bool
{
    if (pmssWaitForDpkgLocks()) {
        return true;
    }

    logMessage($message);
    return false;
}

/**
 * Check if any dpkg/apt lock files are currently held.
 */
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

/**
 * Execute the apt dist-upgrade sequence.
 *
 * Default behaviour is noninteractive (safe for automation). Operators may set
 * `PMSS_DIST_UPGRADE_INTERACTIVE=1` when running from a real TTY to allow debconf
 * prompts during the upgrade.
 */
function pmssExecuteUpgrade(): bool
{
    list($env, $hasTty) = pmssDistUpgradeAptEnv();

    if (!pmssDistUpgradeWaitForLocksOrLog('[ERROR] dist-upgrade: dpkg lock did not clear; aborting apt phase')) {
        return false;
    }

    runCommand("$env apt-get update", true, null, $hasTty);

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

    if (!pmssDistUpgradeWaitForLocksOrLog('[ERROR] dist-upgrade: dpkg lock did not clear; aborting apt autoremove')) {
        return false;
    }
    runCommand("$env apt-get autoremove -y", true, null, $hasTty);

    if (!pmssDistUpgradeWaitForLocksOrLog('[ERROR] dist-upgrade: dpkg lock did not clear; skipping dpkg --configure -a')) {
        return false;
    }
    runCommand('dpkg --configure -a', true, null, $hasTty);

    return true;
}

/**
 * Wrap an apt action with the standard dpkg/apt recovery sequence.
 *
 * Keep the recovery ordering and the exact log messages stable; operators and
 * tests rely on these markers when dist-upgrades wedge dpkg.
 */
function pmssRunUpgradeWithRecovery(string $command, string $env, string $recoveryMessage, bool $inheritTty = false): void
{
    if (!pmssDistUpgradeWaitForLocksOrLog('[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt action')) {
        return;
    }
    if (runCommand($command, true, null, $inheritTty) === 0) {
        return;
    }

    logMessage($recoveryMessage);
    foreach ([
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping dpkg recovery', 'dpkg --configure -a'],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt recovery', "$env apt-get -f install -y"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt update', "$env apt-get update"],
        ['[ERROR] dist-upgrade: dpkg lock did not clear; skipping apt retry', $command],
    ] as $recoveryStep) {
        if (!pmssDistUpgradeWaitForLocksOrLog($recoveryStep[0])) {
            return;
        }
        runCommand($recoveryStep[1], true, null, $inheritTty);
    }
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
 * Ensure libcrypt1 is installed before Debian 11 → 12 upgrades.
 */
function pmssEnsureLibcryptBeforeUpgrade(string $fromMajor, string $toMajor): void
{
    if ($fromMajor !== '11' || $toMajor !== '12') {
        return;
    }

    logMessage('dist-upgrade: ensuring libcrypt1 is installed before Debian 11 → 12 upgrade');
    if (!pmssDistUpgradeWaitForLocksOrLog('[WARN] dist-upgrade: dpkg lock did not clear; skipping libcrypt1 preinstall')) {
        return;
    }

    list($env, $hasTty) = pmssDistUpgradeAptEnv();

    runCommand("$env apt-get update", true, null, $hasTty);
    if (!pmssDistUpgradeWaitForLocksOrLog('[WARN] dist-upgrade: dpkg lock did not clear; skipping libcrypt1 install')) {
        return;
    }
    if (runCommand(pmssDistUpgradeAptCommand($env, 'install', 'libcrypt1'), true, null, $hasTty) !== 0) {
        logMessage('[WARN] dist-upgrade: libcrypt1 preinstall failed; continuing');
    }
}
