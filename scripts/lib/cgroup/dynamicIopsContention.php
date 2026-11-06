<?php
/**
 * Host I/O contention without the stall that read caps cause themselves.
 *
 * Tasks waiting in the blkio throttle count as I/O stall, so an account held at
 * its own cap raises host PSI and iowait on an idle disk. Account slices in the
 * unified hierarchy keep their own io.pressure; the stall outside held accounts
 * is the contention the pool responds to.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/directApply.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

const PMSS_DYNAMIC_IOPS_CONTENTION_STATE_DEFAULT = '/var/run/pmss/dynamic-iops-contention.json';
const PMSS_CGROUP_UNIFIED_ROOT = '/sys/fs/cgroup/unified';

/** Full-stall 300-second average from a PSI file; null when absent or malformed. */
function pmssPsiFullAvg300(string $path): ?float
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || preg_match('/^full .*\bavg300=([0-9]+(?:\.[0-9]+)?)\b/m', $raw, $m) !== 1) return null;
    $value = (float) $m[1];
    return $value <= 100 ? $value : null;
}

/** Cumulative read count of one device from a cgroup-v1 io_serviced file. */
function pmssBlkioReadCount(string $path, string $majMin): ?int
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || preg_match('/^'.preg_quote($majMin, '/').' Read ([0-9]+)$/m', $raw, $m) !== 1) return null;
    return pmssUnsignedDecimalIntParse($m[1]);
}

/** An account is held when it read at 90% or more of the rate its cap delivers. */
function pmssDynamicIopsSliceHeld(?int $cap, ?int $before, ?int $after, int $seconds): bool
{
    if ($cap === null || $cap <= 0 || $before === null || $after === null || $after < $before || $seconds < 60) return false;
    return ($after - $before) / $seconds >= 0.9 * max(1, 10 * intdiv($cap, 10));
}

/** Host full stall, or with any account held, the worst stall outside held accounts. */
function pmssDynamicIopsContention(float $host, ?float $system, array $slices): float
{
    $held = false; $free = $system ?? 0.0;
    foreach ($slices as $slice) {
        if ($slice['held']) { $held = true; continue; }
        if ($slice['full'] !== null) $free = max($free, $slice['full']);
    }
    return $held ? min($host, $free) : $host;
}

/** Measure this cycle and keep one hour of rows; nothing is recorded without the unified hierarchy. */
function pmssDynamicIopsContentionObserve(array $users, string $majMin, int $now, bool $readOnly,
    string $statePath = PMSS_DYNAMIC_IOPS_CONTENTION_STATE_DEFAULT, string $unifiedRoot = PMSS_CGROUP_UNIFIED_ROOT,
    string $hostPressure = '/proc/pressure/io'): array
{
    $state = pmssJsonFileReadAssoc($statePath, true) ?? [];
    $rows = array_values(array_filter(is_array($state['rows'] ?? null) ? $state['rows'] : [],
        static function ($row) use ($now): bool { return is_array($row) && is_int($row['time'] ?? null) && $row['time'] > $now - 3600 && $row['time'] <= $now; }));
    $host = pmssPsiFullAvg300($hostPressure);
    if ($host === null || !is_dir($unifiedRoot.'/user.slice')) return ['rows' => $rows, 'current' => null];
    $then = is_int($state['time'] ?? null) && $state['time'] < $now ? $state['time'] : null;
    $counts = []; $slices = [];
    foreach (array_keys($users) as $user) {
        $errors = 0;
        if (($uid = pmssCgroupDirectUserUidOrError((string) $user, $errors)) === null) continue;
        $dir = pmssCgroupDirectUserSliceDir($uid);
        $counts[$uid] = pmssBlkioReadCount($dir.'/blkio.throttle.io_serviced', $majMin);
        $cap = pmssCgroupDirectDeviceValueParse((string) @file_get_contents($dir.'/blkio.throttle.read_iops_device'), $majMin);
        $before = is_int($state['counts'][$uid] ?? null) ? $state['counts'][$uid] : null;
        $slices[] = ['full' => pmssPsiFullAvg300("$unifiedRoot/user.slice/user-$uid.slice/io.pressure"),
            'held' => $then !== null && pmssDynamicIopsSliceHeld($cap, $before, $counts[$uid], $now - $then)];
    }
    $current = ['time' => $now, 'host' => $host, 'held' => count(array_filter(array_column($slices, 'held'))),
        'contention' => pmssDynamicIopsContention($host, pmssPsiFullAvg300("$unifiedRoot/system.slice/io.pressure"), $slices)];
    $rows[] = $current;
    if (!$readOnly && pmssEnsureSafeDir(dirname($statePath), 0755)) {
        pmssAtomicJsonFileWrite($statePath, ['time' => $now, 'counts' => $counts, 'rows' => $rows], 0600);
    }
    return ['rows' => $rows, 'current' => $current];
}

/** Scale each five-minute host sample by the contention share measured within half a bucket of it. */
function pmssDynamicIopsContentionScale(iterable $samples, array $rows): iterable
{
    foreach ($samples as $sample) {
        $ratio = 1.0; $best = 151;
        foreach ($rows as $row) {
            $host = pmssFiniteNonNegativeFloat($row['host'] ?? null);
            $contention = pmssFiniteNonNegativeFloat($row['contention'] ?? null);
            if (!is_array($sample) || !is_int($sample['time'] ?? null) || $host === null || $contention === null) continue;
            $gap = abs($row['time'] - $sample['time']);
            if ($gap >= $best) continue;
            $best = $gap;
            $ratio = $host > 0 ? min(1.0, $contention / $host) : 1.0;
        }
        if ($ratio < 1.0) {
            foreach (['psiFullAvg300', 'cpuIowaitPct'] as $field) {
                $value = pmssFiniteNonNegativeFloat($sample[$field] ?? null);
                if ($value !== null) $sample[$field] = $value * $ratio;
            }
        }
        yield $sample;
    }
}
