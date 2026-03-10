<?php
/**
 * Library helper: Manager.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

require_once __DIR__ . '/SystemInterface.php';
require_once __DIR__ . '/../update/runtime/commands.php'; // for runStep

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
        $policyIoPairs = [];

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
            $policyIoPairs = $this->applyDefaults($opt);
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

        // Policy mount defaults apply only when no explicit IO input is given.
        if (!empty($policyIoPairs) && !$hasIoFlag && $ioProfile === '' && $device === '') {
            $ioPairs = array_merge($ioPairs, $policyIoPairs);
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
            // Derived Max: 1.25x High, but capped at High + 2048 MiB (2GB) headroom
            $derived = (int)floor($memoryHighMiB * 1.25);
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

    private function applyDefaults(array &$opt): array
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

        return $this->buildPolicyIoPairs($policy);
    }

    /**
     * Expand policy mount defaults into systemd IO property pairs.
     *
     * Each mount entry may define ioWeight, read/write bandwidth, and
     * read/write IOPS. Duplicate property+device pairs keep the latest value.
     */
    private function buildPolicyIoPairs(array $policy): array
    {
        if (!isset($policy['mounts']) || !is_array($policy['mounts'])) {
            return [];
        }

        $pairsByKey = [];
        foreach ($policy['mounts'] as $mountPath => $mountPolicy) {
            if (!is_string($mountPath) || $mountPath === '' || !is_array($mountPolicy)) {
                continue;
            }

            $devicePath = '';
            if (strpos($mountPath, '/dev/') === 0) {
                $devicePath = trim($mountPath);
            } else {
                $devicePath = trim($this->sys->resolveDevice($mountPath));
            }

            if ($devicePath === '') {
                continue;
            }

            if (isset($mountPolicy['ioWeight']) && is_numeric($mountPolicy['ioWeight'])) {
                $ioWeight = (int)$mountPolicy['ioWeight'];
                if ($ioWeight > 0) {
                    $pairsByKey['IODeviceWeight|'.$devicePath] = 'IODeviceWeight='.$devicePath.' '.$ioWeight;
                }
            }

            foreach ([
                ['readBw', 'IOReadBandwidthMax'],
                ['writeBw', 'IOWriteBandwidthMax'],
            ] as $mapping) {
                $policyKey = $mapping[0];
                $propertyName = $mapping[1];
                if (isset($mountPolicy[$policyKey]) && is_string($mountPolicy[$policyKey])) {
                    $limitValue = trim($mountPolicy[$policyKey]);
                    if ($limitValue !== '') {
                        $pairsByKey[$propertyName.'|'.$devicePath] = $propertyName.'='.$devicePath.' '.$limitValue;
                    }
                }
            }

            foreach ([
                ['readIops', 'IOReadIOPSMax'],
                ['writeIops', 'IOWriteIOPSMax'],
            ] as $mapping) {
                $policyKey = $mapping[0];
                $propertyName = $mapping[1];
                if (isset($mountPolicy[$policyKey]) && is_numeric($mountPolicy[$policyKey])) {
                    $limitValue = (int)$mountPolicy[$policyKey];
                    if ($limitValue > 0) {
                        $pairsByKey[$propertyName.'|'.$devicePath] = $propertyName.'='.$devicePath.' '.$limitValue;
                    }
                }
            }
        }

        return array_values($pairsByKey);
    }

    private function expandProfiles(array &$opt): void
    {
        if (isset($opt['cpu-profile']) && !isset($opt['cpu-weight'])) {
            static $cpuWeights = [
                'low'  => '50',
                'high' => '300',
            ];
            $opt['cpu-weight'] = $cpuWeights[$opt['cpu-profile']] ?? '100';
        }
        if (isset($opt['tasks-profile']) && !isset($opt['tasks-max'])) {
            static $tasksMax = [
                'low'  => '1024',
                'high' => '8192',
            ];
            $opt['tasks-max'] = $tasksMax[$opt['tasks-profile']] ?? '4096';
        }
        if (isset($opt['mem-profile'])) {
            if (!isset($opt['memory-high'])) {
                static $memoryHigh = [
                    'low'   => '250',
                    'heavy' => '1024',
                ];
                $opt['memory-high'] = $memoryHigh[$opt['mem-profile']] ?? '500';
            }
        }
    }

    private function applyIoProfile(string $profile, string $dev, array &$opt, array &$pairs): void
    {
        static $profiles = [
            'hdd' => [
                'defaults' => ['io-weight' => '200'],
                'pairs'    => [
                    'IOReadBandwidthMax=%s 5M',
                    'IOWriteBandwidthMax=%s 10M',
                    'IOReadIOPSMax=%s 100',
                    'IOWriteIOPSMax=%s 100',
                ],
            ],
            'nvme' => [
                'defaults' => ['io-weight' => '200'],
                'pairs'    => [],
            ],
            'bulk' => [
                'defaults' => [
                    'io-weight'  => '500',
                    'cpu-weight' => '300',
                    'tasks-max'  => '8192',
                ],
                'pairs' => [],
            ],
        ];

        $entry = $profiles[$profile] ?? null;
        if (!is_array($entry)) {
            return;
        }

        foreach ($entry['defaults'] as $key => $value) {
            if (!isset($opt[$key])) {
                $opt[$key] = $value;
            }
        }

        foreach ($entry['pairs'] as $pair) {
            $pairs[] = sprintf($pair, $dev);
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
