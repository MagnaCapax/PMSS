<?php
/**
 * Rootless Docker and linger helpers for per-user update maintenance.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__).'/distro.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once dirname(__DIR__, 2).'/user/identity.php';
require_once dirname(__DIR__, 2).'/user/log.php';
require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';
require_once dirname(__DIR__, 2).'/user/userConfigStore.php';

/** Keep shell-facing user maintenance helpers behind the username policy. */
function pmssUserMaintenanceUsernameAllowed(string $user, string $context): bool
{
    if (pmssValidateUsername($user)) {
        return true;
    }

    $safeUser = preg_replace('/[\r\n\0]+/', '?', $user);
    $safeUser = is_string($safeUser) && $safeUser !== '' ? $safeUser : '(empty)';
    logMessage(sprintf('[WARN] %s refused invalid username: %s', $context, $safeUser));
    return false;
}

/** Run a shell command and copy stdout/stderr/rc into the user's log. */
function pmssRunAndLog(string $user, string $label, string $command, bool $asUser = false): int
{
    if (!pmssUserMaintenanceUsernameAllowed($user, 'pmssRunAndLog')) {
        return 127;
    }

    $inner = $asUser ? pmssBuildUserShellCommand($user, $command) : $command;
    pmssUserLog($user, "[CMD] {$label}: {$command}");
    $result = pmssCommandCapture($inner, 0, true, 'Failed to start process', 127);
    if ((int) $result['rc'] === 127 && $result['stdout'] === '' && $result['stderr'] === 'Failed to start process') {
        pmssUserLog($user, "[ERR] Failed to start process for: {$label}");
        return 127;
    }

    $stdout = rtrim((string) $result['stdout']);
    $stderr = rtrim((string) $result['stderr']);
    if ($stdout !== '') pmssUserLog($user, $stdout);
    if ($stderr !== '') pmssUserLog($user, $stderr);
    pmssUserLog($user, sprintf('[RC] %s -> %d', $label, (int) $result['rc']));
    return (int) $result['rc'];
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
    if (!pmssUserDockerEnabled($user, $userConfigStore)) {
        pmssUserLog($user, '[SKIP] Docker disabled by config; stopping rootless Docker if running');
        pmssRunAndLog($user, 'userDocker stop (disabled)', sprintf('php /scripts/util/userDocker.php %s stop', escapeshellarg($user)));
        return;
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
        echo "\033[36m[LINGER/DOCKER] {$user}\033[0m".PHP_EOL;
    } else {
        logMessage('[LINGER/DOCKER] '.$user);
    }
    pmssRunAndLog($user, 'loginctl enable-linger', 'loginctl enable-linger '.escapeshellarg($user));
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

/** Read the first ExecStart binary from a rootless Docker unit. */
function pmssUserDockerUnitExecBinary(string $unit): ?string
{
    $lines = @file($unit, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        $trim = pmssConfigLineTrimmed($line, ['#', ';']);
        if ($trim === '' || strpos($trim, 'ExecStart=') !== 0) {
            continue;
        }

        $command = trim(substr($trim, strlen('ExecStart=')));
        if ($command !== '' && $command[0] === '-') {
            $command = ltrim(substr($command, 1));
        }
        $parts = $command === '' ? [] : preg_split('/\s+/', $command);
        return $parts && $parts[0] !== '' ? trim($parts[0], "\"'") : null;
    }

    return null;
}

/** Run dockerd-rootless-setuptool.sh when the user's docker.service is missing or stale. */
function pmssEnsureRootlessDockerInstalled(string $user): void
{
    if (!pmssUserMaintenanceUsernameAllowed($user, 'pmssEnsureRootlessDockerInstalled')) {
        return;
    }

    $uinfo = pmssUserAccountLookup($user);
    if ($uinfo === null || !isset($uinfo['dir'])) {
        pmssUserLog($user, '[WARN] Unable to resolve passwd entry; skipping rootless Docker install');
        return;
    }

    $home = $uinfo['dir'];
    $unit = $home.'/.config/systemd/user/docker.service';
    if (is_file($unit)) {
        $execBinary = pmssUserDockerUnitExecBinary($unit);
        if ($execBinary !== null && is_file($execBinary)) {
            pmssUserLog($user, '[SKIP] Rootless Docker systemd unit already present');
            return;
        }

        pmssUserLog($user, '[WARN] Rootless Docker unit appears stale ('.($execBinary === null ? 'missing ExecStart' : 'missing ExecStart binary '.$execBinary).'); removing to allow reinstall');
        $realHome = realpath($home);
        $realUnit = realpath($unit);
        if ($realHome === false || $realUnit === false || strpos($realUnit, $realHome.'/') !== 0) {
            pmssUserLog($user, '[WARN] Refusing to remove docker.service outside expected home path');
            return;
        }
        if (!@unlink($unit)) {
            pmssUserLog($user, '[WARN] Failed to remove stale docker.service; skipping reinstall');
            return;
        }
    }

    // Manager-independent rootless-Docker install (ADR-0027): run the local setuptool AS the
    // user (pmssRunAndLog $asUser=true wraps via su) with XDG_RUNTIME_DIR set, NOT via the
    // per-user-manager container-shell path which needs a working per-user systemd manager
    // (fails under cgroup v2 + hidepid=2, systemd issue 12955). Converged onto the local
    // setuptool instead of the upstream network installer (reproducible + sovereign; matches
    // userConfigRuntime). Daemon launched separately via nohup dockerd-rootless.sh.
    $installCmd = 'export XDG_RUNTIME_DIR=/run/user/$(id -u); export PATH=$PATH:/usr/sbin:/sbin; /usr/bin/dockerd-rootless-setuptool.sh install';
    pmssRunAndLog($user, 'rootless Docker setuptool install', $installCmd, true);
    clearstatcache();
    pmssUserLog($user, is_file($unit)
        ? '[OK] Rootless Docker unit docker.service created for user'
        : '[WARN] Rootless Docker install script completed but docker.service is still missing');
}

/** Cache fuse-overlayfs usability once per process. */
function pmssUserDockerFuseOverlayfsState(): array
{
    static $state = null;
    if ($state !== null) {
        return $state;
    }

    $path = null;
    foreach (['/usr/bin/fuse-overlayfs', '/usr/local/bin/fuse-overlayfs'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $path = $candidate;
            break;
        }
    }
    $available = false;
    if ($path !== null) {
        $out = [];
        $rc = 1;
        @exec(escapeshellarg($path).' --version >/dev/null 2>&1', $out, $rc);
        $available = ($rc === 0);
    }

    return $state = ['available' => $available, 'path' => $path];
}

/** Verify subid and daemon.json storage-driver state for rootless Docker. */
function pmssEnsureDockerDependencies(string $user): void
{
    if (!pmssUserMaintenanceUsernameAllowed($user, 'pmssEnsureDockerDependencies')) {
        return;
    }

    $subuid = @file_get_contents('/etc/subuid');
    $subgid = @file_get_contents('/etc/subgid');
    if ($subuid === false || strpos($subuid, $user.':') === false) pmssUserLog($user, '[WARN] User missing from /etc/subuid; rootless Docker may fail.');
    if ($subgid === false || strpos($subgid, $user.':') === false) pmssUserLog($user, '[WARN] User missing from /etc/subgid; rootless Docker may fail.');
    $distroVersion = pmssDistroVersionFromEnv();
    if ($distroVersion <= 0) {
        pmssUserLog($user, '[WARN] PMSS_DISTRO_VERSION missing; skipping Docker storage-driver enforcement');
        return;
    }

    $uinfo = pmssUserAccountLookup($user);
    if ($uinfo === null) {
        return;
    }

    $fuse = pmssUserDockerFuseOverlayfsState();
    $home = $uinfo['dir'];
    $hasConfigFile = is_file($home.'/.config/docker/daemon.json');
    $result = pmssUserRootlessDockerConfigConverge($user, $home, (int) $uinfo['uid'], (int) $uinfo['gid'], [
        'storage_driver' => $fuse['available'] ? 'fuse-overlayfs' : null,
        'remove_pmss_storage_driver' => !$fuse['available'],
        'create_when_missing' => $fuse['available'],
        'invalid_json_as_empty' => true,
        'preserve_custom_storage_driver' => true,
        'disable_containerd_snapshotter' => $fuse['available'] && $distroVersion <= 11,
    ]);
    if (!$result['ok']) {
        $messages = ['ensure_dir_failed' => '[WARN] Failed to ensure ~/.config/docker', 'encode_failed' => '[WARN] Failed to encode daemon.json', 'write_failed' => '[WARN] Failed to write daemon.json'];
        pmssUserLog($user, $messages[$result['reason']] ?? '[WARN] Failed to converge daemon.json');
        return;
    }
    if (!$fuse['available'] && !$hasConfigFile) {
        pmssUserLog($user, sprintf('[WARN] fuse-overlayfs %s; skipping storage-driver enforcement', $fuse['path'] === null ? 'missing' : 'unusable'));
    }
    if ($result['removed_storage_driver']) {
        pmssUserLog($user, '[WARN] Removed daemon.json storage-driver because fuse-overlayfs is unavailable');
    }
    if ($result['configured_storage_driver']) {
        pmssUserLog($user, '[INFO] Configured Docker storage-driver: fuse-overlayfs');
    }
    if ($result['disabled_containerd_snapshotter']) {
        pmssUserLog($user, '[INFO] Disabled Docker containerd image store for rootless fuse-overlayfs');
    }
}
