<?php
/**
 * Resource planning helpers for per-user php-cgi pools.
 *
 * @license GPL-3.0-only
 */

function pmssParseSizeToMiB($value): ?int
{
    $raw = trim((string) $value);
    if ($raw === '' || $raw === 'infinity' || $raw === '0') {
        return null;
    }
    if (preg_match('/^([0-9.]+)\s*([KMG])?B?$/i', $raw, $m) !== 1) {
        return is_numeric($raw) ? (int) round(((float) $raw) / 1048576) : null;
    }

    $unit = strtolower($m[2] ?? '');
    $factors = ['' => 1 / 1048576, 'k' => 1 / 1024, 'm' => 1, 'g' => 1024];

    return isset($factors[$unit]) ? (int) round(((float) $m[1]) * $factors[$unit]) : null;
}

function pmssClampMemoryLimit(int $memoryMiB): int
{
    return max(PMSS_PHP_MEMORY_MIN_MB, min(PMSS_PHP_MEMORY_MAX_MB, $memoryMiB));
}

function pmssExtractCpuQuotaPercent(array $props, array $policyDefaults): int
{
    $quota = (int) (pmssSystemdCpuQuotaPercent($props) ?? 0);
    if ($quota > 0 && $quota !== 85) {
        return $quota;
    }

    $policyQuota = (isset($policyDefaults['cpuQuotaPercent']) && is_numeric($policyDefaults['cpuQuotaPercent']))
        ? (int) $policyDefaults['cpuQuotaPercent']
        : 0;
    if ($policyQuota > 0 && $policyQuota !== 85) {
        return $policyQuota;
    }

    return max(200, pmssTotalCpuThreads() * 85);
}

function pmssComputePhpProcessPlan(float $cpuQuotaPercent): array
{
    $targetThreads = (int) ceil((($cpuQuotaPercent > 0 ? $cpuQuotaPercent : 100) / 100) * 4);
    $targetThreads = max(PMSS_PHP_THREADS_MIN, min(PMSS_PHP_THREADS_MAX, $targetThreads));
    $maxProcs = (int) ceil($targetThreads / PMSS_LIGHTTPD_CHILDREN_PER_PROC);
    $totalThreads = $maxProcs * PMSS_LIGHTTPD_CHILDREN_PER_PROC;

    if ($totalThreads > PMSS_PHP_THREADS_MAX) {
        $maxProcs = (int) ceil(PMSS_PHP_THREADS_MAX / PMSS_LIGHTTPD_CHILDREN_PER_PROC);
        $totalThreads = $maxProcs * PMSS_LIGHTTPD_CHILDREN_PER_PROC;
    }

    return ['max_procs' => $maxProcs, 'children' => PMSS_LIGHTTPD_CHILDREN_PER_PROC, 'totalThreads' => $totalThreads];
}

function pmssLighttpdResourcePlan(array $props, array $policyDefaults): array
{
    $memoryHigh = null;
    foreach (['MemoryHigh', 'MemoryMax'] as $field) {
        if (isset($props[$field]) && ($memoryHigh = pmssParseSizeToMiB($props[$field])) !== null) {
            break;
        }
    }

    $cpuQuotaPercent = pmssExtractCpuQuotaPercent($props, $policyDefaults);
    $processPlan = pmssComputePhpProcessPlan($cpuQuotaPercent);
    $memoryDefault = isset($policyDefaults['memoryHighMiB']) ? (int) $policyDefaults['memoryHighMiB'] : 512;

    return [
        'memoryLimit' => pmssClampMemoryLimit((int) ($memoryHigh ?? $memoryDefault)),
        'cpuQuotaPercent' => $cpuQuotaPercent,
        'maxProcs' => $processPlan['max_procs'],
        'children' => $processPlan['children'],
        'totalThreads' => $processPlan['totalThreads'],
    ];
}
