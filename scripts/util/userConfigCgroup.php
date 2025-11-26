#!/usr/bin/php
<?php
/**
 * Inspect and apply per-user cgroup slice configuration.
 *
 * Canonical entry point for automation and operators. Supersedes the legacy
 * userCgroup.php name; a thin wrapper remains for backward compatibility.
 *
 * Usage:
 *   php /scripts/util/userConfigCgroup.php USERNAME [--status] [--config]
 * Default: shows both config and status when no flags are provided.
 *
 * Flags:
 *   --apply              Apply computed properties
 *   --defaults           Seed properties from policy defaults (cgroup.policy.php)
 *   --respect-existing   When used with --defaults, do not overwrite properties
 *                        already present on the slice. Intended for safe updates.
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
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
    $props = ['CPUWeight','IOWeight','MemoryAccounting','CPUAccounting','IOAccounting','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec','CPUQuotaPeriodUSec'];
    $cmd = 'systemctl show '.escapeshellarg($slice).' -p '.implode(',', $props);
    $out = @shell_exec($cmd);
    echo $out !== null ? trim($out)."\n" : "(no data)\n";
}

function readCurrentProps(string $slice): array {
    $props = ['CPUWeight','IOWeight','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec'];
    $cmd = 'systemctl show '.escapeshellarg($slice).' -p '.implode(' -p ', $props);
    $out = @shell_exec($cmd);
    $map = [];
    if (!is_string($out)) return $map;
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = substr($line, 0, $pos);
        $v = substr($line, $pos+1);
        if ($k === 'CPUQuotaPerSecUSec') {
            $map['CPUQuota'] = $v;
        } else {
            $map[$k] = $v;
        }
    }
    return $map;
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

function calculateCgroupWeightFromMemory(int $memoryMiB): int {
    $weight = (int) round(8 * sqrt($memoryMiB));
    return max(10, min(1000, $weight));
}

function computeSetProps(array $opts, int $sysMemMiB): array {
    $props = [];
    $minHigh = 250;
    $memoryHighMiB = null;
    $maxCap = $sysMemMiB > 0 ? (int)floor($sysMemMiB * 0.95) : PHP_INT_MAX;

    if (isset($opts['memory-high'])) {
        $memoryHighMiB = max($minHigh, (int)$opts['memory-high']);
        $props['MemoryHigh'] = $memoryHighMiB.'M';
    }

    if (isset($opts['memory-max'])) {
        if ($memoryHighMiB === null) {
            $memoryHighMiB = max($minHigh, (int)($sysMemMiB*0.10));
            $props['MemoryHigh'] = $memoryHighMiB.'M';
        }
        $memoryMax = (int)$opts['memory-max'];
        $memoryMax = max($memoryHighMiB, min($memoryMax, $maxCap));
        $props['MemoryMax'] = $memoryMax.'M';
    } elseif ($memoryHighMiB !== null) {
        $memoryMax = min((int)floor($memoryHighMiB*1.5), $maxCap);
        $props['MemoryMax'] = $memoryMax.'M';
    }

    // Derive weights from RAM if not explicitly set
    $derivedWeight = null;
    if ($memoryHighMiB !== null) {
        $derivedWeight = calculateCgroupWeightFromMemory($memoryHighMiB);
    }

    if (isset($opts['cpu-weight'])) {
        $props['CPUWeight'] = (int)$opts['cpu-weight'];
    } elseif ($derivedWeight !== null) {
        $props['CPUWeight'] = $derivedWeight;
    }

    if (isset($opts['io-weight'])) {
        $props['IOWeight'] = (int)$opts['io-weight'];
    } elseif ($derivedWeight !== null) {
        $props['IOWeight'] = $derivedWeight;
    }

    if (isset($opts['tasks-max'])) {
        $props['TasksMax'] = (int)$opts['tasks-max'];
    }

    if (isset($opts['cpu-quota-percent'])) {
        $quota = $opts['cpu-quota-percent'];
        if (is_string($quota) && strtolower($quota) === 'infinity') {
            $props['CPUQuota'] = 'infinity';
        } else {
            $pct = (int)$quota;
            if ($pct > 0) {
                $props['CPUQuota'] = $pct.'%';
            } elseif ($pct === 0) {
                $props['CPUQuota'] = 'infinity';
            }
        }
    }

    return $props;
}

function pmssUserConfigCgroupMain(array $argv): int {
    $args = $argv; array_shift($args);
    if (count($args) === 0) {
        fwrite(STDERR, "Usage: /scripts/util/userConfigCgroup.php USERNAME [--status] [--config] [--apply] [--dry-run] [--cpu-weight N] [--io-weight N] [--tasks-max N] [--memory-high MiB] [--memory-max MiB] [--cpu-quota-percent N]\n");
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
    $respectExisting = in_array('--respect-existing', $flags, true);
    $device = '';
    $ioProfile = '';
    $hasIoFlag = false;
    foreach (['--cpu-weight','--io-weight','--tasks-max','--memory-high','--memory-max','--device','--io-profile','--cpu-profile','--mem-profile','--tasks-profile','--cpu-quota-percent'] as $k) {
        foreach ($flags as $i => $f) {
            if (strpos($f, $k.'=') === 0) {
                $val = substr($f, strlen($k)+1);
                if ($k === '--device') { $device = $val; }
                elseif ($k === '--io-profile') { $ioProfile = strtolower($val); }
                elseif ($k === '--cpu-profile') { $opt['cpu-profile'] = strtolower($val); }
                elseif ($k === '--mem-profile') { $opt['mem-profile'] = strtolower($val); }
                elseif ($k === '--tasks-profile') { $opt['tasks-profile'] = strtolower($val); }
                else { $opt[substr($k,2)] = $val; }
            }
        }
    }
    // Support --defaults to apply policy-derived defaults
    $useDefaults = in_array('--defaults', $flags, true);
    if ($useDefaults) {
        $cfgDir = getenv('PMSS_CONFIG_DIR');
        if (!is_string($cfgDir) || $cfgDir==='') { $cfgDir = '/etc/seedbox/config'; }
        $policyFile = rtrim($cfgDir,'/').'/cgroup.policy.php';
        $policy = [];
        if (file_exists($policyFile)) { $loaded = @include $policyFile; if (is_array($loaded)) { $policy = $loaded; } }
        foreach ([['cpu-weight','cpuWeight'],['io-weight','ioWeight'],['tasks-max','tasksMax'],['cpu-quota-percent','cpuQuotaPercent']] as $map) {
            if (!isset($opt[$map[0]]) && isset($policy[$map[1]]) && is_numeric($policy[$map[1]])) {
                $opt[$map[0]] = (string)$policy[$map[1]];
            }
        }
        if (!isset($opt['memory-high']) && isset($policy['memoryHighMiB']) && is_numeric($policy['memoryHighMiB'])) {
            $opt['memory-high'] = (string)$policy['memoryHighMiB'];
        }
        if (!isset($opt['memory-max']) && isset($policy['memoryMaxMiB']) && is_numeric($policy['memoryMaxMiB'])) {
            $opt['memory-max'] = (string)$policy['memoryMaxMiB'];
        }
    }
    if ($wantConfig) showConfig($slice);
    if ($wantStatus) showStatus($slice, $uid);

    // Profiles: CPU/mem/tasks shorthand (expand into concrete values before computing props)
    if (!empty($opt)) {
        if (isset($opt['cpu-profile']) && !isset($opt['cpu-weight'])) {
            switch ($opt['cpu-profile']) {
                case 'low':     $opt['cpu-weight'] = '50'; break;
                case 'high':    $opt['cpu-weight'] = '300'; break;
                default:        $opt['cpu-weight'] = '100'; break;
            }
        }
        if (isset($opt['tasks-profile']) && !isset($opt['tasks-max'])) {
            switch ($opt['tasks-profile']) {
                case 'low':     $opt['tasks-max'] = '1024'; break;
                case 'high':    $opt['tasks-max'] = '8192'; break;
                default:        $opt['tasks-max'] = '4096'; break;
            }
        }
        if (isset($opt['mem-profile'])) {
            // Only set if not explicitly provided
            if (!isset($opt['memory-high'])) {
                switch ($opt['mem-profile']) {
                    case 'low':     $opt['memory-high'] = '250'; break;
                    case 'heavy':   $opt['memory-high'] = '1024'; break;
                    default:        $opt['memory-high'] = '500'; break;
                }
            }
            // memory-max will be derived if not provided
        }
    }

    // Resolve device (for IO profile shorthands)
    $devResolved = '';
    if ($device !== '') {
        if (strpos($device, '/dev/') === 0) {
            $devResolved = $device;
        } else {
            if ($device === '/home') {
                $homeDev = getenv('PMSS_HOME_DEVICE');
                if (is_string($homeDev) && $homeDev !== '') {
                    $devResolved = $homeDev;
                } else {
                    $devResolved = trim((string)@shell_exec('findmnt -no SOURCE /home 2>/dev/null'));
                }
            } else {
                $devResolved = trim((string)@shell_exec('findmnt -no SOURCE '.escapeshellarg($device).' 2>/dev/null'));
            }
        }
    }

    // Parse IO throttles regardless of other flags; always plan/print IO when present
    $ioPairs = [];
    $ioMap = [
        'io-read-bw'   => 'IOReadBandwidthMax',
        'io-write-bw'  => 'IOWriteBandwidthMax',
        'io-read-iops' => 'IOReadIOPSMax',
        'io-write-iops'=> 'IOWriteIOPSMax',
    ];
    foreach ($ioMap as $flag=>$prop) {
        foreach ($flags as $f) {
            if (strpos($f, '--'.$flag.'=') === 0) {
                $spec = substr($f, strlen('--'.$flag.'=')); // e.g., /dev/sda:5M
                $ioPairs[] = $prop.'='.str_replace(':',' ', $spec);
                $hasIoFlag = true;
            }
        }
    }

    // Apply IO profile expansion if requested and device resolves
    if ($ioProfile !== '' && $devResolved !== '') {
        switch ($ioProfile) {
            case 'hdd':
                if (!isset($opt['io-weight'])) { $opt['io-weight'] = '200'; }
                $ioPairs[] = 'IOReadBandwidthMax='.$devResolved.' 5M';
                $ioPairs[] = 'IOWriteBandwidthMax='.$devResolved.' 10M';
                $ioPairs[] = 'IOReadIOPSMax='.$devResolved.' 100';
                $ioPairs[] = 'IOWriteIOPSMax='.$devResolved.' 100';
                break;
            case 'nvme':
                if (!isset($opt['io-weight'])) { $opt['io-weight'] = '200'; }
                // No throttles by default
                break;
            case 'bulk':
                if (!isset($opt['io-weight'])) { $opt['io-weight'] = '500'; }
                if (!isset($opt['cpu-weight'])) { $opt['cpu-weight'] = '300'; }
                if (!isset($opt['tasks-max'])) { $opt['tasks-max'] = '8192'; }
                break;
        }
    }

    // Default view when no change flags are present
    if (!$wantStatus && !$wantConfig) {
        $hasPlanInput = !empty($opt) || !empty($ioPairs) || $device !== '' || $ioProfile !== '' || $hasIoFlag || in_array('--wipe', $flags, true);
        if (!$hasPlanInput) { $wantStatus = $wantConfig = true; }
    }

    // Compute final properties after all profile expansions
    $props = !empty($opt) ? computeSetProps($opt, totalMemMiB()) : [];

    // If requested, avoid overwriting existing properties when applying defaults.
    if ($useDefaults && $respectExisting && !empty($props)) {
        $current = readCurrentProps($slice);
        foreach (array_keys($props) as $k) {
            if (isset($current[$k]) && trim((string)$current[$k]) !== '') {
                unset($props[$k]);
            }
        }
    }

    if (!empty($props)) {
        echo "\n[Planned properties]\n";
        foreach ($props as $k=>$v) { echo "$k=$v\n"; }
    }
    if (!empty($ioPairs)) {
        echo "[Planned IO properties]\n";
        foreach ($ioPairs as $p) echo $p."\n";
    }

    // Apply or simulate changes when there is something to do (props, IO pairs, or wipe)
    $doWipe = in_array('--wipe', $flags, true);
    $hasPlan = !empty($props) || !empty($ioPairs) || $doWipe || $hasIoFlag;
    if ($hasPlan) {
        if ($apply && !$dryRun) {
            requireRoot();
            if ($doWipe) {
                // Revert slice and unlimit core props safely
                runStep('Reverting user slice', 'systemctl revert '.escapeshellarg($slice).' || true');
                runStep('Unlimiting core properties', 'systemctl set-property '.escapeshellarg($slice).' MemoryHigh=infinity MemoryMax=infinity TasksMax=infinity CPUWeight=100 IOWeight=100');
            } else {
                $pairs = [];
                foreach ($props as $k=>$v) { $pairs[] = $k.'='.$v; }
                $allPairs = array_merge($pairs, $ioPairs);
                $cmd = 'systemctl set-property '.escapeshellarg($slice).' '.implode(' ', $allPairs);
                runStep('Applying cgroup properties', $cmd);
            }
        } else {
            echo "(dry-run or no --apply; not changing system)\n";
        }
    }
    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(pmssUserConfigCgroupMain($argv));
}
