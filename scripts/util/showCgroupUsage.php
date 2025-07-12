#!/usr/bin/php
<?php
/**
 * Display current cgroup resource usage for each user.
 * Shows memory consumption and cumulative IO operations.
 */

$users = explode("\n", trim(shell_exec('/scripts/listUsers.php')));
foreach ($users as $user) {
    if (empty($user)) continue;
    $uid = trim(shell_exec("id -u {$user}"));
    $base = "/sys/fs/cgroup/user.slice/user-{$uid}.slice";
    if (!is_dir($base)) continue;
    $mem = @file_get_contents("$base/memory.current");
    $io  = @file_get_contents("$base/io.stat");
    $mem = $mem !== false ? (int)$mem / 1024 / 1024 : 0;
    $rios = $wios = 0;
    if ($io !== false && preg_match('/rios=(\d+)\s+wios=(\d+)/', $io, $m)) {
        $rios = (int)$m[1];
        $wios = (int)$m[2];
    }
    printf("%-12s mem: %6d MB rios: %10d wios: %10d\n", $user, $mem, $rios, $wios);
}
