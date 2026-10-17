<?php
/**
 * Live stats: 24 h ring buffers of node totals and one user's own samples.
 *
 * Per-user values come from the newest line of logs PMSS already writes
 * (resourceLog, trafficLog, trafficIngressLog), so no counter is read twice.
 * Only the node totals (/proc) are new. ADR 0111.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/resources.php';

const PMSS_LIVESTATS_SLOTS = 288;     // 24 h at one sample per five minutes
const PMSS_LIVESTATS_MAX_LINE_AGE = 420;

/** One node counter snapshot from /proc/stat, /proc/meminfo and /proc/net/dev text. */
function pmssLiveStatsNodeCountersParse(string $stat, string $meminfo, string $netDev, string $iface): ?array
{
    if (!preg_match('/^cpu\s+(\d+(?:\s+\d+){3,9})/m', $stat, $cpu)
        || !preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $mt) || !preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $ma)) return null;
    // guest fields are already inside user/nice: only the first eight count.
    $j = array_pad(array_map('intval', preg_split('/\s+/', trim($cpu[1]))), 8, 0);
    $total = array_sum(array_slice($j, 0, 8));
    if (!preg_match('/^\s*'.preg_quote($iface, '/').':\s*(\d+)(?:\s+\d+){7}\s+(\d+)/m', $netDev, $n)) return null;
    return ['busy' => $total - $j[3] - $j[4], 'total' => $total, 'mem_total' => (int) $mt[1] * 1024,
        'mem_used' => ((int) $mt[1] - (int) $ma[1]) * 1024, 'rx' => (int) $n[1], 'tx' => (int) $n[2]];
}

/** Delta of two snapshots as one ring sample; null for resets or implausible intervals. */
function pmssLiveStatsNodeSample(array $prev, array $cur, int $now): ?array
{
    $dt = $now - (int) ($prev['time'] ?? 0);
    if ($dt < 30 || $dt > 1800) return null;
    foreach (['busy', 'total', 'rx', 'tx'] as $k) {
        if (!isset($prev[$k], $cur[$k]) || $cur[$k] < $prev[$k]) return null;
    }
    return ['t' => $now, 'dt' => $dt, 'cb' => round(($cur['busy'] - $prev['busy']) / 100, 2),
        'ct' => round(($cur['total'] - $prev['total']) / 100, 2), 'mu' => $cur['mem_used'], 'mt' => $cur['mem_total'],
        'tx' => $cur['tx'] - $prev['tx'], 'rx' => $cur['rx'] - $prev['rx']];
}

/** Last line of a regular, non-symlink file (reads only the tail). */
function pmssLiveStatsLastLine(string $path): ?string
{
    if (is_link($path) || !is_file($path) || ($h = @fopen($path, 'rb')) === false) return null;
    fseek($h, max(0, (int) @filesize($path) - 1024));
    $tail = (string) stream_get_contents($h);
    fclose($h);
    // A line still being appended has no newline yet: ignore it rather than parse half a number.
    if ($tail !== '' && substr($tail, -1) !== "\n") $tail = ($pos = strrpos($tail, "\n")) === false ? '' : substr($tail, 0, $pos + 1);
    $lines = array_values(array_filter(explode("\n", $tail), 'strlen'));
    return $lines === [] ? null : end($lines);
}

/** Bytes of a "Y-m-d H:i:s: <bytes>" traffic-log line that is fresh and newer than $since, else 0. */
function pmssLiveStatsTraffic(?string $line, int $now, int $since = 0): int
{
    if ($line === null || !preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d): (\d+)$/', trim($line), $m)) return 0;
    $at = strtotime($m[1]);
    return $at !== false && $at > $since && $now - $at <= PMSS_LIVESTATS_MAX_LINE_AGE ? (int) $m[2] : 0;
}

/**
 * One user's ring sample from the existing per-user logs under $logRoot.
 * $since is the previous sample's time: a log line at or before it was already
 * counted then, so an early or repeated run never counts the same delta twice.
 */
function pmssLiveStatsUserSample(string $user, int $now, string $logRoot = '/var/log/pmss', int $since = 0): array
{
    $line = pmssLiveStatsLastLine($logRoot.'/resources/'.$user);
    $r = $line === null ? false : pmssResourceLogLineParse($line);
    $fresh = is_array($r) && $r['timestamp'] > $since && $now - $r['timestamp'] <= PMSS_LIVESTATS_MAX_LINE_AGE;
    return ['t' => $now, 'cpu' => $fresh ? round($r['cpu'] / 1e9, 2) : 0.0, 'mem' => $fresh ? (int) $r['memory'] : 0,
        'tx' => pmssLiveStatsTraffic(pmssLiveStatsLastLine($logRoot.'/traffic/'.$user), $now, $since),
        'rx' => pmssLiveStatsTraffic(pmssLiveStatsLastLine($logRoot.'/traffic-ingress/'.$user), $now, $since)];
}

/** Samples of a ring file; unreadable or malformed input yields []. */
function pmssLiveStatsRingRead(string $path): array
{
    $d = is_link($path) || !is_file($path) ? null : json_decode((string) @file_get_contents($path), true);
    return is_array($d) && is_array($d['samples'] ?? null) ? $d['samples'] : [];
}

/** Append a sample, drop equal/future slots (clock stepped back), keep the newest slots; returns the JSON document. */
function pmssLiveStatsRingEncode(array $samples, array $sample, int $slots = PMSS_LIVESTATS_SLOTS): string
{
    $kept = array_values(array_filter($samples, static function ($s) use ($sample) { return is_array($s) && isset($s['t']) && $s['t'] < $sample['t']; }));
    $kept[] = $sample;
    return json_encode(['v' => 1, 'samples' => array_slice($kept, -$slots)], JSON_UNESCAPED_SLASHES)."\n";
}
