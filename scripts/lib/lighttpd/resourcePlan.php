<?php
/**
 * Resource planning helpers for per-user lighttpd configuration.
 *
 * @license GPL-3.0-only
 */

function pmssParseSizeToMiB($value): ?int
{
    $raw = trim((string)$value);
    if ($raw === '' || $raw === 'infinity' || $raw === '0') {
        return null;
    }

    if (preg_match('/^([0-9.]+)\s*([KMG])?B?$/i', $raw, $m)) {
        $num = (float)$m[1];
        $unit = strtolower($m[2] ?? '');
        $factors = ['' => 1 / 1048576, 'k' => 1 / 1024, 'm' => 1, 'g' => 1024];
        return isset($factors[$unit]) ? (int)round($num * $factors[$unit]) : null;
    }

    // Fallback: assume raw bytes
    return is_numeric($raw)
        ? (int) round(((float) $raw) / 1048576)
        : null;
}

function pmssClampMemoryLimit(int $memoryMiB): int
{
    return max(PMSS_PHP_MEMORY_MIN_MB, min(PMSS_PHP_MEMORY_MAX_MB, $memoryMiB));
}

function pmssExtractCpuQuotaPercent(array $props, array $policyDefaults): int
{
    $quota = null;

    // Prefer explicit slice CPUQuota value when present.
    if (isset($props['CPUQuota'])) {
        $raw = trim((string)$props['CPUQuota']);
        if ($raw !== '' && stripos($raw, 'infinity') === false && strpos($raw, '%') !== false) {
            $quota = (int)round((float)$raw);
        }
    }

    // Derive quota from period values when CPUQuota is not set directly.
    if ($quota === null) {
        $perSec = $props['CPUQuotaPerSecUSec'] ?? null;
        $period = $props['CPUQuotaPeriodUSec'] ?? null;
        if (is_numeric($perSec) && is_numeric($period) && (float)$period > 0.0) {
            $quota = (int)round(((float)$perSec / (float)$period) * 100);
        }
    }

    // When quota is explicitly set and not a legacy 85% sentinel, use it as-is.
    if ($quota !== null && $quota > 0 && $quota !== 85) {
        return $quota;
    }

    // Fallback if no usable systemd property is found:
    // Use policy default when it is a concrete value other than the legacy 85%.
    $policyQuota = (isset($policyDefaults['cpuQuotaPercent']) && is_numeric($policyDefaults['cpuQuotaPercent']))
        ? (int)$policyDefaults['cpuQuotaPercent']
        : 0;
    if ($policyQuota > 0 && $policyQuota !== 85) {
        return $policyQuota;
    }

    // Legacy 85% (either from slice or policy) and "no quota" fall through to a
    // host-based default: ~85% per logical CPU thread, but never below 200%.
    $threads = pmssTotalCpuThreads();
    return max(200, $threads * 85);
}

function pmssComputePhpProcessPlan(float $cpuQuotaPercent): array
{
    // Scale worker threads with CPU quota (approx. 4 threads per 100% quota),
    // then clamp to safe bounds (3..48 total threads).
    $effectiveQuota = $cpuQuotaPercent > 0 ? $cpuQuotaPercent : 100;
    $targetThreads = (int)ceil(($effectiveQuota / 100) * 4);
    $targetThreads = max(PMSS_PHP_THREADS_MIN, min(PMSS_PHP_THREADS_MAX, $targetThreads));

    $childrenPerProc = PMSS_LIGHTTPD_CHILDREN_PER_PROC;
    $maxProcs = (int)ceil($targetThreads / $childrenPerProc);
    $totalThreads = $maxProcs * $childrenPerProc;

    // Ensure we do not exceed global cap after rounding.
    if ($totalThreads > PMSS_PHP_THREADS_MAX) {
        $maxProcs = (int)ceil(PMSS_PHP_THREADS_MAX / $childrenPerProc);
        $totalThreads = $maxProcs * $childrenPerProc;
    }

    return [
        'max_procs'    => $maxProcs,
        'children'     => $childrenPerProc,
        'totalThreads' => $totalThreads,
    ];
}

function pmssResolveUserResources(string $user, array $policyDefaults): array
{
    $props = [];
    if (function_exists('posix_getpwnam') && is_array($info = posix_getpwnam($user)) && isset($info['uid'])) {
        $slice = sprintf('user-%d.slice', (int)$info['uid']);
        $cmd = 'systemctl show '.escapeshellarg($slice).' -p MemoryHigh -p MemoryMax -p CPUQuotaPerSecUSec -p CPUQuotaPeriodUSec -p CPUQuota';
        $out = @shell_exec($cmd);
        if (is_string($out)) {
            foreach (preg_split('/\r?\n/', trim($out)) as $line) {
                if ($line === '') {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $props[substr($line, 0, $pos)] = substr($line, $pos + 1);
            }
        }
    }

    $memoryHigh = null;
    foreach (['MemoryHigh', 'MemoryMax'] as $memoryLimitField) {
        if (isset($props[$memoryLimitField]) && ($memoryHigh = pmssParseSizeToMiB($props[$memoryLimitField])) !== null) {
            break;
        }
    }
    $memoryHigh = $memoryHigh ?? (isset($policyDefaults['memoryHighMiB']) ? (int)$policyDefaults['memoryHighMiB'] : 512);

    $phpMemoryLimit = pmssClampMemoryLimit((int)$memoryHigh);
    $cpuQuotaPercent = pmssExtractCpuQuotaPercent($props, $policyDefaults);
    $plan = pmssComputePhpProcessPlan($cpuQuotaPercent);

    return [
        'memoryLimit'     => $phpMemoryLimit,
        'cpuQuotaPercent' => $cpuQuotaPercent,
        'maxProcs'        => $plan['max_procs'],
        'children'        => $plan['children'],
        'totalThreads'    => $plan['totalThreads'],
    ];
}
