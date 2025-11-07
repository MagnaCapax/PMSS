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
require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

if (!function_exists('pmssCgroupMode')) {
    function pmssCgroupMode(): string {
        return is_file('/sys/fs/cgroup/cgroup.controllers') ? 'v2' : 'v1';
    }
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

function totalMemMiB(): int {
    $o = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($o as $line) {
        if (strpos($line, 'MemTotal:') === 0) {
            $kb = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            return (int)round($kb/1024);
        }
    }
    return 0;
}

function computeSetProps(array $opts, int $sysMemMiB): array {
    $props = [];
    if (isset($opts['cpu-weight']))  { $props['CPUWeight'] = (int)$opts['cpu-weight']; }
    if (isset($opts['io-weight']))   { $props['IOWeight']  = (int)$opts['io-weight']; }
    if (isset($opts['tasks-max']))   { $props['TasksMax']  = (int)$opts['tasks-max']; }
    $minHigh = 250;
    if (isset($opts['memory-high'])) {
        $mh = max($minHigh, (int)$opts['memory-high']);
        $props['MemoryHigh'] = $mh.'M';
    }
    if (isset($opts['memory-max'])) {
        $defaultHigh = max($minHigh, (int)($sysMemMiB*0.10));
        $high = isset($props['MemoryHigh']) ? (int)rtrim($props['MemoryHigh'],'M') : $defaultHigh;
        if (!isset($props['MemoryHigh'])) { $props['MemoryHigh'] = $high.'M'; }
        $maxCap = $sysMemMiB > 0 ? (int)floor($sysMemMiB*0.95) : PHP_INT_MAX;
        $mm = (int)$opts['memory-max'];
        $mm = max($high, min($mm, $maxCap));
        $props['MemoryMax'] = $mm.'M';
    } elseif (isset($props['MemoryHigh'])) {
        $high = (int)rtrim($props['MemoryHigh'],'M');
        $maxCap = $sysMemMiB > 0 ? (int)floor($sysMemMiB*0.95) : PHP_INT_MAX;
        $mm = min((int)floor($high*1.5), $maxCap);
        $props['MemoryMax'] = $mm.'M';
    }
    return $props;
}

function main(array $argv): int {
    $args = $argv; array_shift($args);
    if (count($args) === 0) {
        fwrite(STDERR, "Usage: /scripts/util/userCgroup.php USERNAME [--status] [--config] [--apply] [--dry-run] [--cpu-weight N] [--io-weight N] [--tasks-max N] [--memory-high MiB] [--memory-max MiB]\n");
        return 2;
    }
    $user  = $args[0];
    $flags = array_slice($args, 1);
    $uid   = uidFromUser($user);
    if ($uid < 0) { fwrite(STDERR, "Unknown user: $user\n"); return 1; }
    $slice = "user-{$uid}.slice";
    $mode  = pmssCgroupMode();
    echo "user=$user uid=$uid slice=$slice mode=$mode\n";

    // Parse flags
    $opt = [];
    $wantStatus = in_array('--status', $flags, true);
    $wantConfig = in_array('--config', $flags, true);
    $apply      = in_array('--apply', $flags, true);
    $dryRun     = in_array('--dry-run', $flags, true);
    foreach (['--cpu-weight','--io-weight','--tasks-max','--memory-high','--memory-max'] as $k) {
        foreach ($flags as $i => $f) {
            if (strpos($f, $k.'=') === 0) {
                $opt[substr($k,2)] = substr($f, strlen($k)+1);
            }
        }
    }
    if (!$wantStatus && !$wantConfig && empty($opt)) { $wantStatus = $wantConfig = true; }
    if ($wantConfig) showConfig($slice);
    if ($wantStatus) showStatus($slice, $uid);

    if (!empty($opt)) {
        $props = computeSetProps($opt, totalMemMiB());
        echo "\n[Planned properties]\n";
        foreach ($props as $k=>$v) { echo "$k=$v\n"; }
        if ($apply && !$dryRun) {
            requireRoot();
            $pairs = [];
            foreach ($props as $k=>$v) { $pairs[] = $k.'='.$v; }
            $cmd = 'systemctl set-property '.escapeshellarg($slice).' '.implode(' ',$pairs);
            runStep('Applying cgroup properties', $cmd);
        } else {
            echo "(dry-run or no --apply; not changing system)\n";
        }
    }
    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(main($argv));
}
