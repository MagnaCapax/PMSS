#!/usr/bin/php
<?php
/**
 * Display detailed slice configuration and cgroup usage for all users.
 */

$users = explode("\n", trim(shell_exec('/scripts/listUsers.php')));
foreach ($users as $user) {
    if (empty($user)) continue;
    $uid = trim(shell_exec("id -u {$user}"));
    $confFile = "/etc/systemd/system/user-{$uid}.slice.d/90-pmss-user.conf";
    $sliceDir = "/sys/fs/cgroup/user.slice/user-{$uid}.slice";

    $conf = is_file($confFile) ? file_get_contents($confFile) : '';
    preg_match('/MemoryHigh\s*=\s*(\d+)M/i', $conf, $mHigh);
    preg_match('/MemoryMax\s*=\s*(\d+)M/i', $conf, $mMax);
    preg_match('/CPUWeight\s*=\s*(\d+)/i', $conf, $cpuW);
    preg_match('/CPUQuota\s*=\s*(\d+)%/i', $conf, $cpuQ);
    preg_match('/IOWeight\s*=\s*(\d+)/i', $conf, $ioW);
    preg_match('/TasksMax\s*=\s*(\d+)/i', $conf, $tasks);

    $memCurrent = is_file("$sliceDir/memory.current") ? (int)file_get_contents("$sliceDir/memory.current")/1024/1024 : 0;
    $ioStat = is_file("$sliceDir/io.stat") ? file_get_contents("$sliceDir/io.stat") : '';
    $rios = $wios = 0;
    if ($ioStat && preg_match('/rios=(\d+)\s+wios=(\d+)/', $ioStat, $mi)) {
        $rios = (int)$mi[1];
        $wios = (int)$mi[2];
    }

    printf("%-12s mem:%s/%sMB cpuW:%s quota:%s ioW:%s tasks:%s rios:%d wios:%d\n",
        $user,
        $memCurrent,
        $mHigh[1] ?? 'n/a',
        $cpuW[1] ?? 'n/a',
        $cpuQ[1] ?? 'n/a',
        $ioW[1] ?? 'n/a',
        $tasks[1] ?? 'n/a',
        $rios,
        $wios
    );
}
