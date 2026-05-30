<?php
/**
 * Rendering and CLI handling for per-account terminal stats.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssStatsRenderBar(?float $percent, int $width): string
{
    if ($percent === null) return '['.str_repeat('·', $width).']';
    $clamped = max(0.0, min(100.0, $percent));
    $filled = (int) round(($clamped / 100.0) * $width);
    return '['.str_repeat('█', $filled).str_repeat('░', max(0, $width - $filled)).']';
}

function pmssStatsFormatBytesOrFallback($bytes, string $fallback = 'n/a'): string
{
    return $bytes !== null ? pmssFormatBytes((float) $bytes) : $fallback;
}

function pmssStatsRenderPercentSuffix(?float $percent, int $width): string
{
    return pmssStatsRenderBar($percent, $width).' '.($percent !== null ? sprintf('%d%%', round($percent)) : 'n/a');
}

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

    $diskValue = pmssStatsFormatBytesOrFallback($stats['disk']['used_bytes'], $stats['disk']['used_text']).' / '.pmssStatsFormatBytesOrFallback($stats['disk']['limit_bytes'], $stats['disk']['limit_text']);
    $memoryValue = pmssStatsFormatBytesOrFallback($stats['memory']['current_bytes']).' / '.pmssStatsFormatBytesOrFallback($stats['memory']['limit_bytes']);
    if (!empty($options['mini'])) {
        $ratio = $stats['rtorrent']['ratio'] !== null ? number_format((float) $stats['rtorrent']['ratio'], 2) : 'n/a';
        $lines[] = $title;
        $lines[] = 'Disk '.$diskValue.' · Mem '.$memoryValue;
        $lines[] = 'Up '.pmssFormatBytes((float) ($stats['rtorrent']['upload_rate'] ?? 0.0)).'/s · Down '.pmssFormatBytes((float) ($stats['rtorrent']['download_rate'] ?? 0.0)).'/s · Ratio '.$ratio;
        $lines[] = 'Traffic '.(($stats['traffic']['upload_month_mib'] !== null) ? pmssTrafficFormatAmount((float) $stats['traffic']['upload_month_mib']) : 'n/a').' · Uptime '.($stats['uptime_seconds'] !== null ? pmssStatsFormatUptime((int) $stats['uptime_seconds']) : 'n/a');
        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    $lines[] = pmssStatsRenderLine('Disk', $diskValue, pmssStatsRenderPercentSuffix($stats['disk']['percent'], 20));
    $lines[] = pmssStatsRenderLine('Memory', $memoryValue, pmssStatsRenderPercentSuffix($stats['memory']['percent'], 20));
    $lines[] = '';
    if ($stats['rtorrent']['torrent_total'] !== null) {
        $torrentSummary = sprintf('%d seeding · %d downloading · %d stopped', (int) ($stats['rtorrent']['torrent_seeding'] ?? 0), (int) ($stats['rtorrent']['torrent_downloading'] ?? 0), (int) ($stats['rtorrent']['torrent_stopped'] ?? 0));
    } elseif ($stats['rtorrent']['ok']) {
        $torrentSummary = 'rTorrent responsive';
    } else {
        $torrentSummary = 'rTorrent unavailable';
    }
    $lines[] = pmssStatsRenderLine('Torrents', $torrentSummary);
    foreach ([['Upload', '▲', 'upload'], ['Download', '▼', 'download']] as $transferLine) {
        $lines[] = pmssStatsRenderLine($transferLine[0], $transferLine[1].' '.pmssFormatBytes((float) ($stats['rtorrent'][$transferLine[2].'_rate'] ?? 0.0)).'/s', 'Total: '.pmssStatsFormatBytesOrFallback($stats['rtorrent'][$transferLine[2].'_total']));
    }
    $lines[] = pmssStatsRenderLine('Ratio', $stats['rtorrent']['ratio'] !== null ? number_format((float) $stats['rtorrent']['ratio'], 2) : 'n/a');
    $lines[] = '';

    $trafficValue = ($stats['traffic']['upload_month_mib'] !== null) ? pmssTrafficFormatAmount((float) $stats['traffic']['upload_month_mib']) : 'n/a';
    $trafficSuffix = '';
    if ($stats['traffic']['limit_mib'] !== null) {
        $trafficValue .= ' / '.pmssTrafficFormatAmount((float) $stats['traffic']['limit_mib']);
        $trafficSuffix = pmssStatsRenderPercentSuffix($stats['traffic']['percent'], 16);
    }
    $lines[] = pmssStatsRenderLine('Traffic', $trafficValue, $trafficSuffix);
    $lines[] = pmssStatsRenderLine('Uptime', $stats['uptime_seconds'] !== null ? pmssStatsFormatUptime((int) $stats['uptime_seconds']) : 'n/a', 'PMSS '.$stats['pmss_version']);

    if (!empty($options['full'])) {
        $lines[] = '';
        $lines[] = pmssStatsRenderLine('PIDs', ($stats['cgroup']['pids_current'] !== null) ? (string) $stats['cgroup']['pids_current'] : 'n/a');
        $lines[] = pmssStatsRenderLine('CPU', ($stats['cgroup']['cpu_usage_usec'] !== null) ? number_format(((float) $stats['cgroup']['cpu_usage_usec']) / 1000000, 1, '.', '').'s' : 'n/a');
        $lines[] = pmssStatsRenderLine('I/O Read', pmssFormatBytes((float) ($stats['cgroup']['io_read_bytes'] ?? 0)));
        $lines[] = pmssStatsRenderLine('I/O Write', pmssFormatBytes((float) ($stats['cgroup']['io_write_bytes'] ?? 0)));
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
    $self = basename($argv[0] ?? 'pmss-stats.php');
    $usage = pmssCliHelpUsageOptions($self.' [--full] [--json] [--mini] [--no-header]', [
        ['--full', 'Show extra cgroup counters and I/O details.'],
        ['--json', 'Emit machine-readable JSON.'],
        ['--mini', 'Show a compact four-line summary.'],
        ['--no-header', 'Skip the title box.'],
        ['--help', 'Show this help.'],
    ], 13);
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage)) === null) return false;

    return [
        'full' => pmssCliOptionPresent($parsed, 'full'),
        'json' => pmssCliOptionPresent($parsed, 'json'),
        'mini' => pmssCliOptionPresent($parsed, 'mini'),
        'no_header' => pmssCliOptionPresent($parsed, 'no-header'),
    ];
}

function pmssStatsMain(array $argv): int
{
    $options = pmssStatsParseOptions($argv);
    if ($options === false) return 0;
    $stats = pmssStatsCollect();
    if ($options['json']) return pmssJsonEmitPayload($stats, 'Failed to encode PMSS stats JSON.', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo pmssStatsRenderText($stats, $options);
    return 0;
}
