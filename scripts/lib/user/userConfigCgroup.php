<?php
/** Pure resource-to-command projections; callers retain validation and execution. @license GPL-3.0-only */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/userConfigResourceSpecs.php';

/** @return array<int,string> Render sparse userConfig positionals. */
function pmssUserConfigCliBuildUserConfigPositionals(array $user): array
{
    $optionalArgs = array_fill(4, 9, '');
    $lastOptionalIndex = 3;
    foreach (pmssUserConfigCliResourceSpecs('userConfigIndex') as $key => $spec) {
        $index = $spec['userConfigIndex'];
        $optionalArgs[$index] = (string) ($user[$key] ?? '');
        if ($optionalArgs[$index] !== '') {
            $lastOptionalIndex = $index;
        }
    }
    return array_slice($optionalArgs, 0, max(0, $lastOptionalIndex - 3));
}

/** @return array<int,string> Build canonical userConfigCgroup.php apply arguments. */
function pmssUserConfigCliBuildCgroupApplyArgs(string $username, int $memoryMiB, array $user): array
{
    $args = ['/scripts/util/userConfigCgroup.php', $username, '--apply', '--memory-high='.(string) $memoryMiB];
    foreach (pmssUserConfigCliResourceSpecs('cgroupFlag') as $key => $spec) {
        if (!empty($user[$key])) {
            $args[] = $spec['cgroupFlag'].$user[$key];
        } elseif (pmssUserConfigCliCgroupIoClearRequested($user, $key)) {
            $args[] = $spec['cgroupFlag'].'/home:max';
        }
    }
    if (isset($user['cpuQuotaPercent']) && $user['cpuQuotaPercent'] !== '') {
        $args[] = '--cpu-quota-percent='.(string) $user['cpuQuotaPercent'];
    }
    return $args;
}

/** Return true when an explicit stored IOPS zero/blank should clear a stale systemd throttle. */
function pmssUserConfigCliCgroupIoClearRequested(array $user, string $key): bool
{
    if (!in_array($key, ['IOReadIOPS', 'IOWriteIOPS'], true) || !array_key_exists($key, $user) || $user[$key] === null || !is_scalar($user[$key])) {
        return false;
    }

    $value = trim((string) $user[$key]);
    return $value === '' || $value === '0';
}

/** @return array<int,string>|null Build cgroup apply args from a stored payload. */
function pmssUserConfigCliBuildStoredCgroupApplyArgs(string $username, array $payload): ?array
{
    $memory = is_numeric($payload['ramMiB'] ?? null) ? (int) $payload['ramMiB'] : 0;
    if ($memory <= 0) {
        return null;
    }

    return pmssUserConfigCliBuildCgroupApplyArgs($username, $memory, pmssUserConfigCliPersistedStoredResources($payload));
}

/** Build the PHP command for a stored plan; a missing RAM baseline remains a skip. */
function pmssUserConfigCliBuildStoredCgroupApplyCommand(string $username, array $payload): ?string
{
    $args = pmssUserConfigCliBuildStoredCgroupApplyArgs($username, $payload);
    return $args === null ? null : pmssBuildCommand('php', $args);
}
