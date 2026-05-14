<?php
/**
 * Per-account terminal stats helpers.
 *
 * This module keeps the CLI entrypoint small while collecting user-scoped
 * quota, traffic, resource, and rTorrent status from canonical PMSS data.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/rtorrent/scgi.php';
require_once __DIR__.'/traffic.php';
require_once __DIR__.'/update.php';
require_once __DIR__.'/user/userConfigStore.php';
require_once __DIR__.'/user/trafficLimit.php';
require_once __DIR__.'/cli/optionParser.php';

/**
 * Normalise a stats username when it cleanly maps to a PMSS account.
 */
function pmssStatsContextUserNormalize(string $user): string
{
    $user = trim($user);
    if ($user === '') {
        return '';
    }

    if (function_exists('pmssUsernameNormalizeIfValid')) {
        $normalized = pmssUsernameNormalizeIfValid($user);
        return is_string($normalized) ? $normalized : $user;
    }

    if (function_exists('pmssNormalizeUsername')) {
        $normalized = pmssNormalizeUsername($user);
        if (function_exists('pmssUsernameIsValid') && pmssUsernameIsValid($normalized)) {
            return $normalized;
        }
    }

    return $user;
}

/**
 * Choose the safe default home root for a stats context user.
 */
function pmssStatsContextDefaultHome(string $user): string
{
    return function_exists('pmssUsernameIsValid') && pmssUsernameIsValid($user)
        ? '/home/'.$user
        : '/home';
}

/**
 * Prefer explicit PMSS overrides before falling back to the shell HOME.
 */
function pmssStatsContextHomeCandidate(array $overrides, string $defaultHome): string
{
    if (array_key_exists('home', $overrides)) {
        return (string) $overrides['home'];
    }

    $statsHome = getenv('PMSS_STATS_HOME');
    if (is_string($statsHome) && trim($statsHome) !== '') {
        return $statsHome;
    }

    $statsUser = array_key_exists('user', $overrides) ? $overrides['user'] : getenv('PMSS_STATS_USER');
    if (is_string($statsUser) && trim($statsUser) !== '') {
        return $defaultHome;
    }

    $shellHome = getenv('HOME');
    return (is_string($shellHome) && trim($shellHome) !== '') ? $shellHome : $defaultHome;
}

/**
 * Resolve one stats path override while rejecting malformed or relative input.
 */
function pmssStatsContextPathResolve($candidate, string $default, bool $directoryPath = false): string
{
    $path = trim((string) $candidate);
    if ($path === '' || preg_match('/[\r\n\0]/', $path) === 1 || $path[0] !== '/') {
        $path = $default;
    }

    return $directoryPath ? pmssDirPathNormalize($path) : $path;
}

/**
 * Resolve the effective user, home, and local PMSS paths for stats reads.
 *
 * @param array<string, string> $overrides
 *
 * @return array<string, string>
 */
function pmssStatsResolveContext(array $overrides = []): array
{
    $user = trim((string) ($overrides['user'] ?? getenv('PMSS_STATS_USER') ?: ''));
    if ($user === '' && function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $entry = @posix_getpwuid(posix_geteuid());
        if (is_array($entry) && isset($entry['name'])) {
            $user = trim((string) $entry['name']);
        }
    }
    if ($user === '') {
        $user = trim((string) (getenv('USER') ?: get_current_user()));
    }
    $user = pmssStatsContextUserNormalize($user);

    $defaultHome = pmssStatsContextDefaultHome($user);
    $home = pmssStatsContextPathResolve(
        pmssStatsContextHomeCandidate($overrides, $defaultHome),
        $defaultHome,
        true
    );

    $configDir = pmssStatsContextPathResolve(
        $overrides['config_dir'] ?? getenv('PMSS_STATS_CONFIG_DIR') ?: '/etc/seedbox/config',
        '/etc/seedbox/config',
        true
    );
    $socketPath = pmssStatsContextPathResolve(
        $overrides['socket_path'] ?? getenv('PMSS_STATS_SOCKET_PATH') ?: ($home.'/.rtorrent.socket'),
        $home.'/.rtorrent.socket'
    );
    $versionFile = pmssStatsContextPathResolve(
        $overrides['version_file'] ?? getenv('PMSS_STATS_VERSION_FILE') ?: '/etc/seedbox/config/version',
        '/etc/seedbox/config/version'
    );

    $cgroupDir = pmssStatsContextPathResolve(
        $overrides['cgroup_dir'] ?? getenv('PMSS_STATS_CGROUP_DIR') ?: '',
        '',
        true
    );
    if ($cgroupDir === '') {
        $lines = @file('/proc/self/cgroup', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            $paths = [];
            foreach ($lines as $line) {
                $parts = explode(':', (string) $line, 3);
                if (count($parts) !== 3 || trim($parts[2]) === '') {
                    continue;
                }
                $paths[] = '/'.ltrim(trim($parts[2]), '/');
            }

            foreach ($paths as $path) {
                foreach (['/sys/fs/cgroup'.$path, '/sys/fs/cgroup/unified'.$path] as $candidate) {
                    if (is_dir($candidate)) {
                        $cgroupDir = $candidate;
                        break 2;
                    }
                }
            }
        }
    }

    return [
        'user' => $user,
        'home' => $home,
        'config_dir' => $configDir,
        'socket_path' => $socketPath,
        'version_file' => $versionFile,
        'cgroup_dir' => $cgroupDir,
    ];
}

/**
 * Parse a human-readable size token into bytes.
 */
function pmssStatsParseSizeToBytes(string $value): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTPE]?)(?:i?B)?$/i', $value, $matches) !== 1) {
        return null;
    }

    $number = (float) $matches[1];
    $unit = strtoupper($matches[2]);
    $powerMap = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6];
    $power = $powerMap[$unit] ?? null;
    if ($power === null) {
        return null;
    }

    return $number * pow(1024, $power);
}

/**
 * Read the quota snapshot written into the user home directory.
 *
 * @return array<string, mixed>
 */
function pmssStatsReadQuotaSnapshot(string $home): array
{
    $path = rtrim($home, '/').'/.quota';
    $result = [
        'path' => $path,
        'raw' => '',
        'used_text' => 'n/a',
        'soft_text' => 'n/a',
        'hard_text' => 'n/a',
        'used_bytes' => null,
        'soft_bytes' => null,
        'hard_bytes' => null,
    ];

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $result;
    }
    $result['raw'] = $raw;

    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        if (preg_match('/^\s*\/dev\/\S+\s+(\S+)\s+(\S+)\s+(\S+)/', trim($line), $matches) !== 1) {
            continue;
        }

        $result['used_text'] = $matches[1];
        $result['soft_text'] = $matches[2];
        $result['hard_text'] = $matches[3];
        $result['used_bytes'] = pmssStatsParseSizeToBytes($matches[1]);
        $result['soft_bytes'] = pmssStatsParseSizeToBytes($matches[2]);
        $result['hard_bytes'] = pmssStatsParseSizeToBytes($matches[3]);
        break;
    }

    return $result;
}

/**
 * Format bytes for compact CLI output.
 */
function pmssStatsFormatBytes(float $bytes, int $precision = 1): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $index = 0;
    while ($bytes >= 1024.0 && $index < count($units) - 1) {
        $bytes /= 1024.0;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : $precision, '.', '').' '.$units[$index];
}

/**
 * Format a rate stored in bytes per second.
 */
function pmssStatsFormatRate(float $bytesPerSecond): string
{
    return pmssStatsFormatBytes($bytesPerSecond).'/s';
}

/**
 * Render uptime in a compact days-hours-minutes form.
 */
function pmssStatsFormatUptime(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($days > 0) {
        return sprintf('%dd %dh %dm', $days, $hours, $minutes);
    }
    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $minutes);
    }
    return sprintf('%dm', $minutes);
}

/**
 * Read a few cgroup v2 counters for the current account slice when present.
 *
 * @return array<string, mixed>
 */
function pmssStatsReadCgroupStats(string $cgroupDir): array
{
    $stats = [
        'memory_current' => null,
        'memory_limit' => null,
        'pids_current' => null,
        'cpu_usage_usec' => null,
        'io_read_bytes' => 0,
        'io_write_bytes' => 0,
        'io_pressure_avg10' => null,
    ];

    if ($cgroupDir === '' || !is_dir($cgroupDir)) {
        return $stats;
    }

    $memoryCurrent = @file_get_contents($cgroupDir.'/memory.current');
    $memoryMax = @file_get_contents($cgroupDir.'/memory.max');
    $pidsCurrent = @file_get_contents($cgroupDir.'/pids.current');
    $cpuStat = @file($cgroupDir.'/cpu.stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $ioStat = @file($cgroupDir.'/io.stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $ioPressure = @file_get_contents($cgroupDir.'/../io.pressure');
    if (!is_string($ioPressure) || trim($ioPressure) === '') {
        $ioPressure = @file_get_contents(dirname($cgroupDir).'/io.pressure');
    }

    $memoryCurrent = is_string($memoryCurrent) ? trim($memoryCurrent) : '';
    $memoryMax = is_string($memoryMax) ? trim($memoryMax) : '';
    $pidsCurrent = is_string($pidsCurrent) ? trim($pidsCurrent) : '';
    $stats['memory_current'] = ctype_digit($memoryCurrent) ? (int) $memoryCurrent : null;
    $stats['memory_limit'] = ($memoryMax === 'max') ? 'max' : (ctype_digit($memoryMax) ? (int) $memoryMax : null);
    $stats['pids_current'] = ctype_digit($pidsCurrent) ? (int) $pidsCurrent : null;

    foreach ($cpuStat ?: [] as $line) {
        if (preg_match('/^usage_usec\s+(\d+)$/', (string) $line, $matches) === 1) {
            $stats['cpu_usage_usec'] = (int) $matches[1];
        }
    }

    foreach ($ioStat ?: [] as $line) {
        if (preg_match('/\brbytes=(\d+)/', (string) $line, $readMatches) === 1) {
            $stats['io_read_bytes'] += (int) $readMatches[1];
        }
        if (preg_match('/\bwbytes=(\d+)/', (string) $line, $writeMatches) === 1) {
            $stats['io_write_bytes'] += (int) $writeMatches[1];
        }
    }

    if (is_string($ioPressure) && preg_match('/avg10=([0-9.]+)/', $ioPressure, $matches) === 1) {
        $stats['io_pressure_avg10'] = (float) $matches[1];
    }

    return $stats;
}

/**
 * Collect decoded rTorrent state with bounded probes and graceful fallback.
 *
 * @param callable $caller
 *
 * @return array<string, mixed>
 */
function pmssStatsReadRtorrentStats(callable $caller, string $socketPath): array
{
    $stats = [
        'ok' => false,
        'upload_rate' => null,
        'download_rate' => null,
        'upload_total' => null,
        'download_total' => null,
        'ratio' => null,
        'torrent_total' => null,
        'torrent_active' => null,
        'torrent_seeding' => null,
        'torrent_downloading' => null,
        'torrent_stopped' => null,
    ];

    $uploadRate = $caller($socketPath, 'get_up_rate', [], 2);
    $downloadRate = $caller($socketPath, 'get_down_rate', [], 2);
    $uploadTotal = $caller($socketPath, 'get_up_total', [], 2);
    $downloadTotal = $caller($socketPath, 'get_down_total', [], 2);
    if ($uploadRate === false || $downloadRate === false || $uploadTotal === false || $downloadTotal === false) {
        return $stats;
    }

    $stats['ok'] = true;
    $stats['upload_rate'] = (float) $uploadRate;
    $stats['download_rate'] = (float) $downloadRate;
    $stats['upload_total'] = (float) $uploadTotal;
    $stats['download_total'] = (float) $downloadTotal;
    $stats['ratio'] = ((float) $downloadTotal > 0.0) ? ((float) $uploadTotal / (float) $downloadTotal) : null;

    $countView = static function (string $view) use ($caller, $socketPath): ?int {
        $result = $caller($socketPath, 'd.multicall2', [$view, 'd.get_hash='], 2);
        if ($result === false || !is_array($result)) {
            return null;
        }

        return count($result);
    };

    $total = $countView('main');
    $active = $countView('started');
    $seeding = $countView('seeding');
    if ($total !== null) {
        $stats['torrent_total'] = $total;
    }
    if ($active !== null) {
        $stats['torrent_active'] = $active;
    }
    if ($seeding !== null) {
        $stats['torrent_seeding'] = $seeding;
    }
    if ($active !== null && $seeding !== null) {
        $stats['torrent_downloading'] = max(0, $active - $seeding);
    }
    if ($total !== null && $active !== null) {
        $stats['torrent_stopped'] = max(0, $total - $active);
    }

    return $stats;
}

/**
 * Build a complete user-scoped stats payload for text or JSON rendering.
 *
 * @param array<string, string> $overrides
 * @param callable|null $rtorrentCaller
 *
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
    $uptimeRaw = @file_get_contents('/proc/uptime');
    if (is_string($uptimeRaw) && preg_match('/^(\d+(?:\.\d+)?)/', trim($uptimeRaw), $matches) === 1) {
        $uptimeSeconds = (int) floor((float) $matches[1]);
    }

    $rtorrent = pmssStatsReadRtorrentStats($rtorrentCaller ?: 'rtorrentScgiCall', $context['socket_path']);

    $diskLimitBytes = $quota['soft_bytes'];
    if ($diskLimitBytes === null && isset($config['quota']) && is_numeric($config['quota'])) {
        $diskLimitBytes = ((float) $config['quota']) * 1024 * 1024 * 1024;
    }
    $diskPercent = null;
    if ($quota['used_bytes'] !== null && is_numeric($diskLimitBytes) && (float) $diskLimitBytes > 0) {
        $diskPercent = ((float) $quota['used_bytes'] / (float) $diskLimitBytes) * 100.0;
    }

    $memoryCurrentBytes = null;
    if (isset($resource['memory']['current']) && is_numeric($resource['memory']['current'])) {
        $memoryCurrentBytes = (float) $resource['memory']['current'];
    } elseif (is_int($cgroup['memory_current'] ?? null)) {
        $memoryCurrentBytes = (float) $cgroup['memory_current'];
    }

    $memoryLimitBytes = null;
    if (isset($config['ramMiB']) && is_numeric($config['ramMiB'])) {
        $memoryLimitBytes = ((float) $config['ramMiB']) * 1024 * 1024;
    } elseif (is_int($cgroup['memory_limit'] ?? null)) {
        $memoryLimitBytes = (float) $cgroup['memory_limit'];
    }
    $memoryPercent = null;
    if ($memoryCurrentBytes !== null && $memoryLimitBytes !== null && $memoryLimitBytes > 0.0) {
        $memoryPercent = ($memoryCurrentBytes / $memoryLimitBytes) * 100.0;
    }

    $trafficLimitMiB = $trafficLimitState['effectiveLimitGiB'] > 0
        ? $trafficLimitState['effectiveLimitGiB'] * 1024.0
        : null;
    $trafficUsedMiB = isset($traffic['raw']['month']) && is_numeric($traffic['raw']['month'])
        ? (float) $traffic['raw']['month']
        : null;
    $trafficPercent = null;
    if ($trafficUsedMiB !== null && $trafficLimitMiB !== null && $trafficLimitMiB > 0.0) {
        $trafficPercent = ($trafficUsedMiB / $trafficLimitMiB) * 100.0;
    }

    return [
        'context' => $context,
        'product' => isset($config['product']) ? (string) $config['product'] : 'PMSS',
        'pmss_version' => getPmssVersion($context['version_file']),
        'uptime_seconds' => $uptimeSeconds,
        'quota' => $quota,
        'disk' => [
            'used_bytes' => $quota['used_bytes'],
            'limit_bytes' => $diskLimitBytes,
            'used_text' => (string) $quota['used_text'],
            'limit_text' => $diskLimitBytes !== null ? pmssStatsFormatBytes((float) $diskLimitBytes) : (string) $quota['soft_text'],
            'percent' => $diskPercent,
        ],
        'memory' => [
            'current_bytes' => $memoryCurrentBytes,
            'limit_bytes' => $memoryLimitBytes,
            'percent' => $memoryPercent,
        ],
        'traffic' => [
            'upload_month_mib' => $trafficUsedMiB,
            'download_month_mib' => isset($trafficIngress['raw']['month']) && is_numeric($trafficIngress['raw']['month'])
                ? (float) $trafficIngress['raw']['month']
                : null,
            'limit_mib' => $trafficLimitMiB,
            'bonus_gib' => $trafficLimitState['bonusGiB'],
            'percent' => $trafficPercent,
        ],
        'resource' => $resource,
        'cgroup' => $cgroup,
        'rtorrent' => $rtorrent,
    ];
}

/**
 * Render a fixed-width progress bar.
 */
function pmssStatsRenderBar(?float $percent, int $width): string
{
    if ($percent === null) {
        return '['.str_repeat('·', $width).']';
    }

    $clamped = max(0.0, min(100.0, $percent));
    $filled = (int) round(($clamped / 100.0) * $width);
    return '['.str_repeat('█', $filled).str_repeat('░', max(0, $width - $filled)).']';
}

/**
 * Render one labeled line with alignment suitable for narrow terminals.
 */
function pmssStatsRenderLine(string $label, string $value, string $suffix = ''): string
{
    return sprintf("  %-8s %s%s", $label, $value, $suffix === '' ? '' : '  '.$suffix);
}

/**
 * Render the neofetch-style account summary.
 *
 * @param array<string, mixed> $stats
 * @param array<string, bool>  $options
 */
function pmssStatsRenderText(array $stats, array $options = []): string
{
    $lines = [];
    $user = $stats['context']['user'];
    $title = 'Pulsed Media Seedbox · '.$stats['product'].' · '.$user;
    if (empty($options['no_header'])) {
        $borderWidth = max(strlen($title) + 4, 36);
        $lines[] = '╭'.str_repeat('─', $borderWidth - 2).'╮';
        $lines[] = '│ '.str_pad($title, $borderWidth - 4).' │';
        $lines[] = '╰'.str_repeat('─', $borderWidth - 2).'╯';
        $lines[] = '';
    }

    $diskValue = (($stats['disk']['used_bytes'] !== null) ? pmssStatsFormatBytes((float) $stats['disk']['used_bytes']) : $stats['disk']['used_text'])
        .' / '
        .(($stats['disk']['limit_bytes'] !== null) ? pmssStatsFormatBytes((float) $stats['disk']['limit_bytes']) : $stats['disk']['limit_text']);
    $memoryValue = (($stats['memory']['current_bytes'] !== null) ? pmssStatsFormatBytes((float) $stats['memory']['current_bytes']) : 'n/a')
        .' / '
        .(($stats['memory']['limit_bytes'] !== null) ? pmssStatsFormatBytes((float) $stats['memory']['limit_bytes']) : 'n/a');

    if (!empty($options['mini'])) {
        $ratio = $stats['rtorrent']['ratio'] !== null ? number_format((float) $stats['rtorrent']['ratio'], 2) : 'n/a';
        $lines[] = $title;
        $lines[] = 'Disk '.$diskValue.' · Mem '.$memoryValue;
        $lines[] = 'Up '.pmssStatsFormatRate((float) ($stats['rtorrent']['upload_rate'] ?? 0.0)).' · Down '.pmssStatsFormatRate((float) ($stats['rtorrent']['download_rate'] ?? 0.0)).' · Ratio '.$ratio;
        $lines[] = 'Traffic '.(($stats['traffic']['upload_month_mib'] !== null) ? pmssTrafficFormatAmount((float) $stats['traffic']['upload_month_mib']) : 'n/a').' · Uptime '.($stats['uptime_seconds'] !== null ? pmssStatsFormatUptime((int) $stats['uptime_seconds']) : 'n/a');
        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    $lines[] = pmssStatsRenderLine(
        'Disk',
        $diskValue,
        pmssStatsRenderBar($stats['disk']['percent'], 20).' '.($stats['disk']['percent'] !== null ? sprintf('%d%%', round((float) $stats['disk']['percent'])) : 'n/a')
    );
    $lines[] = pmssStatsRenderLine(
        'Memory',
        $memoryValue,
        pmssStatsRenderBar($stats['memory']['percent'], 20).' '.($stats['memory']['percent'] !== null ? sprintf('%d%%', round((float) $stats['memory']['percent'])) : 'n/a')
    );
    $lines[] = '';

    if ($stats['rtorrent']['torrent_total'] !== null) {
        $torrentSummary = sprintf(
            '%d seeding · %d downloading · %d stopped',
            (int) ($stats['rtorrent']['torrent_seeding'] ?? 0),
            (int) ($stats['rtorrent']['torrent_downloading'] ?? 0),
            (int) ($stats['rtorrent']['torrent_stopped'] ?? 0)
        );
    } elseif ($stats['rtorrent']['ok']) {
        $torrentSummary = 'rTorrent responsive';
    } else {
        $torrentSummary = 'rTorrent unavailable';
    }
    $lines[] = pmssStatsRenderLine('Torrents', $torrentSummary);
    $lines[] = pmssStatsRenderLine(
        'Upload',
        '▲ '.pmssStatsFormatRate((float) ($stats['rtorrent']['upload_rate'] ?? 0.0)),
        'Total: '.(($stats['rtorrent']['upload_total'] !== null) ? pmssStatsFormatBytes((float) $stats['rtorrent']['upload_total']) : 'n/a')
    );
    $lines[] = pmssStatsRenderLine(
        'Download',
        '▼ '.pmssStatsFormatRate((float) ($stats['rtorrent']['download_rate'] ?? 0.0)),
        'Total: '.(($stats['rtorrent']['download_total'] !== null) ? pmssStatsFormatBytes((float) $stats['rtorrent']['download_total']) : 'n/a')
    );
    $lines[] = pmssStatsRenderLine(
        'Ratio',
        $stats['rtorrent']['ratio'] !== null ? number_format((float) $stats['rtorrent']['ratio'], 2) : 'n/a'
    );
    $lines[] = '';

    if ($stats['traffic']['limit_mib'] !== null) {
        $lines[] = pmssStatsRenderLine(
            'Traffic',
            (($stats['traffic']['upload_month_mib'] !== null) ? pmssTrafficFormatAmount((float) $stats['traffic']['upload_month_mib']) : 'n/a')
                .' / '.pmssTrafficFormatAmount((float) $stats['traffic']['limit_mib']),
            pmssStatsRenderBar($stats['traffic']['percent'], 16).' '.($stats['traffic']['percent'] !== null ? sprintf('%d%%', round((float) $stats['traffic']['percent'])) : 'n/a')
        );
    } else {
        $lines[] = pmssStatsRenderLine(
            'Traffic',
            (($stats['traffic']['upload_month_mib'] !== null) ? pmssTrafficFormatAmount((float) $stats['traffic']['upload_month_mib']) : 'n/a')
        );
    }
    $lines[] = pmssStatsRenderLine('Uptime', $stats['uptime_seconds'] !== null ? pmssStatsFormatUptime((int) $stats['uptime_seconds']) : 'n/a', 'PMSS '.$stats['pmss_version']);

    if (!empty($options['full'])) {
        $lines[] = '';
        $lines[] = pmssStatsRenderLine('PIDs', ($stats['cgroup']['pids_current'] !== null) ? (string) $stats['cgroup']['pids_current'] : 'n/a');
        $lines[] = pmssStatsRenderLine('CPU', ($stats['cgroup']['cpu_usage_usec'] !== null) ? number_format(((float) $stats['cgroup']['cpu_usage_usec']) / 1000000, 1, '.', '').'s' : 'n/a');
        $lines[] = pmssStatsRenderLine('I/O Read', pmssStatsFormatBytes((float) ($stats['cgroup']['io_read_bytes'] ?? 0)));
        $lines[] = pmssStatsRenderLine('I/O Write', pmssStatsFormatBytes((float) ($stats['cgroup']['io_write_bytes'] ?? 0)));
        $lines[] = pmssStatsRenderLine('I/O PSI', ($stats['cgroup']['io_pressure_avg10'] !== null) ? number_format((float) $stats['cgroup']['io_pressure_avg10'], 1, '.', '') : 'n/a');
    }

    return implode(PHP_EOL, $lines).PHP_EOL;
}

/**
 * Parse CLI options for the stats command.
 *
 * @return array<string, bool>|false
 */
function pmssStatsParseOptions(array $argv)
{
    $parsed = pmssParseCliTokens($argv);

    if (pmssCliOptionPresent($parsed, 'help', 'h')) {
        $self = basename($argv[0] ?? 'pmss-stats.php');
        echo pmssCliHelpUsageOptions($self.' [--full] [--json] [--mini] [--no-header]', [
            ['--full', 'Show extra cgroup counters and I/O details.'],
            ['--json', 'Emit machine-readable JSON.'],
            ['--mini', 'Show a compact four-line summary.'],
            ['--no-header', 'Skip the title box.'],
            ['--help', 'Show this help.'],
        ], 13);
        return false;
    }

    return [
        'full' => pmssCliOptionPresent($parsed, 'full'),
        'json' => pmssCliOptionPresent($parsed, 'json'),
        'mini' => pmssCliOptionPresent($parsed, 'mini'),
        'no_header' => pmssCliOptionPresent($parsed, 'no-header'),
    ];
}

/**
 * CLI main for the per-account stats command.
 */
function pmssStatsMain(array $argv): int
{
    $options = pmssStatsParseOptions($argv);
    if ($options === false) {
        return 0;
    }

    $stats = pmssStatsCollect();
    if ($options['json']) {
        return pmssJsonEmitPayload($stats, 'Failed to encode PMSS stats JSON.', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    echo pmssStatsRenderText($stats, $options);
    return 0;
}
