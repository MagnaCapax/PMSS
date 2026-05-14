<?php
/**
 * IOPS monthly budget enforcement helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/userConfigCli.php';
require_once __DIR__.'/userConfigStore.php';
require_once __DIR__.'/iopsLimit.php';

function pmssIopsLimitThrottleIops(): int
{
    return 100;
}

function pmssIopsLimitMarkerPath(string $username, ?string $runtimeDir = null): string
{
    $root = pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime');
    return rtrim($root, '/').'/iopsLimitEnforced/'.$username;
}

function pmssIopsLimitResourceStatsPath(string $username, ?string $runtimeDir = null): string
{
    $root = pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime');
    return rtrim($root, '/').'/resourceStats/'.$username;
}

function pmssIopsLimitBuildThrottleCommand(string $username, int $iops): string
{
    return pmssBuildCommand('php', [
        '/scripts/util/userConfigCgroup.php',
        $username,
        '--apply',
        '--device=/home',
        '--io-read-iops=/home:'.$iops,
        '--io-write-iops=/home:'.$iops,
    ]);
}

function pmssIopsLimitBuildRestoreCommand(string $username, array $payload): ?string
{
    $memory = isset($payload['ramMiB']) && is_numeric($payload['ramMiB']) ? (int) $payload['ramMiB'] : 0;
    if ($memory <= 0) {
        return null;
    }

    $user = ['name' => $username, 'memory' => $memory];
    foreach (pmssUserConfigCliPersistedStoredResources($payload) as $key => $value) {
        $user[$key] = $value;
    }

    $args = [
        '/scripts/util/userConfigCgroup.php',
        $username,
        '--apply',
        '--memory-high='.(string) $memory,
    ];
    $args = array_merge($args, pmssUserConfigCliBuildCgroupResourceArgs($user));
    if (isset($user['cpuQuotaPercent']) && $user['cpuQuotaPercent'] !== '') {
        $args[] = '--cpu-quota-percent='.(string) $user['cpuQuotaPercent'];
    }

    return pmssBuildCommand('php', ['/scripts/util/userConfigCgroup.php', $username, '--apply', '--wipe'])
        .' && '
        .pmssBuildCommand('php', $args);
}

/** @return array{action:string,limit:int,usage:int} */
function pmssIopsLimitEnforcementPlan(int $limit, int $usage, bool $markerExists): array
{
    $overLimit = $limit > 0 && $usage > $limit;
    if ($overLimit && !$markerExists) {
        return ['action' => 'enforce', 'limit' => $limit, 'usage' => $usage];
    }
    if (!$overLimit && $markerExists) {
        return ['action' => 'restore', 'limit' => $limit, 'usage' => $usage];
    }
    return ['action' => 'none', 'limit' => $limit, 'usage' => $usage];
}

function pmssIopsLimitWriteMarker(string $path): bool
{
    return pmssIopsLimitEnsureStorageDir(dirname($path))
        && function_exists('pmssAtomicWriteFile')
        && pmssAtomicWriteFile($path, (string) time(), 0600)
        && pmssIopsLimitConvergeFileMode($path, 0600);
}

function pmssIopsLimitRemoveMarker(string $path): bool
{
    if (!file_exists($path)) {
        return true;
    }
    if (is_link($path) || !is_file($path)) {
        return false;
    }
    return @unlink($path);
}

function pmssIopsLimitsRun(): int
{
    $users = pmssListManagedUsers('/scripts/listUsers.php');
    $store = new UserConfigStore();

    foreach ($users as $user) {
        $limit = pmssIopsLimitReadOperationsFile(pmssIopsLimitRuntimePath($user));
        $usage = pmssReadUserMonthlyIopsUsage(pmssIopsLimitResourceStatsPath($user));
        $markerPath = pmssIopsLimitMarkerPath($user);
        $markerExists = is_file($markerPath) && !is_link($markerPath);
        $plan = pmssIopsLimitEnforcementPlan($limit, $usage, $markerExists);

        if ($plan['action'] === 'enforce') {
            $rc = runStep(
                'Applying monthly IOPS throttle for '.$user,
                pmssIopsLimitBuildThrottleCommand($user, pmssIopsLimitThrottleIops())
            );
            if ($rc === 0) {
                pmssIopsLimitWriteMarker($markerPath);
                function_exists('pmssUserLog') && pmssUserLog($user, sprintf('monthly IOPS throttle enabled (limit=%d usage=%d cap=%d)', $limit, $usage, pmssIopsLimitThrottleIops()));
            }
            continue;
        }

        if ($plan['action'] === 'restore') {
            $payload = $store->applyFallbacks($user, $store->get($user) ?? []);
            $command = pmssIopsLimitBuildRestoreCommand($user, $payload);
            if ($command === null) {
                function_exists('pmssUserLog') && pmssUserLog($user, '[WARN] IOPS throttle restore skipped: missing RAM baseline');
                continue;
            }

            $rc = runStep('Restoring baseline cgroup IOPS for '.$user, $command);
            if ($rc === 0) {
                pmssIopsLimitRemoveMarker($markerPath);
                function_exists('pmssUserLog') && pmssUserLog($user, sprintf('monthly IOPS throttle removed (limit=%d usage=%d)', $limit, $usage));
            }
        }
    }

    return 0;
}
