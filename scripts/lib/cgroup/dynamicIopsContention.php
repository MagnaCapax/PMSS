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
/** Readers the throttle keeps waiting were measured getting 76-90% of their cap per cycle, not the full cap (#1249). */
const PMSS_DYNAMIC_IOPS_HELD_RATIO = 0.75;
/**
 * Contention leaves out accounts reading at half their cap or more: the throttle delivers whole tens
 * per 100 ms, so a bursty reader waits on its own cap well below its average limit (#1249).
 */
const PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO = 0.5;

/** Full-stall 300-second average from a PSI file; null when absent or malformed. */
function pmssPsiFullAvg300(string $path): ?float
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || preg_match('/^full .*\bavg300=([0-9]+(?:\.[0-9]+)?)\b/m', $raw, $m) !== 1) return null;
    $value = (float) $m[1];
    return $value <= 100 ? $value : null;
}

/** Cumulative reads (io_serviced) or read bytes (io_service_bytes) of one device from a cgroup-v1 file. */
function pmssBlkioReadCount(string $path, string $majMin): ?int
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || preg_match('/^'.preg_quote($majMin, '/').' Read ([0-9]+)$/m', $raw, $m) !== 1) return null;
    return pmssUnsignedDecimalIntParse($m[1]);
}

/** An account is held when it read at 75% (or $ratio) or more of the rate its cap delivers. */
function pmssDynamicIopsSliceHeld(?int $cap, ?int $before, ?int $after, int $seconds, float $ratio = PMSS_DYNAMIC_IOPS_HELD_RATIO): bool
{
    // Reject corrupt signed baselines before subtraction can overflow or invent reads.
    if ($cap === null || $cap <= 0 || $before === null || $before < 0
        || $after === null || $after < $before || $seconds < 60) return false;
    return ($after - $before) / $seconds >= $ratio * max(1, 10 * intdiv($cap, 10));
}

/**
 * UIDs held in the previous cycle, as a set. Full avg300 still carries about a third of
 * that cycle, so they stay out of contention one cycle more; a cycle older than 15 minutes counts for nothing.
 */
function pmssDynamicIopsHeldLastCycle(array $state, int $now): array
{
    $then = $state['time'] ?? null;
    if (!is_int($then) || $then < 0 || $then >= $now || $now - $then > 900 || !is_array($state['held'] ?? null)) return [];
    return array_fill_keys(array_filter($state['held'], static function ($uid): bool { return is_int($uid) && $uid > 0; }), true);
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

/** Read v1 counters once; keep contention rows only when unified pressure is available. */
function pmssDynamicIopsContentionObserve(array $users, string $majMin, int $now, bool $readOnly,
    string $statePath = PMSS_DYNAMIC_IOPS_CONTENTION_STATE_DEFAULT, string $unifiedRoot = PMSS_CGROUP_UNIFIED_ROOT,
    string $hostPressure = '/proc/pressure/io'): array
{
    $state = pmssJsonFileReadAssoc($statePath, true) ?? [];
    $rows = array_values(array_filter(is_array($state['rows'] ?? null) ? $state['rows'] : [],
        static function ($row) use ($now): bool { return is_array($row) && is_int($row['time'] ?? null) && $row['time'] > $now - 3600 && $row['time'] <= $now; }));
    $host = pmssPsiFullAvg300($hostPressure);
    // Read once even without unified PSI: the HDD probe needs only v1 service counters.
    // The HDD probe weighs delivered bytes, the throughput an account sees (ADR 0238); held compares operations with the IOPS cap.
    $counts = []; $bytes = []; $uids = [];
    foreach (array_keys($users) as $user) {
        $errors = 0;
        if (($uid = pmssCgroupDirectUserUidOrError((string) $user, $errors)) === null) continue;
        $uids[$user] = $uid;
        $counts[$uid] = pmssBlkioReadCount(pmssCgroupDirectUserSliceDir($uid).'/blkio.throttle.io_serviced', $majMin);
        $bytes[$uid] = pmssBlkioReadCount(pmssCgroupDirectUserSliceDir($uid).'/blkio.throttle.io_service_bytes', $majMin);
    }
    if ($host === null || !is_dir($unifiedRoot.'/user.slice')) return ['rows' => $rows, 'current' => null, 'held' => [], 'counts' => $counts, 'bytes' => $bytes];
    // A corrupt negative epoch must not become a floating elapsed argument to the held predicate.
    $then = is_int($state['time'] ?? null) && $state['time'] >= 0 && $state['time'] < $now ? $state['time'] : null;
    $wasHeld = pmssDynamicIopsHeldLastCycle($state, $now);
    $slices = []; $heldUsers = []; $heldUids = [];
    foreach ($uids as $user => $uid) {
        $dir = pmssCgroupDirectUserSliceDir($uid);
        $cap = pmssCgroupDirectDeviceValueParse((string) @file_get_contents($dir.'/blkio.throttle.read_iops_device'), $majMin);
        $before = is_int($state['counts'][$uid] ?? null) ? $state['counts'][$uid] : null;
        $heldUsers[$user] = $then !== null && pmssDynamicIopsSliceHeld($cap, $before, $counts[$uid], $now - $then);
        $exclude = $then !== null && pmssDynamicIopsSliceHeld($cap, $before, $counts[$uid], $now - $then, PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO);
        if ($exclude) $heldUids[] = $uid;
        $slices[] = ['full' => pmssPsiFullAvg300("$unifiedRoot/user.slice/user-$uid.slice/io.pressure"), 'held' => $exclude || isset($wasHeld[$uid])];
    }
    $current = ['time' => $now, 'host' => $host, 'held' => count(array_filter($heldUsers)), 'excluded' => count(array_filter(array_column($slices, 'held'))),
        'contention' => pmssDynamicIopsContention($host, pmssPsiFullAvg300("$unifiedRoot/system.slice/io.pressure"), $slices)];
    $rows[] = $current;
    if (!$readOnly && pmssEnsureSafeDir(dirname($statePath), 0755)) {
        pmssAtomicJsonFileWrite($statePath, ['time' => $now, 'counts' => $counts, 'held' => $heldUids, 'rows' => $rows], 0600);
    }
    return ['rows' => $rows, 'current' => $current, 'held' => $heldUsers, 'counts' => $counts, 'bytes' => $bytes];
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
