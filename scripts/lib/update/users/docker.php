<?php
/**
 * Rootless Docker and linger helpers for per-user update maintenance.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__).'/distro.php';
require_once dirname(__DIR__).'/packageState.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once dirname(__DIR__, 2).'/user/identity.php';
require_once dirname(__DIR__, 2).'/user/log.php';
require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';
require_once dirname(__DIR__, 2).'/user/userConfigStore.php';
pmssRequireRelativeFiles(__DIR__, ['dockerCommands.php', 'dockerInstall.php', 'dockerDependencies.php']);

/** Remove root-owned linger flags for names absent from passwd before enabling linger. */
function pmssUserMaintenancePruneStaleLinger(string $directory = '/var/lib/systemd/linger', ?callable $lookup = null, bool $dryRun = false, int $expectedOwner = 0): int
{
    if ($dryRun || pmssEnvFlagEnabled('PMSS_TEST_MODE') || pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        return 0;
    }
    $lookup = $lookup ?? 'posix_getpwnam';
    $directoryStat = @lstat($directory);
    if ($directoryStat === false || ($directoryStat['mode'] & 0170000) !== 0040000 || $directoryStat['uid'] !== $expectedOwner) {
        return 0;
    }
    $names = pmssDirectoryEntriesRead($directory);
    if ($names === false) {
        logMessage('[WARN] Unable to scan systemd linger directory');
        return 0;
    }

    // This root-owned systemd directory is outside account control (ADR 0121).
    $removed = 0;
    foreach ($names as $name) {
        if (!pmssValidateUsername($name)) {
            continue;
        }
        $path = $directory.'/'.$name;
        $stat = @lstat($path);
        if ($stat === false || $stat['uid'] !== $expectedOwner || !in_array($stat['mode'] & 0170000, [0100000, 0120000], true) || $lookup($name) !== false) {
            continue;
        }
        if (@unlink($path)) {
            $removed++;
        } else {
            logMessage('[WARN] Unable to remove stale systemd linger flag for '.pmssLogSafeLabel($name));
        }
    }
    return $removed;
}

/** Enable linger, refresh user@UID, then start or restart and check rootless Docker. */
function pmssEnsureLingerAndDocker(string $user): void
{
    if (!pmssUserMaintenanceUsernameAllowed($user, 'pmssEnsureLingerAndDocker')) {
        return;
    }
    if (is_dir("/home/{$user}/www-disabled")) {
        pmssUserLog($user, '[SKIP] User appears suspended; skipping linger/Docker wiring');
        return;
    }

    static $userConfigStore = null;
    $userConfigStore = $userConfigStore ?: new UserConfigStore();
    $dockerEnabled = pmssUserDockerEnabled($user, $userConfigStore);
    $lingerEnabled = pmssUserLingerEnabled($user, $userConfigStore);
    if (!$dockerEnabled) {
        pmssUserLog($user, '[SKIP] Docker disabled by config; stopping rootless Docker if running');
        pmssRunAndLog($user, 'userDocker stop (disabled)', sprintf('php /scripts/util/userDocker.php %s stop', escapeshellarg($user)));
        if (!$lingerEnabled) {
            return;
        }
    }
    if (!pmssSystemdRuntimeAvailable()) {
        pmssUserLog($user, '[SKIP] systemd not available on this host');
        return;
    }

    $uid = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
    if ($uid === '' || !ctype_digit($uid)) {
        pmssUserLog($user, '[WARN] Could not resolve UID');
        return;
    }

    pmssUserLog($user, sprintf('== Linger/Docker kick for %s (uid=%s) on host %s ==', $user, $uid, gethostname()));
    if (pmssStreamIsTty(STDOUT)) {
        echo pmssCliAnsiStyle('[LINGER/DOCKER] '.$user, '36').PHP_EOL;
    } else {
        logMessage('[LINGER/DOCKER] '.$user);
    }
    $lingerRc = pmssRunAndLog($user, 'loginctl enable-linger', 'loginctl enable-linger '.escapeshellarg($user));
    if (!$dockerEnabled) {
        pmssUserLog($user, $lingerRc === 0
            ? '[OK] Linger opt-in applied with rootless Docker disabled'
            : '[WARN] Linger opt-in failed with rootless Docker disabled');
        return;
    }
    pmssEnsureRootlessDockerInstalled($user);

    foreach ([
        ['systemctl status user@.service (pre)', 'systemctl --no-pager -l status user@'.$uid.'.service || true'],
        ['systemctl start user@.service', 'systemctl start user@'.$uid.'.service'],
        ['systemctl status user@.service (post)', 'systemctl --no-pager -l status user@'.$uid.'.service || true'],
    ] as $systemdCommand) {
        pmssRunAndLog($user, $systemdCommand[0], $systemdCommand[1]);
    }

    pmssEnsureDockerDependencies($user);
    $dockerAction = getenv('PMSS_DOCKER_PACKAGES_CHANGED') === '1' ? 'restart' : 'start';
    foreach ([$dockerAction, 'status'] as $action) {
        pmssRunAndLog($user, 'userDocker '.$action, sprintf('php /scripts/util/userDocker.php %s %s', escapeshellarg($user), $action));
    }
}
