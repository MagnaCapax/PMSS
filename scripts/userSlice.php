#!/usr/bin/env php
<?php
/**
 * userSlice.php USERNAME
 *
 * CLI helper to inspect a user's systemd slice and current cgroup resource
 * properties. This mirrors the slice status view shown in the web stats page
 * but is callable directly from the shell for quick debugging.
 *
 * Output:
 *   - Basic identity: user, uid, detected slice name.
 *   - systemctl status user-UID.slice (core fields + CGroup tree).
 *
 * This script is read-only and safe to run on production hosts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/lib/runtime.php';
require_once __DIR__.'/lib/userLifecycle.php';

pmssRequireCli();

if ($argc !== 2) {
    fwrite(STDERR, "Usage: /scripts/userSlice.php USERNAME\n");
    exit(1);
}

$user = $argv[1];

$info = pmssUserAccountLookup($user);
if ($info === null) { fwrite(STDERR, "Unknown user: {$user}\n"); exit(1); }

$uid = (int) $info['uid'];
$slice = 'user-' . $uid . '.slice';

echo "User slice inspection\n";
echo "======================\n";
echo "user : {$user}\n";
echo "uid  : {$uid}\n";
echo "slice: {$slice}\n\n";

$cmd = 'systemctl status ' . escapeshellarg($slice) . ' 2>&1';
$output = @shell_exec($cmd);
if ($output === null || $output === '') {
    echo "No systemctl output for {$slice}. Is systemd running on this host?\n";
    exit(0);
}

$lines = explode("\n", $output);
$main = [];
$cgroup = [];
$inCgroup = false;

foreach ($lines as $line) {
    if (strpos($line, 'CGroup:') === 0) {
        $inCgroup = true;
        $cgroup[] = $line;
        continue;
    }
    if ($inCgroup) {
        if (trim($line) !== '' && isset($line[0]) && $line[0] === ' ') {
            $cgroup[] = $line;
        } else {
            $inCgroup = false;
            $main[] = $line;
        }
    } else {
        $main[] = $line;
    }
}

echo "Systemd slice status\n";
echo "--------------------\n";
echo implode("\n", $main) . "\n";

if (!empty($cgroup)) {
    echo "\nCGroup tree\n";
    echo "-----------\n";
    echo implode("\n", $cgroup) . "\n";
}

exit(0);
