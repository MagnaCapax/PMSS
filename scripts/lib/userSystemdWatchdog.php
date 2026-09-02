<?php
/**
 * Observe optional per-user systemd units without managing their lifecycle.
 *
 * PMSS itself does not depend on user@UID.service (ADR 0027). This helper only
 * reports when a linger-enabled account with its own units loses that manager.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/user/identity.php';
require_once __DIR__.'/user/log.php';

/** Return account-authored user services, excluding PMSS's Docker unit. */
function pmssUserSystemdWatchdogUnitNames(string $home): array
{
    $unitDir = rtrim($home, '/').'/.config/systemd/user';
    if (!is_dir($unitDir) || is_link($unitDir) || !pmssPathTargetIsSafe($unitDir, true)) return [];

    $units = [];
    foreach (glob($unitDir.'/*.service') ?: [] as $path) {
        $name = basename($path);
        if ($name !== 'docker.service' && is_file($path)) {
            $units[] = $name;
        }
    }
    sort($units, SORT_STRING);
    return $units;
}

/** Limit alerts to the production v1 path and accounts that declare intent. */
function pmssUserSystemdWatchdogShouldObserve(bool $lingerEnabled, array $unitNames, string $cgroupMode): bool
{
    return $lingerEnabled && $unitNames !== [] && $cgroupMode === 'v1';
}

/** Probe user@UID.service and normalize command failures to unknown. */
function pmssUserSystemdWatchdogManagerState(int $uid, ?callable $probe = null): string
{
    if ($uid <= 0) return 'unknown';
    $result = $probe !== null
        ? $probe($uid)
        : pmssCommandCapture(pmssBuildCommand('systemctl', ['is-active', 'user@'.$uid.'.service']), 10);
    if (!is_array($result)) return 'unknown';

    $output = strtolower(trim((string) ($result['stdout'] ?? '')));
    $rc = isset($result['rc']) ? (int) $result['rc'] : -1;
    if ($rc === 0 && $output === 'active') return 'active';
    return $rc === 3 && in_array($output, ['inactive', 'failed'], true) ? 'inactive' : 'unknown';
}

/** Build a snapshot; a second consecutive inactive probe completes the debounce. */
function pmssUserSystemdWatchdogSnapshot(string $username, int $unitCount, string $managerState, array $previous = []): array
{
    $previousInactive = ($previous['username'] ?? '') === $username
        && ($previous['restartPolicy'] ?? '') === 'observe-only'
        && ($previous['managerState'] ?? '') === 'inactive';
    $state = $managerState === 'active'
        ? 'healthy'
        : ($managerState === 'inactive' ? ($previousInactive ? 'degraded' : 'pending') : 'unknown');

    return [
        'timestamp' => date('c'),
        'username' => $username,
        'state' => $state,
        'managerState' => $managerState,
        'accountUnitCount' => max(0, $unitCount),
        'restartPolicy' => 'observe-only',
    ];
}

/** Resolve the reserved customer-readable status artifact path. */
function pmssUserSystemdWatchdogStatusPath(string $home): string
{
    $path = rtrim($home, '/').'/.systemd-user-status.json';
    return is_dir($home) && !is_link($home) && pmssPathTargetIsSafe($path, false, true)
        && pmssPathWithinResolvedRoot($path, $home) ? $path : '';
}

/** Atomically publish an observe-only status snapshot. */
function pmssUserSystemdWatchdogStatusWrite(string $home, string $path, array $status): bool
{
    if ($path === '' || !pmssPathTargetIsSafe($path, false, true) || !pmssPathWithinResolvedRoot($path, $home)) return false;
    $encoded = pmssJsonEncodePrettyLine($status);
    $temporary = @tempnam(dirname($path), '.systemd-user-status.');
    if (!is_string($encoded) || $temporary === false) return false;
    if (@file_put_contents($temporary, $encoded, LOCK_EX) === false || !@chmod($temporary, 0644) || !@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

/** Emit only actionable failures and recovery transitions. */
function pmssUserSystemdWatchdogLogTransition(string $username, array $status, array $previous): void
{
    $state = (string) ($status['state'] ?? 'unknown');
    $oldState = (string) ($previous['state'] ?? '');
    if ($state === $oldState || $state === 'pending'
        || ($state === 'healthy' && !in_array($oldState, ['degraded', 'unknown'], true))) {
        return;
    }

    $prefix = in_array($state, ['degraded', 'unknown'], true) ? '###PMSS_USER_SYSTEMD_ALERT ' : '';
    $message = $prefix.'User systemd watchdog: '.$username.' state='.$state.' manager='.(string) ($status['managerState'] ?? 'unknown');
    echo date('Y-m-d H:i:s').' '.$message.PHP_EOL;
    pmssUserLog($username, $message);
    if (function_exists('pmssLogJson')) {
        pmssLogJson(['event' => 'user_systemd_watchdog', 'user' => $username, 'state' => $state, 'manager_state' => $status['managerState'] ?? 'unknown']);
    }
}

/** Observe one account and publish state without restarting or enabling anything. */
function pmssUserSystemdWatchdogRunUser(
    string $username,
    string $homeRoot = '/home',
    string $lingerRoot = '/var/lib/systemd/linger',
    ?callable $probe = null,
    ?callable $accountLookup = null
): ?array {
    if (!pmssValidateUsername($username)) return null;
    $home = rtrim($homeRoot, '/').'/'.$username;
    $unitNames = pmssUserSystemdWatchdogUnitNames($home);
    $lingerEnabled = is_file(rtrim($lingerRoot, '/').'/'.$username);
    if (!pmssUserSystemdWatchdogShouldObserve($lingerEnabled, $unitNames, pmssCgroupMode())) return null;

    $account = $accountLookup !== null ? $accountLookup($username) : pmssUserAccountLookup($username);
    $uid = pmssPasswdEntryPositiveUid($account);
    $path = pmssUserSystemdWatchdogStatusPath($home);
    if ($path === '') return null;
    $previous = pmssJsonFileReadAssoc($path, true) ?? [];
    $status = pmssUserSystemdWatchdogSnapshot(
        $username,
        count($unitNames),
        $uid === null ? 'unknown' : pmssUserSystemdWatchdogManagerState($uid, $probe),
        $previous
    );
    if (!pmssUserSystemdWatchdogStatusWrite($home, $path, $status)) {
        echo date('Y-m-d H:i:s').' User systemd watchdog: unable to publish status for '.$username.PHP_EOL;
        return $status;
    }
    pmssUserSystemdWatchdogLogTransition($username, $status, $previous);
    return $status;
}
