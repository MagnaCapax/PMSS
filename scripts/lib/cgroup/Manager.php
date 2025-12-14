<?php

namespace PMSS\Cgroup;

require_once __DIR__ . '/SystemInterface.php';
require_once __DIR__ . '/../update/runtime/commands.php'; // for runStep
require_once __DIR__ . '/../runtime.php';

class Manager
{
    /** @var SystemInterface */
    private $sys;

    public function __construct(SystemInterface $sys)
    {
        $this->sys = $sys;
    }

    public function run(array $argv): int
    {
        $args = $argv;
        array_shift($args); // remove script name
        if (count($args) === 0) {
            fwrite(STDERR, "Usage: /scripts/util/userConfigCgroup.php USERNAME [--status] [--config] [--apply] [--dry-run] [--cpu-weight N] [--io-weight N] [--tasks-max N] [--memory-high MiB] [--memory-max MiB] [--cpu-quota-percent N]\n");
            return 2;
        }

        $user  = $args[0];
        $flags = array_slice($args, 1);
        $uid   = $this->sys->getUid($user);

        if ($uid < 0) {
            fwrite(STDERR, "Unknown user: $user\n");
            return 1;
        }

        $slice = "user-".$uid.".slice";
        $mode  = $this->sys->getCgroupMode();
        echo "user=$user uid=$uid slice=$slice mode=$mode\n";

        $opt = [];
        $wantStatus = in_array('--status', $flags, true);
        $wantConfig = in_array('--config', $flags, true);
        $apply      = in_array('--apply', $flags, true);
        $dryRun     = in_array('--dry-run', $flags, true);
        $respectExisting = in_array('--respect-existing', $flags, true);
        $device = '';
        $ioProfile = '';
        $hasIoFlag = false;

        // Flag parsing
        foreach (['--cpu-weight','--io-weight','--tasks-max','--memory-high','--memory-max','--device','--io-profile','--cpu-profile','--mem-profile','--tasks-profile','--cpu-quota-percent'] as $k) {
            foreach ($flags as $f) {
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

        // Defaults policy
        if (in_array('--defaults', $flags, true)) {
            $this->applyDefaults($opt);
        }

        if ($wantConfig) $this->showConfig($slice);
        if ($wantStatus) $this->showStatus($slice, $uid);

        // Profiles
        $this->expandProfiles($opt);

        // IO Device resolution
        $devResolved = '';
        if ($device !== '') {
            if (strpos($device, '/dev/') === 0) {
                $devResolved = $device;
            } else {
                $devResolved = $this->sys->resolveDevice($device);
            }
        }

        // IO Throttles
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
                    $spec = substr($f, strlen('--'.$flag.'='));
                    $ioPairs[] = $prop.'='.str_replace(':',' ', $spec);
                    $hasIoFlag = true;
                }
            }
        }

        // IO Profile
        if ($ioProfile !== '' && $devResolved !== '') {
            $this->applyIoProfile($ioProfile, $devResolved, $opt, $ioPairs);
        }

        // Default view if no actions
        if (!$wantStatus && !$wantConfig) {
            $hasPlanInput = !empty($opt) || !empty($ioPairs) || $device !== '' || $ioProfile !== '' || $hasIoFlag || in_array('--wipe', $flags, true);
            if (!$hasPlanInput) {
                $this->showConfig($slice);
                $this->showStatus($slice, $uid);
            }
        }

        $sysMem = $this->sys->getTotalMemoryMiB();
        $props = !empty($opt) ? $this->computeSetProps($opt, $sysMem) : [];

        if (in_array('--defaults', $flags, true) && $respectExisting && !empty($props)) {
            $current = $this->readCurrentProps($slice);
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

        $doWipe = in_array('--wipe', $flags, true);
        $hasPlan = !empty($props) || !empty($ioPairs) || $doWipe || $hasIoFlag;

        if ($hasPlan) {
            if ($apply && !$dryRun) {
                $this->sys->requireRoot();
                if ($doWipe) {
                    \runStep('Reverting user slice', 'systemctl revert '.escapeshellarg($slice).' || true');
                    \runStep('Unlimiting core properties', 'systemctl set-property '.escapeshellarg($slice).' MemoryHigh=infinity MemoryMax=infinity TasksMax=infinity CPUWeight=100 IOWeight=100');
                } else {
                    $pairs = [];
                    foreach ($props as $k=>$v) { $pairs[] = $k.'='.$v; }
                    $allPairs = array_merge($pairs, $ioPairs);
                    $cmd = 'systemctl set-property '.escapeshellarg($slice).' '.implode(' ', $allPairs);
                    \runStep('Applying cgroup properties', $cmd);
                }
            } else {
                echo "(dry-run or no --apply; not changing system)\n";
            }
        }

        return 0;
    }

    public function computeSetProps(array $opts, int $sysMemMiB): array
    {
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
            
            // Apply clamp: Max cannot exceed High + 2048 MiB
            $headroomCap = $memoryHighMiB + 2048;
            $memoryMax = max($memoryHighMiB, min($memoryMax, $maxCap, $headroomCap));
            
            $props['MemoryMax'] = $memoryMax.'M';
        } elseif ($memoryHighMiB !== null) {
            // Derived Max: 1.5x High, but capped at High + 2048 MiB (2GB) headroom
            $derived = (int)floor($memoryHighMiB * 1.5);
            $headroomCap = $memoryHighMiB + 2048;
            $memoryMax = min($derived, $headroomCap, $maxCap);
            $props['MemoryMax'] = $memoryMax.'M';
        }

        $derivedWeight = $memoryHighMiB !== null ? self::calculateWeightFromMemory($memoryHighMiB) : null;

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
            if ((is_string($quota) && strtolower($quota) === 'infinity') || (int)$quota === 0) {
                $props['CPUQuota'] = ''; // Empty string removes the limit
            } else {
                $pct = (int)$quota;
                if ($pct > 0) {
                    $props['CPUQuota'] = $pct.'%';
                }
            }
        }

        return $props;
    }

    /**
     * Derive a CPU/IO weight from a configured memory high watermark.
     *
     * Formula mirrors the original userConfigCgroup.php helper:
     *   weight = clamp(round(8 * sqrt(MiB)), 10, 1000)
     *
     * Exposed as a static helper so production probes (and other tools)
     * can reuse the calculation without reimplementing it.
     */
    public static function calculateWeightFromMemory(int $memoryHighMiB): int
    {
        if ($memoryHighMiB < 0) {
            $memoryHighMiB = 0;
        }
        $derived = (int) round(8 * sqrt($memoryHighMiB));
        if ($derived < 10) {
            $derived = 10;
        } elseif ($derived > 1000) {
            $derived = 1000;
        }
        return $derived;
    }

    private function applyDefaults(array &$opt): void
    {
        $cfgDir = \pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $policyFile = $cfgDir.'/cgroup.policy.php';
        $policy = [];
        // This file read is direct; ideally should be via SystemInterface if we want to mock policy
        // But for now we rely on filesystem if it exists.
        // To mock this, SystemInterface needs a 'includePolicy' or similar. 
        // For now, hermetic tests can mock getenv('PMSS_CONFIG_DIR') and write a temp file.
        if (file_exists($policyFile)) { 
            $loaded = @include $policyFile; 
            if (is_array($loaded)) { $policy = $loaded; } 
        }
        
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

    private function expandProfiles(array &$opt): void
    {
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
            if (!isset($opt['memory-high'])) {
                switch ($opt['mem-profile']) {
                    case 'low':     $opt['memory-high'] = '250'; break;
                    case 'heavy':   $opt['memory-high'] = '1024'; break;
                    default:        $opt['memory-high'] = '500'; break;
                }
            }
        }
    }

    private function applyIoProfile(string $profile, string $dev, array &$opt, array &$pairs): void
    {
        switch ($profile) {
            case 'hdd':
                if (!isset($opt['io-weight'])) { $opt['io-weight'] = '200'; }
                $pairs[] = 'IOReadBandwidthMax='.$dev.' 5M';
                $pairs[] = 'IOWriteBandwidthMax='.$dev.' 10M';
                $pairs[] = 'IOReadIOPSMax='.$dev.' 100';
                $pairs[] = 'IOWriteIOPSMax='.$dev.' 100';
                break;
            case 'nvme':
                if (!isset($opt['io-weight'])) { $opt['io-weight'] = '200'; }
                break;
            case 'bulk':
                if (!isset($opt['io-weight'])) { $opt['io-weight'] = '500'; }
                if (!isset($opt['cpu-weight'])) { $opt['cpu-weight'] = '300'; }
                if (!isset($opt['tasks-max'])) { $opt['tasks-max'] = '8192'; }
                break;
        }
    }

    private function showConfig(string $slice): void
    {
        echo "\n[Config] $slice\n";
        $props = ['CPUWeight','IOWeight','MemoryAccounting','CPUAccounting','IOAccounting','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec','CPUQuotaPeriodUSec'];
        $out = $this->sys->execute('systemctl show '.escapeshellarg($slice).' -p '.implode(',', $props));
        echo $out !== null ? trim($out)."\n" : "(no data)\n";
    }

    private function showStatus(string $slice, int $uid): void
    {
        $mode = $this->sys->getCgroupMode();
        echo "\n[Status] $slice (mode=$mode)\n";
        if ($mode === 'v2') {
            $base = "/sys/fs/cgroup/user.slice/user-".$uid.".slice";
            $pairs = [
                'pids.current'    => $base.'/pids.current',
                'memory.current'  => $base.'/memory.current',
                'memory.high'     => $base.'/memory.high',
                'memory.max'      => $base.'/memory.max',
                'io.stat'         => $base.'/io.stat',
                'cpu.weight'      => $base.'/cpu.weight',
            ];
            foreach ($pairs as $label => $path) {
                $val = $this->sys->readFile($path);
                if ($val === null) { echo "$label: (unavailable)\n"; } else { echo "$label: ".trim($val)."\n"; }
            }
        } else {
            $base = "/sys/fs/cgroup";
            $pids = $base."/pids/user.slice/user-".$uid.".slice/pids.current";
            $meml = $base."/memory/user.slice/user-".$uid.".slice/memory.limit_in_bytes";
            $memu = $base."/memory/user.slice/user-".$uid.".slice/memory.usage_in_bytes";
            foreach ([ 'pids.current' => $pids, 'memory.limit_in_bytes' => $meml, 'memory.usage_in_bytes' => $memu ] as $label => $path) {
                $val = $this->sys->readFile($path);
                if ($val === null) { echo "$label: (unavailable)\n"; } else { echo "$label: ".trim($val)."\n"; }
            }
        }
    }

    private function readCurrentProps(string $slice): array
    {
        $props = ['CPUWeight','IOWeight','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec'];
        $out = $this->sys->execute('systemctl show '.escapeshellarg($slice).' -p '.implode(' -p ', $props));
        $map = [];
        if (!is_string($out)) return $map;
        foreach (preg_split('/?
/', trim($out)) as $line) {
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
}
