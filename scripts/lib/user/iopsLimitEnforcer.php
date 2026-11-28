<?php
/**
 * IOPS monthly budget enforcement helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
pmssRequireRelativeFiles(__DIR__, ['../userLifecycle.php', 'userConfigCli.php', 'userConfigStore.php', 'iopsLimit.php', 'iopsLimitAction.php', '../cgroup/dynamicIopsPolicy.php']);

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

/**
 * The user-log line states only what was applied. Usage is the last 7 days scaled to 30; on cgroup v1 the
 * held read cap is written by cgroupIopsLimitApply.php, so $budgetCap is set only where that kernel can hold it.
 */
function pmssIopsLimitLogLine(string $action, bool $clamp, int $limit, int $usage, int $cap, ?int $budgetCap = null, int $holdUntil = 0): string
{
    if ($action === 'enforce') {
        if ($clamp) {
            return sprintf('monthly IOPS throttle enabled (limit=%d usage=%d cap=%d)', $limit, $usage, $cap);
        }
        return $budgetCap === null
            ? sprintf('monthly IOPS budget exceeded (limit=%d usage=%d); no read cap on this kernel', $limit, $usage)
            : sprintf('monthly IOPS budget exceeded (limit=%d usage=%d); /home read cap %d IOPS, held at least until %s',
                $limit, $usage, $budgetCap, gmdate('Y-m-d H:i', $holdUntil).' UTC');
    }
    return $clamp
        ? sprintf('monthly IOPS throttle removed (limit=%d usage=%d)', $limit, $usage)
        : sprintf('monthly IOPS usage back within budget (limit=%d usage=%d)', $limit, $usage);
}

/**
 * An enforced account is released only once it is back within budget and the marker is PMSS_IOPS_BUDGET_HOLD_SECONDS
 * old; a budget removed (0) releases at once.
 * @return array{action:string,limit:int,usage:int}
 */
function pmssIopsLimitEnforcementPlan(int $limit, int $usage, bool $markerExists, int $markerAge = PHP_INT_MAX): array
{
    $overLimit = $limit > 0 && $usage > $limit;
    $action = 'none';
    if ($overLimit && !$markerExists) {
        $action = 'enforce';
    } elseif (!$overLimit && $markerExists && ($limit <= 0 || $markerAge >= PMSS_IOPS_BUDGET_HOLD_SECONDS)) {
        $action = 'restore';
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
    $kernelReady = !$clamp && pmssDynamicIopsKernelReady();
    $now = time();

    foreach ($users as $user) {
        $limit = pmssIntegerSettingFileRead(pmssIopsLimitRuntimePath($user), 'pmssIopsLimitParseMonthlyOperations');
        $usage = pmssIopsBudgetUsage(pmssRuntimeDir().'/resourceStats/'.$user);
        $markerPath = pmssIopsBudgetMarkerPath($user);
        $markerTime = pmssIopsBudgetMarkerTime($markerPath);
        $plan = pmssIopsLimitEnforcementPlan($limit, $usage, $markerTime !== null, $markerTime === null ? 0 : $now - $markerTime);
        if ($plan['action'] === 'none') {
            continue;
        }

        $restoreCommand = $plan['action'] !== 'restore' ? null
            : pmssIopsLimitBuildRestoreCommand($user, $store->applyFallbacks($user, $store->get($user) ?? []));
        pmssIopsLimitApplyPlan($user, $plan, $clamp, $kernelReady ? pmssIopsBudgetReadCap($limit) : null, $now, $restoreCommand, [
            'run' => function ($label, $command) {
                return runStep($label, $command);
            },
            'markerWrite' => function () use ($markerPath, $now) {
                return pmssIntegerSettingStorageDirEnsure(dirname($markerPath), 0700)
                    && function_exists('pmssAtomicWriteFile')
                    && pmssAtomicWriteFile($markerPath, (string) $now, 0600)
                    && pmssIntegerSettingPathModeConverge($markerPath, 0600);
            },
            'markerRemove' => function () use ($markerPath) {
                return pmssIntegerSettingFileRemove($markerPath);
            },
            'log' => function ($line) use ($user) {
                pmssUserLog($user, $line);
            },
        ]);
    }

    return 0;
}
