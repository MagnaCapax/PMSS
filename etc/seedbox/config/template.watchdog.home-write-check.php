<?php
/**
 * PMSS watchdog test: is the write path under /home stuck? (ADR 0301)
 *
 * The watchdog daemon runs this every interval through /etc/watchdog.d/home-write-check.sh.
 * It exits 5 only when the block device behind /home has write I/O in flight and has not
 * completed one write for PMSS_HOME_WRITE_WINDOW seconds: "stuck", not "busy". A busy host
 * keeps completing writes whatever its load average says. It reads only /proc, /sys and
 * /run state, never anything under /home, so a wedged filesystem cannot block it.
 * Whatever it cannot read or decide counts as "not stuck" (exit 0) and is logged once.
 *
 * Counters come from the top device that /home is mounted from (md, dm or a partition):
 * a write held up on any member below it keeps that device's own writes from completing.
 * PHP 7.3 compatible; run as `php -n` to start fast and ignore ini files.
 *
 * @license GPL-3.0-only
 */

const PMSS_HOME_WRITE_WINDOW = 300;
const PMSS_HOME_WRITE_STUCK = 5;

/** Source device of the filesystem mounted at /home, else of /; '' when it is not a /dev node. */
function pmssHomeWriteMountSource(string $mounts): string
{
    $home = '';
    $root = '';
    foreach (explode("\n", $mounts) as $line) {
        $fields = explode(' ', $line);
        if (count($fields) < 3) continue;
        // Later entries shadow earlier ones; a mountpoint below /home is a different path.
        if ($fields[1] === '/home') $home = $fields[0];
        elseif ($fields[1] === '/') $root = $fields[0];
    }
    $source = $home !== '' ? $home : $root;
    return strpos($source, '/dev/') === 0 ? $source : '';
}

/** Kernel block device name behind a /dev path ('' when it does not resolve to a block device). */
function pmssHomeWriteDeviceName(string $source, string $root = ''): string
{
    $real = $source === '' ? false : realpath($root.$source);
    if ($real === false || strpos($real, $root.'/dev/') !== 0) return '';
    $name = basename($real);
    return is_dir($root.'/sys/class/block/'.$name) ? $name : '';
}

/** True for a non-empty string of ASCII digits. `php -n` loads no extension modules (ctype is one on Debian), so core functions only. */
function pmssHomeWriteIsDigits(string $value): bool
{
    return $value !== '' && strspn($value, '0123456789') === strlen($value);
}

/** Whitespace-separated fields of one kernel statistics line. */
function pmssHomeWriteFields(string $line): array
{
    return array_values(array_filter(explode(' ', str_replace(["\t", "\r", "\n"], ' ', $line)), 'strlen'));
}

/** Writes completed by one device, from /proc/diskstats content (field 8); null when absent or malformed. */
function pmssHomeWriteCompleted(string $diskstats, string $name): ?int
{
    foreach (explode("\n", $diskstats) as $line) {
        $fields = pmssHomeWriteFields($line);
        if (count($fields) >= 8 && $fields[2] === $name && pmssHomeWriteIsDigits($fields[7])) return (int) $fields[7];
    }
    return null;
}

/** Writes in flight, from /sys/class/block/<name>/inflight content ("<reads> <writes>"); null when malformed. */
function pmssHomeWriteInflight(string $raw): ?int
{
    $fields = pmssHomeWriteFields($raw);
    return count($fields) === 2 && pmssHomeWriteIsDigits($fields[0]) && pmssHomeWriteIsDigits($fields[1]) ? (int) $fields[1] : null;
}

/** Seconds of uptime from /proc/uptime content; null when malformed. */
function pmssHomeWriteUptime(string $raw): ?int
{
    $seconds = explode('.', pmssHomeWriteFields($raw)[0] ?? '')[0];
    return pmssHomeWriteIsDigits($seconds) ? (int) $seconds : null;
}

/** Parse the state file "<since uptime> <writes completed> <logged 0|1>"; null when absent or malformed. */
function pmssHomeWriteParseState(string $raw): ?array
{
    $fields = pmssHomeWriteFields($raw);
    if (count($fields) !== 3 || !pmssHomeWriteIsDigits($fields[0]) || !pmssHomeWriteIsDigits($fields[1]) || !in_array($fields[2], ['0', '1'], true)) return null;
    return ['since' => (int) $fields[0], 'writes' => (int) $fields[1], 'logged' => (int) $fields[2]];
}

/**
 * The decision. The window opens at the first sample that shows writes in flight and no new
 * completion, and closes at any sample with a completion or with no write in flight.
 *
 * @return array{0: ?array, 1: int} next state (null = no window open) and the exit status
 */
function pmssHomeWriteDecide(?array $state, int $uptime, int $writes, int $writeInflight, int $window = PMSS_HOME_WRITE_WINDOW): array
{
    if ($writeInflight < 1) return [null, 0];
    // A counter that moved, or an uptime that went back, is progress or a new boot.
    if ($state === null || $writes !== $state['writes'] || $uptime < $state['since']) {
        return [['since' => $uptime, 'writes' => $writes, 'logged' => 0], 0];
    }
    return [$state, $uptime - $state['since'] >= $window ? PMSS_HOME_WRITE_STUCK : 0];
}

/** Evidence line: rotated file first (check*.log policy), then syslog, which survives the reboot it may cause. */
function pmssHomeWriteLog(string $root, string $line): void
{
    if (is_dir($root.'/var/log/pmss')) {
        @file_put_contents($root.'/var/log/pmss/checkHomeWriteWatchdog.log', gmdate('Y-m-d\TH:i:s\Z').' '.$line."\n", FILE_APPEND);
    }
    if ($root === '') {
        @openlog('pmss-watchdog-home', LOG_PID, LOG_DAEMON);
        @syslog(LOG_ERR, $line);
    }
}

/** A reason this test could not decide: logged once per episode, then silent until it clears. */
function pmssHomeWriteUndecided(string $root, string $reason): int
{
    $note = $root.'/run/pmss-watchdog-home-write-note';
    if (@file_get_contents($note) !== $reason) {
        pmssHomeWriteLog($root, 'cannot decide, treating as not stuck: '.$reason);
        @file_put_contents($note, $reason);
    }
    return 0;
}

/** First line of a kernel file, trimmed; '?' when unreadable. */
function pmssHomeWriteFirstLine(string $path): string
{
    $raw = @file_get_contents($path);
    return is_string($raw) ? trim(explode("\n", $raw)[0]) : '?';
}

/** One check. Exit 5 = stuck, 0 = anything else. */
function pmssHomeWriteRun(string $root): int
{
    $statePath = $root.'/run/pmss-watchdog-home-write';
    $mounts = @file_get_contents($root.'/proc/mounts');
    $source = is_string($mounts) ? pmssHomeWriteMountSource($mounts) : '';
    if ($source === '') return pmssHomeWriteUndecided($root, '/home (or /) is not mounted from a /dev node');
    $name = pmssHomeWriteDeviceName($source, $root);
    if ($name === '') return pmssHomeWriteUndecided($root, $source.' does not resolve to a block device');

    $diskstats = @file_get_contents($root.'/proc/diskstats');
    $writes = is_string($diskstats) ? pmssHomeWriteCompleted($diskstats, $name) : null;
    $inflightRaw = @file_get_contents($root.'/sys/class/block/'.$name.'/inflight');
    $inflight = is_string($inflightRaw) ? pmssHomeWriteInflight($inflightRaw) : null;
    $uptimeRaw = @file_get_contents($root.'/proc/uptime');
    $uptime = is_string($uptimeRaw) ? pmssHomeWriteUptime($uptimeRaw) : null;
    if ($writes === null || $inflight === null || $uptime === null) {
        return pmssHomeWriteUndecided($root, $name.': write counters, in-flight count or uptime unreadable');
    }
    @unlink($root.'/run/pmss-watchdog-home-write-note');

    $stateRaw = @file_get_contents($statePath);
    $state = is_string($stateRaw) ? pmssHomeWriteParseState($stateRaw) : null;
    [$next, $code] = pmssHomeWriteDecide($state, $uptime, $writes, $inflight);

    $detail = 'device='.$name.' writes_completed='.$writes.' write_inflight='.$inflight.' uptime='.$uptime
        .'s loadavg=['.pmssHomeWriteFirstLine($root.'/proc/loadavg').'] psi_io=['.pmssHomeWriteFirstLine($root.'/proc/pressure/io').']';
    if ($code === PMSS_HOME_WRITE_STUCK) {
        if ($state === null || $state['logged'] === 0) {
            pmssHomeWriteLog($root, 'STUCK: no write completed for '.($uptime - $next['since']).'s with writes in flight; '.$detail
                .'; reporting failure to watchdog, a reboot follows once retry-timeout expires');
        }
        $next['logged'] = 1;
    } elseif ($state !== null && $state['logged'] === 1) {
        pmssHomeWriteLog($root, 'recovered: '.$detail);
    }

    if ($next === null) {
        @unlink($statePath);
    } elseif (@file_put_contents($statePath.'.tmp', $next['since'].' '.$next['writes'].' '.$next['logged']) === false
        || !@rename($statePath.'.tmp', $statePath)) {
        return pmssHomeWriteUndecided($root, 'state file under /run is not writable');
    }
    return $code;
}

/** Entry point; any failure to run counts as "not stuck". */
function pmssHomeWriteMain(): int
{
    $root = (string) getenv('PMSS_HOME_WRITE_ROOT');
    try {
        return pmssHomeWriteRun($root);
    } catch (\Throwable $e) {
        return pmssHomeWriteUndecided($root, 'unexpected '.get_class($e).': '.$e->getMessage());
    }
}

if (!defined('PMSS_HOME_WRITE_LIBRARY')) {
    exit(pmssHomeWriteMain());
}
