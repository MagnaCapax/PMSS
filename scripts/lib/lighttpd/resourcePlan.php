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
        $factor = 1;
        if ($unit === 'k') {
            $factor = 1 / 1024;
        } elseif ($unit === 'm') {
            $factor = 1;
        } elseif ($unit === 'g') {
            $factor = 1024;
        } else {
            // No unit → assume bytes
            return (int)round($num / 1048576);
        }
        return (int)round($num * $factor);
    }

    // Fallback: assume raw bytes
    if (is_numeric($raw)) {
        return (int)round(((float)$raw) / 1048576);
    }

    return null;
}

function pmssClampMemoryLimit(int $memoryMiB): int
{
    $bounded = max(PMSS_PHP_MEMORY_MIN_MB, min(PMSS_PHP_MEMORY_MAX_MB, $memoryMiB));
    return $bounded;
}

function pmssExtractCpuQuotaPercent(array $props, array $policyDefaults): int
{
    $quota = null;

    // Prefer explicit slice CPUQuota value when present.
    if (isset($props['CPUQuota'])) {
        $raw = trim((string)$props['CPUQuota']);
        if ($raw !== '' && stripos($raw, 'infinity') === false) {
            if (strpos($raw, '%') !== false) {
                $quota = (int)round((float)$raw);
            }
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
    if (isset($policyDefaults['cpuQuotaPercent']) && is_numeric($policyDefaults['cpuQuotaPercent'])) {
        $policyQuota = (int)$policyDefaults['cpuQuotaPercent'];
        if ($policyQuota > 0 && $policyQuota !== 85) {
            return $policyQuota;
        }
    }

    // Legacy 85% (either from slice or policy) and "no quota" fall through to a
    // host-based default: ~85% per logical CPU thread, but never below 200%.
    $threads = pmssTotalCpuThreads();
    $default = $threads > 0 ? $threads * 85 : 200;
    return max(200, $default);
}

function pmssReadUserSliceProps(string $user): array
{
    if (!function_exists('posix_getpwnam')) {
        return [];
    }
    $info = posix_getpwnam($user);
    if (!is_array($info) || !isset($info['uid'])) {
        return [];
    }
    $slice = sprintf('user-%d.slice', (int)$info['uid']);
    $cmd = 'systemctl show '.escapeshellarg($slice).' -p MemoryHigh -p MemoryMax -p CPUQuotaPerSecUSec -p CPUQuotaPeriodUSec -p CPUQuota';
    $out = @shell_exec($cmd);
    $props = [];
    if (!is_string($out)) {
        return $props;
    }
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = substr($line, 0, $pos);
        $val = substr($line, $pos + 1);
        $props[$key] = $val;
    }
    return $props;
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
    $props = pmssReadUserSliceProps($user);

    $memoryHigh = null;
    if (isset($props['MemoryHigh'])) {
        $memoryHigh = pmssParseSizeToMiB($props['MemoryHigh']);
    }
    if ($memoryHigh === null && isset($props['MemoryMax'])) {
        $memoryHigh = pmssParseSizeToMiB($props['MemoryMax']);
    }
    if ($memoryHigh === null && isset($policyDefaults['memoryHighMiB'])) {
        $memoryHigh = (int)$policyDefaults['memoryHighMiB'];
    }
    if ($memoryHigh === null) {
        $memoryHigh = 512;
    }

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

