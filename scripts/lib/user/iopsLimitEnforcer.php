<?php
/**
 * IOPS monthly budget enforcement helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
pmssRequireRelativeFiles(__DIR__, ['../userLifecycle.php', 'userConfigCli.php', 'userConfigStore.php', 'iopsLimit.php']);

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
    $applyCommand = pmssUserConfigCliBuildStoredCgroupApplyCommand($username, $payload);
    if ($applyCommand === null) {
        return null;
    }

    return pmssBuildCommand('php', ['/scripts/util/userConfigCgroup.php', $username, '--apply', '--wipe'])
        .' && '
        .$applyCommand;
}

/**
 * systemd applies IOReadIOPSMax/IOWriteIOPSMax only on the unified hierarchy, and on cgroup v1 the
 * account read cap belongs to cgroupIopsLimitApply.php, which rewrites it every run (#1307).
 */
function pmssIopsLimitClampApplies(string $cgroupMode): bool
{
    return $cgroupMode === 'v2';
}

/** The user-log line states only what was applied. */
function pmssIopsLimitLogLine(string $action, bool $clamp, int $limit, int $usage, int $cap): string
{
    if ($action === 'enforce') {
        return $clamp
            ? sprintf('monthly IOPS throttle enabled (limit=%d usage=%d cap=%d)', $limit, $usage, $cap)
            : sprintf('monthly IOPS budget exceeded (limit=%d usage=%d); no IOPS throttle on cgroup v1', $limit, $usage);
    }
    return $clamp
        ? sprintf('monthly IOPS throttle removed (limit=%d usage=%d)', $limit, $usage)
        : sprintf('monthly IOPS usage back within budget (limit=%d usage=%d)', $limit, $usage);
}

/** @return array{action:string,limit:int,usage:int} */
function pmssIopsLimitEnforcementPlan(int $limit, int $usage, bool $markerExists): array
{
    $overLimit = $limit > 0 && $usage > $limit;
    $action = 'none';
    if ($overLimit !== $markerExists) {
        $action = $overLimit ? 'enforce' : 'restore';
    }
    return ['action' => $action, 'limit' => $limit, 'usage' => $usage];
}

function pmssIopsLimitsRun(): int
{
    // A failed account scan is not an empty fleet; let cron report the failure.
    $users = pmssListManagedUsersFromResult(pmssListManagedUsersResult('/scripts/listUsers.php'));
    if ($users === null) {
        return 1;
    }
    $store = new UserConfigStore();
    $clamp = pmssIopsLimitClampApplies(pmssCgroupModeWithDefault('v1'));

    foreach ($users as $user) {
        $limit = pmssIntegerSettingFileRead(pmssIopsLimitRuntimePath($user), 'pmssIopsLimitParseMonthlyOperations');
        $usage = pmssReadUserMonthlyIopsUsage(pmssRuntimeDir().'/resourceStats/'.$user);
        $markerPath = pmssIntegerSettingRuntimeUserPath('iopsLimitEnforced', $user);
        $markerExists = pmssRegularFilePathIsReadable($markerPath);
        $plan = pmssIopsLimitEnforcementPlan($limit, $usage, $markerExists);

        if ($plan['action'] === 'enforce') {
            $throttleIops = 100;
            $rc = !$clamp ? 0 : runStep(
                'Applying monthly IOPS throttle for '.$user,
                pmssIopsLimitBuildThrottleCommand($user, $throttleIops)
            );
            if ($rc === 0) {
                pmssIntegerSettingStorageDirEnsure(dirname($markerPath), 0700)
                    && function_exists('pmssAtomicWriteFile')
                    && pmssAtomicWriteFile($markerPath, (string) time(), 0600)
                    && pmssIntegerSettingPathModeConverge($markerPath, 0600);
                pmssUserLog($user, pmssIopsLimitLogLine('enforce', $clamp, $limit, $usage, $throttleIops));
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
                pmssUserLog($user, pmssIopsLimitLogLine('restore', $clamp, $limit, $usage, 0));
            }
        }
    }

    return 0;
}
