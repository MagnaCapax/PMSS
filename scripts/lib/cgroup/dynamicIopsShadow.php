<?php
/**
 * Bounded allocation inputs and the private enforce-cycle log; the apply cron writes the caps.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/dynamicIopsPolicy.php';
require_once __DIR__.'/ioCeilingHistory.php';
require_once __DIR__.'/dynamicIopsContention.php';
require_once __DIR__.'/dynamicIopsProbe.php';
require_once __DIR__.'/readLimitStatus.php';

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

/** Collect once per enforce cycle; read-only previews never publish, record or write caps. */
function pmssDynamicIopsShadowCycle(array $users, array $policy, ?array &$result = null, bool $readOnly = false, bool $cohortComplete = true): bool
{
    $result = null;
    $settings = pmssDynamicIopsSettings($policy);
    if ($settings === null) return ($policy['cgroup']['dynamicIops']['mode'] ?? null) === 'off';
    if ($settings['mode'] === 'off') return true;
    $readOnly = $readOnly || pmssEnvFlagEnabled('PMSS_DRY_RUN');
    $now = time();
    $device = pmssCgroupPolicyMountSourceResolve('/home');
    $md = pmssCgroupPolicyHomeDeviceIsMdBacked($device);
    $class = $md ? null : pmssCgroupPolicyHomeStorageClassResolve($device);
    $statePath = pmssResolvePathFromEnv('PMSS_IO_CEILING_STATE_PATH', PMSS_IO_CEILING_STATE_PATH_DEFAULT);
    $state = pmssJsonFileReadAssoc($statePath, true);
    $stamp = is_string($state['computed_at'] ?? null) ? strtotime($state['computed_at']) : false;
    $legacyPassive = pmssIoCeilingPublishedReadIops($statePath);
    $passive = $stamp !== false && $stamp <= $now && $stamp >= $now - 86400 ? $legacyPassive : null;
    $majMin = $device !== '' ? pmssCgroupPolicyDeviceMajorMinorResolve($device) : null;
    $contention = $majMin === null ? ['rows' => [], 'current' => null, 'held' => []]
        : pmssDynamicIopsContentionObserve($users, $majMin, $now, $readOnly);
    // Stall of accounts held at their own cap is not contention; scale host samples to what remains.
    $pressure = pmssDynamicIopsPressure(pmssDynamicIopsContentionScale(
        pmssDynamicIopsHistory('/var/log/pmss/iostat-history.log'), $contention['rows']), $now);
    // Solid state retains its previous multiplier; md members decide the media.
    $steerPath = pmssResolvePathFromEnv('PMSS_DYNAMIC_IOPS_STEER_STATE', '/var/run/pmss/dynamic-iops-steer.json');
    $steer = pmssJsonFileReadAssoc($steerPath, true) ?? [];
    $then = $steer['time'] ?? null;
    $pressure['previous_multiplier'] = is_int($then) && $then < $now && $now - $then <= 900
        ? pmssFiniteNonNegativeFloat($steer['multiplier'] ?? null) : null;
    $pressure['media'] = $class ?? pmssCgroupPolicyHomeStorageClassResolve($device) ?? 'hdd';
    // Solid state steers against the group median or its own quiet floor (ADR 0237); a stale floor does not count.
    $fleetCompare = pmssJsonFileReadAssoc(pmssResolvePathFromEnv('PMSS_FLEET_COMPARE_PATH', '/var/lib/pmss/public/fleetCompare.json'), true);
    $ownFloor = $passive !== null ? pmssFiniteNonNegativeFloat($state['ioping_floor_ms'] ?? null) : null;
    $pressure['latency_reference_ms'] = pmssDynamicIopsLatencyReference($fleetCompare, $ownFloor, $now);
    $allowed = !$md && pmssCgroupPolicyReadIopsDerivationAllowed($legacyPassive, pmssCgroupPolicyReadIopsClassFloor($class, $policy), count($users));
    $benchmark = pmssDynamicIopsBenchmark('/var/log/pmss/benchmark-storage.jsonl', $device);
    $result = pmssDynamicIopsAllocate($users, $policy, $pressure, $passive, $benchmark, $class, $allowed, $legacyPassive);
    $probe = null;
    $probePath = pmssResolvePathFromEnv('PMSS_DYNAMIC_IOPS_PROBE_STATE', '/var/lib/pmss/dynamic-iops-probe.json');
    if ($pressure['media'] === 'hdd') {
        // Check the existing allocation guards before accepting work; fallback cycles cannot form pairs.
        $probe = pmssDynamicIopsProbeCycle(pmssJsonFileReadAssoc($probePath, true), $settings,
            $contention['counts'] ?? [], $now, (string) gethostname(), ($pressure['md_sync'] ?? false) === true,
            $cohortComplete && pmssDynamicIopsReadCaps($users, $policy, $result) !== null);
        $pressure['probe_multiplier'] = $probe['multiplier'];
        $result = pmssDynamicIopsAllocate($users, $policy, $pressure, $passive, $benchmark, $class, $allowed, $legacyPassive);
        if ($result !== null) $result['probe'] = $probe['probe'];
    }
    if ($result === null) $result = ['fallback' => 'allocation-failed'];
    // A partial config scan cannot safely redistribute the full host pool.
    if (!$cohortComplete) $result['fallback'] = 'incomplete-account-configs';
    $result['read_caps'] = pmssDynamicIopsReadCaps($users, $policy, $result);
    if ($result['read_caps'] === null && $result['fallback'] === null) $result['fallback'] = 'invalid-read-cap-map';
    $result['read_action'] = $result['read_caps'] === null ? 'keep-kernel' : 'apply';
    $result['contention'] = $contention['current'];
    if ($readOnly) return true;
    if ($probe !== null && pmssEnsureSafeDir(dirname($probePath), 0755)) {
        pmssAtomicJsonFileWrite($probePath, $probe['state'], 0600);
    }
    // Only a multiplier that reached the kernel is the next cycle's starting point.
    if ($result['read_action'] === 'apply' && ($result['steer'] ?? null) === 'latency' && pmssEnsureSafeDir(dirname($steerPath), 0755)) {
        pmssAtomicJsonFileWrite($steerPath, ['time' => $now, 'multiplier' => $result['multiplier']], 0600);
    }
    // The panel shows the delivered limit and a notice while held (ADR 0232).
    if ($result['read_caps'] !== null) pmssReadLimitStatusPublish($result['read_caps'], $contention['held'], $now);
    $payload = pmssJsonEncodeSafe(['schema_version' => 2, 'timestamp' => gmdate('c', $now),
        'event' => 'dynamic_iops_enforce', 'mode' => $settings['mode'], 'md_enforcement_excluded' => false,
        'settings' => $settings] + $result);
    if ($payload === null) return false;
    return pmssRunSnapshotLogTask(__FILE__, 'PMSS_DYNAMIC_IOPS_LOG', '/var/log/pmss/dynamic-iops.jsonl',
        static function ($handle) use ($payload): int { return pmssStreamWriteAll($handle, $payload."\n") ? 0 : 1; }) === 0;
}
