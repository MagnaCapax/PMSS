<?php
/**
 * Data collection for per-account terminal stats.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Read the quota snapshot written into the user home directory.
 *
 * @return array<string, mixed>
 */
function pmssStatsReadQuotaSnapshot(string $home): array
{
    $path = rtrim($home, '/').'/.quota';
    $result = ['path' => $path, 'raw' => '', 'used_text' => 'n/a', 'soft_text' => 'n/a', 'hard_text' => 'n/a', 'used_bytes' => null, 'soft_bytes' => null, 'hard_bytes' => null];
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || trim($raw) === '') return $result;
    $result['raw'] = $raw;

    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        if (preg_match('/^\s*\/dev\/\S+\s+(\S+)\s+(\S+)\s+(\S+)/', trim($line), $matches) !== 1) continue;
        $result['used_text'] = $matches[1];
        $result['soft_text'] = $matches[2];
        $result['hard_text'] = $matches[3];
        $result['used_bytes'] = pmssParseSizeToBytes($matches[1]);
        $result['soft_bytes'] = pmssParseSizeToBytes($matches[2]);
        $result['hard_bytes'] = pmssParseSizeToBytes($matches[3]);
        break;
    }
    return $result;
}

function pmssStatsPercent(?float $used, ?float $limit): ?float
{
    return ($used !== null && $limit !== null && $limit > 0.0) ? ($used / $limit) * 100.0 : null;
}

function pmssStatsFormatUptime(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) return sprintf('%dd %dh %dm', $days, $hours, $minutes);
    return $hours > 0 ? sprintf('%dh %dm', $hours, $minutes) : sprintf('%dm', $minutes);
}

/**
 * Read a few cgroup v2 counters for the current account slice when present.
 *
 * @return array<string, mixed>
 */
function pmssStatsReadCgroupStats(string $cgroupDir): array
{
    $stats = ['memory_current' => null, 'memory_limit' => null, 'pids_current' => null, 'cpu_usage_usec' => null, 'io_read_bytes' => 0, 'io_write_bytes' => 0, 'io_pressure_avg10' => null];
    if ($cgroupDir === '' || !is_dir($cgroupDir)) return $stats;

    $memoryCurrent = pmssReadRegularFileTrimmed($cgroupDir.'/memory.current') ?? '';
    $memoryMax = pmssReadRegularFileTrimmed($cgroupDir.'/memory.max') ?? '';
    $pidsCurrent = pmssReadRegularFileTrimmed($cgroupDir.'/pids.current') ?? '';
    $stats['memory_current'] = ctype_digit($memoryCurrent) ? (int) $memoryCurrent : null;
    $stats['memory_limit'] = ($memoryMax === 'max') ? 'max' : (ctype_digit($memoryMax) ? (int) $memoryMax : null);
    $stats['pids_current'] = ctype_digit($pidsCurrent) ? (int) $pidsCurrent : null;

    foreach (preg_split('/\r?\n/', trim(pmssReadRegularFileContents($cgroupDir.'/cpu.stat') ?? '')) ?: [] as $line) {
        if (preg_match('/^usage_usec\s+(\d+)$/', (string) $line, $matches) === 1) $stats['cpu_usage_usec'] = (int) $matches[1];
    }
    foreach (preg_split('/\r?\n/', trim(pmssReadRegularFileContents($cgroupDir.'/io.stat') ?? '')) ?: [] as $line) {
        if (preg_match('/\brbytes=(\d+)/', (string) $line, $readMatches) === 1) $stats['io_read_bytes'] += (int) $readMatches[1];
        if (preg_match('/\bwbytes=(\d+)/', (string) $line, $writeMatches) === 1) $stats['io_write_bytes'] += (int) $writeMatches[1];
    }

    $ioPressure = pmssReadRegularFileContents($cgroupDir.'/../io.pressure');
    if ($ioPressure === null || trim($ioPressure) === '') $ioPressure = pmssReadRegularFileContents(dirname($cgroupDir).'/io.pressure');
    if (is_string($ioPressure) && preg_match('/avg10=([0-9.]+)/', $ioPressure, $matches) === 1) $stats['io_pressure_avg10'] = (float) $matches[1];
    return $stats;
}

/**
 * Collect decoded rTorrent state with bounded probes and graceful fallback.
 *
 * @param callable $caller
 * @return array<string, mixed>
 */
function pmssStatsReadRtorrentStats(callable $caller, string $socketPath): array
{
    $stats = [
        'ok' => false, 'upload_rate' => null, 'download_rate' => null, 'upload_total' => null, 'download_total' => null, 'ratio' => null,
        'torrent_total' => null, 'torrent_active' => null, 'torrent_seeding' => null, 'torrent_downloading' => null, 'torrent_stopped' => null,
    ];

    foreach (['upload_rate' => 'get_up_rate', 'download_rate' => 'get_down_rate', 'upload_total' => 'get_up_total', 'download_total' => 'get_down_total'] as $key => $method) {
        $value = $caller($socketPath, $method, [], 2);
        if ($value === false) return $stats;
        $stats[$key] = (float) $value;
    }
    $stats['ok'] = true;
    $stats['ratio'] = ($stats['download_total'] > 0.0) ? ($stats['upload_total'] / $stats['download_total']) : null;

    foreach (['torrent_total' => 'main', 'torrent_active' => 'started', 'torrent_seeding' => 'seeding'] as $key => $view) {
        $result = $caller($socketPath, 'd.multicall2', [$view, 'd.get_hash='], 2);
        if (is_array($result)) $stats[$key] = count($result);
    }
    if ($stats['torrent_active'] !== null && $stats['torrent_seeding'] !== null) $stats['torrent_downloading'] = max(0, $stats['torrent_active'] - $stats['torrent_seeding']);
    if ($stats['torrent_total'] !== null && $stats['torrent_active'] !== null) $stats['torrent_stopped'] = max(0, $stats['torrent_total'] - $stats['torrent_active']);
    return $stats;
}

/**
 * Build a complete user-scoped stats payload for text or JSON rendering.
 *
 * @param array<string, string> $overrides
 * @return array<string, mixed>
 */
function pmssStatsCollect(array $overrides = [], ?callable $rtorrentCaller = null): array
{
    $context = pmssStatsResolveContext($overrides);
    $home = $context['home'];
    $store = new UserConfigStore($context['config_dir']);
    $configPayload = $store->get($context['user']);
    $config = is_array($configPayload) ? $configPayload : [];
    $quota = pmssStatsReadQuotaSnapshot($home);
    $traffic = pmssReadSerializedArrayFile($home.'/.trafficData') ?: [];
    $trafficIngress = pmssReadSerializedArrayFile($home.'/.trafficDataIngress') ?: [];
    $resource = pmssReadSerializedArrayFile($home.'/.resourceData') ?: [];
    $trafficLimitState = pmssTrafficLimitStateRead($home.'/.trafficLimit', $home.'/.bonusTraffic');
    $cgroup = pmssStatsReadCgroupStats($context['cgroup_dir']);
    $uptimeSeconds = null;
    $uptimeRaw = pmssReadRegularFileContents('/proc/uptime');
    if (is_string($uptimeRaw) && preg_match('/^(\d+(?:\.\d+)?)/', trim($uptimeRaw), $matches) === 1) $uptimeSeconds = (int) floor((float) $matches[1]);

    $rtorrent = pmssStatsReadRtorrentStats($rtorrentCaller ?: 'rtorrentScgiCall', $context['socket_path']);
    $diskLimitBytes = $quota['soft_bytes'];
    if ($diskLimitBytes === null && isset($config['quota']) && is_numeric($config['quota'])) $diskLimitBytes = ((float) $config['quota']) * 1024 * 1024 * 1024;
    $memoryCurrentBytes = isset($resource['memory']['current']) && is_numeric($resource['memory']['current']) ? (float) $resource['memory']['current'] : (is_int($cgroup['memory_current'] ?? null) ? (float) $cgroup['memory_current'] : null);
    $memoryLimitBytes = isset($config['ramMiB']) && is_numeric($config['ramMiB']) ? ((float) $config['ramMiB']) * 1024 * 1024 : (is_int($cgroup['memory_limit'] ?? null) ? (float) $cgroup['memory_limit'] : null);
    $trafficLimitMiB = $trafficLimitState['effectiveLimitGiB'] > 0 ? $trafficLimitState['effectiveLimitGiB'] * 1024.0 : null;
    $trafficUsedMiB = isset($traffic['raw']['month']) && is_numeric($traffic['raw']['month']) ? (float) $traffic['raw']['month'] : null;

    return [
        'context' => $context,
        'product' => isset($config['product']) ? (string) $config['product'] : 'PMSS',
        'pmss_version' => getPmssVersion($context['version_file']),
        'uptime_seconds' => $uptimeSeconds,
        'quota' => $quota,
        'disk' => [
            'used_bytes' => $quota['used_bytes'], 'limit_bytes' => $diskLimitBytes,
            'used_text' => (string) $quota['used_text'],
            'limit_text' => $diskLimitBytes !== null ? pmssFormatBytes((float) $diskLimitBytes) : (string) $quota['soft_text'],
            'percent' => pmssStatsPercent($quota['used_bytes'], $diskLimitBytes),
        ],
        'memory' => ['current_bytes' => $memoryCurrentBytes, 'limit_bytes' => $memoryLimitBytes, 'percent' => pmssStatsPercent($memoryCurrentBytes, $memoryLimitBytes)],
        'traffic' => [
            'upload_month_mib' => $trafficUsedMiB,
            'download_month_mib' => isset($trafficIngress['raw']['month']) && is_numeric($trafficIngress['raw']['month']) ? (float) $trafficIngress['raw']['month'] : null,
            'limit_mib' => $trafficLimitMiB,
            'bonus_gib' => $trafficLimitState['bonusGiB'],
            'percent' => pmssStatsPercent($trafficUsedMiB, $trafficLimitMiB),
        ],
        'resource' => $resource,
        'cgroup' => $cgroup,
        'rtorrent' => $rtorrent,
    ];
}
