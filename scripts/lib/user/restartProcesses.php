<?php
/** Process selection and account-privileged signalling for restartUser. */
require_once dirname(__DIR__).'/runtime.php';

/** @return int[] Real-UID processes, excluding the persistent user service manager. */
function pmssRestartUserProcessPids(int $uid, string $procRoot = '/proc'): array
{
    $entries = @scandir($procRoot);
    if (!is_array($entries)) return [];
    $pids = [];
    foreach ($entries as $entry) {
        if (!ctype_digit($entry) || (int) $entry < 1) continue;
        $base = rtrim($procRoot, '/').'/'.$entry;
        $status = @file_get_contents($base.'/status');
        if (!is_string($status)
            || !preg_match('/^Uid:\s*(\d+)\b/m', $status, $match)
            || (int) $match[1] !== $uid
            || preg_match('/^State:\s*Z\b/m', $status)) continue;
        $comm = @file_get_contents($base.'/comm');
        if (!is_string($comm)) continue;
        $comm = trim($comm);
        if ($comm === '(sd-pam)') continue;
        if ($comm === 'systemd') {
            $cmdline = @file_get_contents($base.'/cmdline');
            if (is_string($cmdline) && strpos($cmdline, '--user') !== false) continue;
        }
        $pids[] = (int) $entry;
    }
    sort($pids, SORT_NUMERIC);
    return $pids;
}

/** The shell and kill process inherit the account UID, protecting PID reuse. */
function pmssRestartUserSignalCommandBuild(string $user, string $signal, array $pids): string
{
    if (!in_array($signal, ['TERM', 'KILL'], true) || $pids === []) return '';
    $numbers = array_values(array_filter(array_map('intval', $pids), static function (int $pid): bool { return $pid > 0; }));
    if ($numbers === []) return '';
    return pmssBuildUserShellCommand($user, 'kill -'.$signal.' '.implode(' ', $numbers));
}

/** @return array{term:int,kill:int,remaining:int} */
function pmssRestartUserProcessesStop(string $user, int $uid, string $procRoot = '/proc'): array
{
    $initial = pmssRestartUserProcessPids($uid, $procRoot);
    if ($initial !== []) {
        $command = pmssLockChildClosePrefix().pmssRestartUserSignalCommandBuild($user, 'TERM', $initial).' >/dev/null 2>&1';
        exec($command);
    }
    $deadline = microtime(true) + 20.0;
    while (microtime(true) < $deadline && pmssRestartUserProcessPids($uid, $procRoot) !== []) {
        usleep(200000);
    }
    $survivors = pmssRestartUserProcessPids($uid, $procRoot);
    if ($survivors !== []) {
        $command = pmssLockChildClosePrefix().pmssRestartUserSignalCommandBuild($user, 'KILL', $survivors).' >/dev/null 2>&1';
        exec($command);
        usleep(200000);
    }
    return ['term' => count($initial), 'kill' => count($survivors),
        'remaining' => count(pmssRestartUserProcessPids($uid, $procRoot))];
}
