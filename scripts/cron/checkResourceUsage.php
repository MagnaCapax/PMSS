#!/usr/bin/php
<?php
/**
 * Monitor user processes and throttle them when they exceed the limits
 * defined for the user's systemd slice.  Each account has a slice file
 * under /etc/systemd/system/user-<UID>.slice.d created by userConfig.php.
 * Those files specify MemoryMax and optional CPUQuota values that may vary
 * greatly between users (anything from 2 GiB to tens of GiB of RAM).
 *
 * This script reads those limits and only throttles processes that grow
 * past them.  It is primarily aimed at rTorrent but works for any process
 * owned by the user.  cpu.shares are lowered temporarily by placing the
 * offending PID into the rtorrent cgroup.
 */

echo date('Y-m-d H:i:s') . ": Checking resource usage\n";
$users = explode("\n", trim(shell_exec('/scripts/listUsers.php')));
$memInfo = file('/proc/meminfo');
$totalMem = 0;
foreach ($memInfo as $l) {
    if (strpos($l, 'MemTotal:') === 0) {
        $parts = preg_split('/\s+/', trim($l));
        $totalMem = (int)$parts[1];
        break;
    }
}
$globalCap = $totalMem > 0 ? (int)($totalMem * 0.95) : PHP_INT_MAX;

/**
 * Default limits are used when a slice file is missing.  They are generous
 * enough to avoid accidental throttling on systems with many cores.
 */
$defaultMemLimit = PHP_INT_MAX; // in kB, effectively unlimited
$defaultCpuLimit = 3000;        // percentage, e.g. 3000 = 30 cores

foreach ($users as $user) {
    if (empty($user)) continue;

    $uid = trim(shell_exec("id -u {$user}"));
    $sliceConf = "/etc/systemd/system/user-{$uid}.slice.d/90-pmss-user.conf";

    $memoryLimit = $defaultMemLimit; // kB
    $cpuLimit    = $defaultCpuLimit; // %
    $tasksLimit  = 1000;

    if (is_file($sliceConf)) {
        $conf = file_get_contents($sliceConf);
        if (preg_match('/MemoryMax\s*=\s*(\d+)M/i', $conf, $m)) {
            $memoryLimit = (int)$m[1] * 1024; // convert to kB
        }
        if (preg_match('/CPUQuota\s*=\s*(\d+)%/i', $conf, $m)) {
            $cpuLimit = (int)$m[1];
        }
        if (preg_match('/TasksMax\s*=\s*(\d+)/i', $conf, $m)) {
            $tasksLimit = (int)$m[1];
        }
    }

    if ($memoryLimit > $globalCap) {
        $memoryLimit = $globalCap;
    }

    $pids = trim(shell_exec("pgrep -u {$user}"));
    if (empty($pids)) continue;

    $pidList = explode("\n", $pids);
    $totalRss = 0;
    foreach ($pidList as $pid) {
        $status = @file("/proc/{$pid}/status");
        if (!$status) continue;
        $rss = 0;
        foreach ($status as $line) {
            if (strpos($line, 'VmRSS:') === 0) {
                $rss = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                break;
            }
        }

        $totalRss += $rss;
        $cpu = (float) trim(shell_exec("ps -p {$pid} -o %cpu --no-headers"));

        if ($rss > $memoryLimit || $cpu > $cpuLimit) {
            echo "Throttling PID {$pid} of {$user} (mem {$rss}k cpu {$cpu})\n";
            @file_put_contents('/sys/fs/cgroup/rtorrent/tasks', $pid);
            @file_put_contents('/sys/fs/cgroup/rtorrent/cpu.shares', '512');
        }
    }

    if ($totalRss > $memoryLimit || count($pidList) > $tasksLimit) {
        echo "Killing processes for {$user} (rss {$totalRss} limit {$memoryLimit})\n";
        shell_exec("killall -u {$user}");
    }
}

