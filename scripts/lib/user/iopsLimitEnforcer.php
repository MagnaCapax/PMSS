<?php
/**
 * IOPS monthly budget enforcement helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/userConfigCli.php';
require_once __DIR__.'/userConfigStore.php';
require_once __DIR__.'/iopsLimit.php';

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

function pmssIopsLimitsRun(): int
{
    $users = pmssListManagedUsers('/scripts/listUsers.php');
    $store = new UserConfigStore();

    foreach ($users as $user) {
        $limit = pmssIntegerSettingFileRead(pmssIopsLimitRuntimePath($user), 'pmssIopsLimitParseMonthlyOperations');
        $usage = pmssReadUserMonthlyIopsUsage(pmssIntegerSettingRuntimeUserPath('resourceStats', $user));
        $markerPath = pmssIntegerSettingRuntimeUserPath('iopsLimitEnforced', $user);
        $markerExists = is_file($markerPath) && !is_link($markerPath);
        $plan = pmssIopsLimitEnforcementPlan($limit, $usage, $markerExists);

        if ($plan['action'] === 'enforce') {
            $throttleIops = 100;
            $rc = runStep(
                'Applying monthly IOPS throttle for '.$user,
                pmssIopsLimitBuildThrottleCommand($user, $throttleIops)
            );
            if ($rc === 0) {
                pmssIntegerSettingStorageDirEnsure(dirname($markerPath), 0700)
                    && function_exists('pmssAtomicWriteFile')
                    && pmssAtomicWriteFile($markerPath, (string) time(), 0600)
                    && pmssIntegerSettingPathModeConverge($markerPath, 0600);
                pmssUserLog($user, sprintf('monthly IOPS throttle enabled (limit=%d usage=%d cap=%d)', $limit, $usage, $throttleIops));
            }
            continue;
        }

        if ($plan['action'] === 'restore') {
            $payload = $store->applyFallbacks($user, $store->get($user) ?? []);
            $command = pmssIopsLimitBuildRestoreCommand($user, $payload);
            if ($command === null) {
                pmssUserLog($user, '[WARN] IOPS throttle restore skipped: missing RAM baseline');
                continue;
            }

            $rc = runStep('Restoring baseline cgroup IOPS for '.$user, $command);
            if ($rc === 0) {
                pmssIntegerSettingFileRemove($markerPath);
                pmssUserLog($user, sprintf('monthly IOPS throttle removed (limit=%d usage=%d)', $limit, $usage));
            }
        }
    }

    return 0;
}
