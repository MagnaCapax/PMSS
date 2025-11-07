#!/usr/bin/php
<?php
/**
 * Inspect per-user cgroup slice configuration and status.
 *
 * Usage:
 *   php /scripts/util/userCgroup.php USERNAME [--status] [--config]
 * Default: shows both config and status when no flags are provided.
 */

require_once __DIR__.'/../lib/cli/OptionParser.php';

function pmssCgroupMode(): string {
    return is_file('/sys/fs/cgroup/cgroup.controllers') ? 'v2' : 'v1';
}

function uidFromUser(string $user): int {
    if (!function_exists('posix_getpwnam')) return -1;
    $info = posix_getpwnam($user);
    return is_array($info) && isset($info['uid']) ? (int)$info['uid'] : -1;
}

function showConfig(string $slice): void {
    echo "\n[Config] $slice\n";
    $props = ['CPUWeight','IOWeight','MemoryAccounting','CPUAccounting','IOAccounting','MemoryHigh','MemoryMax','TasksMax'];
    $cmd = 'systemctl show '.escapeshellarg($slice).' -p '.implode(',', $props);
    $out = @shell_exec($cmd);
    echo $out !== null ? trim($out)."\n" : "(no data)\n";
}

function showStatus(string $slice, int $uid): void {
    $mode = pmssCgroupMode();
    echo "\n[Status] $slice (mode=$mode)\n";
    if ($mode === 'v2') {
        $base = "/sys/fs/cgroup/user.slice/user-{$uid}.slice";
        $pairs = [
            'pids.current'    => $base.'/pids.current',
            'memory.current'  => $base.'/memory.current',
            'memory.high'     => $base.'/memory.high',
            'memory.max'      => $base.'/memory.max',
            'io.stat'         => $base.'/io.stat',
            'cpu.weight'      => $base.'/cpu.weight',
        ];
        foreach ($pairs as $label => $path) {
            $val = @file_get_contents($path);
            if ($val === false) { echo "$label: (unavailable)\n"; } else { echo "$label: ".trim($val)."\n"; }
        }
    } else {
        $base = "/sys/fs/cgroup";
        $pids = $base."/pids/user.slice/user-{$uid}.slice/pids.current";
        $meml = $base."/memory/user.slice/user-{$uid}.slice/memory.limit_in_bytes";
        $memu = $base."/memory/user.slice/user-{$uid}.slice/memory.usage_in_bytes";
        foreach ([ 'pids.current' => $pids, 'memory.limit_in_bytes' => $meml, 'memory.usage_in_bytes' => $memu ] as $label => $path) {
            $val = @file_get_contents($path);
            if ($val === false) { echo "$label: (unavailable)\n"; } else { echo "$label: ".trim($val)."\n"; }
        }
    }
}

// CLI parsing
$args = $argv;
array_shift($args);
if (count($args) === 0) {
    fwrite(STDERR, "Usage: php /scripts/util/userCgroup.php USERNAME [--status] [--config]\n");
    exit(2);
}
$user = $args[0];
$flags = array_slice($args, 1);
$wantStatus = in_array('--status', $flags, true);
$wantConfig = in_array('--config', $flags, true);
if (!$wantStatus && !$wantConfig) { $wantStatus = $wantConfig = true; }

$uid = uidFromUser($user);
if ($uid < 0) {
    fwrite(STDERR, "Unknown user: $user\n");
    exit(1);
}
$slice = "user-{$uid}.slice";
echo "user=$user uid=$uid slice=$slice mode=".pmssCgroupMode()."\n";
if ($wantConfig) showConfig($slice);
if ($wantStatus) showStatus($slice, $uid);

