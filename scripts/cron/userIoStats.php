#!/usr/bin/php
<?php
/**
 * Collect per-user IOPS usage from cgroup statistics.
 *
 * Logs the change in read/write IOPS since the previous run to
 * /var/log/pmss/ioStats/<username> so averages can be calculated
 * similarly to trafficLog.php.
 *
 * Works only when the unified cgroup "io" controller is available.
 */

echo date('Y-m-d H:i:s') . ": collecting io stats\n";

$logDir = '/var/log/pmss/ioStats/';
$runDir = '/var/run/pmss/ioStats/';
foreach ([$logDir, $runDir] as $d) {
    if (!is_dir($d)) mkdir($d, 0700, true);
}

// Verify io controller exists (Debian 11+ with cgroup v2)
$haveIo = is_file('/sys/fs/cgroup/cgroup.controllers') &&
          strpos(file_get_contents('/sys/fs/cgroup/cgroup.controllers'), 'io') !== false;
if (!$haveIo) {
    echo "io controller not available\n";
    exit;
}

$users = explode("\n", trim(shell_exec('/scripts/listUsers.php')));

foreach ($users as $user) {
    if (empty($user)) continue;
    $uid  = trim(shell_exec("id -u {$user}"));
    $path = "/sys/fs/cgroup/user.slice/user-{$uid}.slice/io.stat";
    if (!is_file($path)) continue;

    $content = trim(file_get_contents($path));
    $vals = [];
    foreach (explode(' ', $content) as $part) {
        if (strpos($part, '=') !== false) {
            list($k, $v) = explode('=', $part, 2);
            $vals[$k] = (int)$v;
        }
    }
    if (!isset($vals['rios']) && !isset($vals['rbytes'])) continue;

    $stateFile = $runDir . $user;
    $prev = file_exists($stateFile) ? @unserialize(file_get_contents($stateFile)) : null;
    $vals['time'] = time();
    file_put_contents($stateFile, serialize($vals));

    if ($prev) {
        $read  = ($vals['rios'] ?? 0) - ($prev['rios'] ?? 0);
        $write = ($vals['wios'] ?? 0) - ($prev['wios'] ?? 0);
        $line  = date('Y-m-d H:i:s') . ": {$read} {$write}\n";
        file_put_contents($logDir . $user, $line, FILE_APPEND);
    }
}
