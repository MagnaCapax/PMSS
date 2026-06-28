<?php
/**
 * Resource planning helpers for per-user php-cgi pools.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../runtime.php';

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

function pmssComputePhpProcessPlan(float $cpuQuotaPercent, int $minThreads = 0): array
{
    $targetThreads = (int) ceil((($cpuQuotaPercent > 0 ? $cpuQuotaPercent : 100) / 100) * 4);
    if ($minThreads > 0) {
        $targetThreads = max($targetThreads, $minThreads);
    }
    $targetThreads = max(PMSS_PHP_THREADS_MIN, min(PMSS_PHP_THREADS_MAX, $targetThreads));
    $maxProcs = (int) ceil($targetThreads / PMSS_LIGHTTPD_CHILDREN_PER_PROC);
    $totalThreads = $maxProcs * PMSS_LIGHTTPD_CHILDREN_PER_PROC;

    if ($totalThreads > PMSS_PHP_THREADS_MAX) {
        $maxProcs = (int) ceil(PMSS_PHP_THREADS_MAX / PMSS_LIGHTTPD_CHILDREN_PER_PROC);
        $totalThreads = $maxProcs * PMSS_LIGHTTPD_CHILDREN_PER_PROC;
    }

    return ['max_procs' => $maxProcs, 'children' => PMSS_LIGHTTPD_CHILDREN_PER_PROC, 'totalThreads' => $totalThreads];
}

function pmssLighttpdMinThreadsFromUserConfig(array $userConfig): int
{
    if (!isset($userConfig['lighttpdMinThreads']) || !is_numeric($userConfig['lighttpdMinThreads'])) {
        return 0;
    }

    $minThreads = (int) $userConfig['lighttpdMinThreads'];
    return $minThreads > 0 ? min(PMSS_PHP_THREADS_MAX, $minThreads) : 0;
}

function pmssLighttpdResourcePlan(array $props, array $policyDefaults, array $userConfig = []): array
{
    $memoryHigh = null;
    foreach (['MemoryHigh', 'MemoryMax'] as $field) {
        if (isset($props[$field]) && ($memoryHigh = pmssParseSizeToMiB($props[$field])) !== null) {
            break;
        }
    }

    $cpuQuotaPercent = pmssExtractCpuQuotaPercent($props, $policyDefaults);
    $processPlan = pmssComputePhpProcessPlan($cpuQuotaPercent, pmssLighttpdMinThreadsFromUserConfig($userConfig));
    $memoryDefault = isset($policyDefaults['memoryHighMiB']) ? (int) $policyDefaults['memoryHighMiB'] : 512;

    return [
        'memoryLimit' => pmssClampMemoryLimit((int) ($memoryHigh ?? $memoryDefault)),
        'cpuQuotaPercent' => $cpuQuotaPercent,
        'maxProcs' => $processPlan['max_procs'],
        'children' => $processPlan['children'],
        'totalThreads' => $processPlan['totalThreads'],
    ];
}
