<?php
/**
 * Bounded allocation inputs and private cycle output, with no throttle writer.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/dynamicIopsPolicy.php';
require_once __DIR__.'/ioCeilingHistory.php';

/** Tail complete records only, rejecting redirected paths and substituted inodes. */
function pmssDynamicIopsTailLines(string $path): array
{
    if (!pmssPathTargetIsSafe($path, false, true) || !pmssUserFileStatIsSingleRegular($stat = @lstat($path))) return [];
    $handle = @fopen($path, 'rbn');
    if ($handle === false) return [];
    try {
        if (!pmssLockFileHandleMatchesPath($handle, $path, $stat, $opened)) return [];
        $offset = max(0, $opened['size'] - 65536);
        if (fseek($handle, $offset) !== 0) return [];
        $raw = stream_get_contents($handle, 65536);
        if (!is_string($raw)) return [];
        $lines = explode("\n", $raw);
        if ($offset > 0) array_shift($lines);
        array_pop($lines); // A growing log's final partial record is never evidence.
        return $lines;
    } finally {
        fclose($handle);
    }
}

/** Decode the existing serialized iostat records without instantiating classes. */
function pmssDynamicIopsHistory(string $path): iterable
{
    foreach ([$path.'.1', $path] as $file) {
        foreach (pmssDynamicIopsTailLines($file) as $line) {
            if (strlen($line) > 8192 || strpos($line, ' || ') !== 19) continue;
            if (($row = pmssSerializedArrayDecode(substr($line, 23))) !== null) yield $row;
        }
    }
}

/** Use only the latest successful 4k random-read measurement of this mount device. */
function pmssDynamicIopsBenchmark(string $path, string $homeDevice): ?float
{
    $latest = null; $time = 0;
    foreach ([$path.'.1', $path] as $file) {
        foreach (pmssDynamicIopsTailLines($file) as $line) {
            $row = pmssJsonDecodeAssoc($line);
            if ($row === null || ($row['ok'] ?? null) !== true || ($row['device'] ?? null) !== $homeDevice
                || !in_array($row['test'] ?? null, ['home-device-randread-4k', 'randread-small'], true)) continue;
            $stamp = is_string($row['run_ts'] ?? null) ? strtotime($row['run_ts']) : false;
            $value = pmssFiniteNonNegativeFloat($row['metrics']['read_iops'] ?? null);
            if ($stamp !== false && $stamp >= $time && $stamp <= time() && $value !== null && $value > 0) {
                $time = $stamp; $latest = $value;
            }
        }
    }
    return $latest;
}

/** Collect once for shadow/enforce; read-only previews never publish or write caps. */
function pmssDynamicIopsShadowCycle(array $users, array $policy, ?array &$result = null, bool $readOnly = false, bool $cohortComplete = true): bool
{
    $result = null;
    $settings = pmssDynamicIopsSettings($policy);
    if ($settings === null) return ($policy['cgroup']['dynamicIops']['mode'] ?? null) !== 'enforce';
    if ($settings['mode'] === 'off') return true;
    $readOnly = $readOnly || pmssEnvFlagEnabled('PMSS_DRY_RUN');
    if ($settings['mode'] === 'shadow' && $readOnly) return true;
    $now = time();
    $device = pmssCgroupPolicyMountSourceResolve('/home');
    $md = pmssCgroupPolicyHomeDeviceIsMdBacked($device);
    $class = $md ? null : pmssCgroupPolicyHomeStorageClassResolve($device);
    $statePath = pmssResolvePathFromEnv('PMSS_IO_CEILING_STATE_PATH', PMSS_IO_CEILING_STATE_PATH_DEFAULT);
    $state = pmssJsonFileReadAssoc($statePath, true);
    $stamp = is_string($state['computed_at'] ?? null) ? strtotime($state['computed_at']) : false;
    $legacyPassive = pmssIoCeilingPublishedReadIops($statePath);
    $passive = $stamp !== false && $stamp <= $now && $stamp >= $now - 86400 ? $legacyPassive : null;
    $pressure = pmssDynamicIopsPressure(pmssDynamicIopsHistory('/var/log/pmss/iostat-history.log'), $now);
    $allowed = !$md && pmssCgroupPolicyReadIopsDerivationAllowed($legacyPassive, pmssCgroupPolicyReadIopsClassFloor($class, $policy), count($users));
    $result = pmssDynamicIopsAllocate($users, $policy, $pressure, $passive,
        pmssDynamicIopsBenchmark('/var/log/pmss/benchmark-storage.jsonl', $device), $class, $allowed, $legacyPassive);
    if ($result === null) {
        if ($settings['mode'] !== 'enforce') return false;
        $result = ['fallback' => 'allocation-failed'];
    }
    if ($settings['mode'] === 'enforce') {
        // A partial config scan cannot safely redistribute the full host pool.
        if (!$cohortComplete) $result['fallback'] = 'incomplete-account-configs';
        $result['read_caps'] = pmssDynamicIopsReadCaps($users, $policy, $result);
        if ($result['read_caps'] === null && $result['fallback'] === null) $result['fallback'] = 'invalid-read-cap-map';
        $result['read_action'] = $result['read_caps'] === null ? 'keep-kernel' : 'apply';
    }
    if ($readOnly) return true;
    $payload = pmssJsonEncodeSafe(['schema_version' => 2, 'timestamp' => gmdate('c', $now),
        'event' => $settings['mode'] === 'shadow' ? 'dynamic_iops_shadow' : 'dynamic_iops_enforce',
        'mode' => $settings['mode'], 'md_enforcement_excluded' => $md && $settings['mode'] !== 'enforce', 'settings' => $settings] + $result);
    if ($payload === null) return false;
    return pmssRunSnapshotLogTask(__FILE__, 'PMSS_DYNAMIC_IOPS_LOG', '/var/log/pmss/dynamic-iops.jsonl',
        static function ($handle) use ($payload): int { return pmssStreamWriteAll($handle, $payload."\n") ? 0 : 1; }) === 0;
}
