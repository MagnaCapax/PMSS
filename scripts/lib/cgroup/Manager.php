<?php
/**
 * Library helper: Manager.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

require_once __DIR__ . '/SystemInterface.php';
require_once __DIR__ . '/../cli/helpText.php';
require_once __DIR__ . '/../systemdSliceProperties.php';
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
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            echo $this->usageText()."\n";
            return 0;
        }
        if (count($args) === 0) {
            fwrite(STDERR, $this->usageText()."\n");
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
        foreach (['--cpu-weight','--io-weight','--tasks-max','--memory-high','--memory-max','--device','--io-profile','--cpu-profile','--mem-profile','--tasks-profile','--cpu-quota-percent','--io-latency-ms'] as $k) {
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

        if (($invalidMessage = $this->validateFlagOptions($opt)) !== null) {
            fwrite(STDERR, $invalidMessage."\n");
            return 2;
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
                    $parsedIoPair = $this->parseIoPropertyPair($prop, $spec);
                    if ($parsedIoPair === null) {
                        fwrite(STDERR, 'Invalid --'.$flag.' specification: '.$spec."\n");
                        return 2;
                    }
                    $ioPairs[] = $parsedIoPair;
                    $hasIoFlag = true;
                }
            }
        }

        if ($device !== '' && preg_match('/\s/', $device) === 1) {
            fwrite(STDERR, "Invalid --device value: whitespace is not allowed\n");
            return 2;
        }

        if (isset($opt['io-latency-ms']) && $devResolved === '') {
            $devResolved = $this->sys->resolveDevice('/home');
        }

        // IO Profile
        if ($ioProfile !== '' && $devResolved !== '') {
            $this->applyIoProfile($ioProfile, $devResolved, $opt, $ioPairs);
        }

        if (isset($opt['io-latency-ms']) && (int)$opt['io-latency-ms'] > 0) {
            if ($mode === 'v2' && $devResolved !== '') {
                $ioPairs[] = 'IODeviceLatencyTargetSec='.$devResolved.' '.(int)$opt['io-latency-ms'].'ms';
                $hasIoFlag = true;
            } elseif ($mode !== 'v2') {
                echo "[SKIP] IODeviceLatencyTargetSec requires cgroup v2\n";
            }
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
                    \runStep('Reverting user slice', \pmssBuildCommand('systemctl', ['revert', $slice]).' || true');
                    \runStep(
                        'Unlimiting core properties',
                        \pmssBuildCommand('systemctl', [
                            'set-property',
                            $slice,
                            'MemoryHigh=infinity',
                            'MemoryMax=infinity',
                            'TasksMax=infinity',
                            'CPUWeight=100',
                            'IOWeight=100',
                        ])
                    );
                } else {
                    $pairs = [];
                    foreach ($props as $k=>$v) { $pairs[] = $k.'='.$v; }
                    $allPairs = array_merge($pairs, $ioPairs);
                    $cmd = \pmssBuildCommand('systemctl', array_merge(['set-property', $slice], $allPairs));
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
     * Reject malformed CLI values before they reach systemctl.
     */
    private function validateFlagOptions(array $opt): ?string
    {
        foreach (['cpu-weight', 'io-weight', 'tasks-max', 'memory-high', 'memory-max', 'io-latency-ms'] as $key) {
            if (isset($opt[$key]) && preg_match('/^-?[0-9]+$/', (string)$opt[$key]) !== 1) {
                return 'Invalid --'.$key.' value: expected integer';
            }
        }

        if (isset($opt['io-latency-ms']) && (int)$opt['io-latency-ms'] <= 0) {
            return 'Invalid --io-latency-ms value: expected positive integer';
        }

        if (!isset($opt['cpu-quota-percent'])) {
            return null;
        }

        $quota = (string)$opt['cpu-quota-percent'];
        if (strtolower($quota) === 'infinity' || preg_match('/^-?[0-9]+$/', $quota) === 1) {
            return null;
        }

        return 'Invalid --cpu-quota-percent value: expected integer or infinity';
    }

    /**
     * Convert explicit IO throttle input into a validated systemd property pair.
     */
    private function parseIoPropertyPair(string $propertyName, string $spec): ?string
    {
        if (preg_match('/^([^:\s]+):([^\s]+)$/', trim($spec), $matches) !== 1) {
            return null;
        }

        if (strpos($matches[1], '/dev/') !== 0 || strpos($matches[1], "\0") !== false || strpos($matches[2], "\0") !== false) {
            return null;
        }

        return $propertyName.'='.$matches[1].' '.$matches[2];
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
        $policy = $this->loadPolicy();
        
        foreach ([['cpu-weight','cpuWeight'],['io-weight','ioWeight'],['tasks-max','tasksMax'],['cpu-quota-percent','cpuQuotaPercent']] as $map) {
            if (!isset($opt[$map[0]]) && isset($policy[$map[1]]) && is_numeric($policy[$map[1]])) {
                $opt[$map[0]] = (string)$policy[$map[1]];
            }
        }
        if (!isset($opt['io-latency-ms']) && isset($policy['ioLatencyMs']) && is_numeric($policy['ioLatencyMs'])) {
            $opt['io-latency-ms'] = (string)$policy['ioLatencyMs'];
        }
        if (!isset($opt['memory-high']) && isset($policy['memoryHighMiB']) && is_numeric($policy['memoryHighMiB'])) {
            $opt['memory-high'] = (string)$policy['memoryHighMiB'];
        }
        if (!isset($opt['memory-max']) && isset($policy['memoryMaxMiB']) && is_numeric($policy['memoryMaxMiB'])) {
            $opt['memory-max'] = (string)$policy['memoryMaxMiB'];
        }

        if (!isset($policy['mounts']) || !is_array($policy['mounts'])) {
            return [];
        }

        $pairsByKey = [];
        foreach ($policy['mounts'] as $mountPath => $mountPolicy) {
            if (!is_string($mountPath) || $mountPath === '' || !is_array($mountPolicy)) {
                continue;
            }

            $devicePath = strpos($mountPath, '/dev/') === 0
                ? trim($mountPath)
                : trim($this->sys->resolveDevice($mountPath));
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

    /**
     * Load cgroup policy from PMSS config directory.
     */
    private function loadPolicy(): array
    {
        $cfgDir = \pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $policyFile = $cfgDir.'/cgroup.policy.php';
        $policy = [];

        if (is_file($policyFile)) {
            $loaded = @include $policyFile;
            if (is_array($loaded)) {
                $policy = $loaded;
            }
        }

        return $policy;
    }

    private function expandProfiles(array &$opt): void
    {
        $cpuWeights = $this->resolveNumericProfiles('cpu', [
            'low'  => '50',
            'high' => '300',
        ]);

        $tasksMax = $this->resolveNumericProfiles('tasks', [
            'low'  => '1024',
            'high' => '8192',
        ]);

        $memoryHigh = $this->resolveNumericProfiles('mem', [
            'low'   => '250',
            'heavy' => '1024',
        ]);

        if (isset($opt['cpu-profile']) && !isset($opt['cpu-weight'])) {
            $profileName = strtolower($opt['cpu-profile']);
            $opt['cpu-profile'] = $profileName;
            $opt['cpu-weight'] = $cpuWeights[$profileName] ?? '100';
        }

        if (isset($opt['tasks-profile']) && !isset($opt['tasks-max'])) {
            $profileName = strtolower($opt['tasks-profile']);
            $opt['tasks-profile'] = $profileName;
            $opt['tasks-max'] = $tasksMax[$profileName] ?? '4096';
        }

        if (isset($opt['mem-profile'])) {
            $profileName = strtolower($opt['mem-profile']);
            $opt['mem-profile'] = $profileName;
            if (!isset($opt['memory-high'])) {
                $opt['memory-high'] = $memoryHigh[$profileName] ?? '500';
            }
        }
    }

    /**
     * Resolve numeric profile maps from policy, preserving built-in defaults.
     */
    private function resolveNumericProfiles(string $family, array $defaults): array
    {
        $resolved = $defaults;
        $policy = $this->loadPolicy();

        if (!isset($policy['profiles']) || !is_array($policy['profiles'])) {
            return $resolved;
        }

        if (!isset($policy['profiles'][$family]) || !is_array($policy['profiles'][$family])) {
            return $resolved;
        }

        foreach ($policy['profiles'][$family] as $profileName => $profileValue) {
            if (!is_string($profileName) || $profileName === '' || !is_numeric($profileValue)) {
                continue;
            }

            $numericValue = (int)$profileValue;
            if ($numericValue <= 0) {
                continue;
            }

            $resolved[strtolower($profileName)] = (string)$numericValue;
        }

        return $resolved;
    }

    private function applyIoProfile(string $profile, string $dev, array &$opt, array &$pairs): void
    {
        $profiles = [
            'hdd' => [
                'defaults' => ['io-weight' => '200'],
                'limits'   => ['readBw' => '5M', 'writeBw' => '10M', 'readIops' => 100, 'writeIops' => 100],
            ],
            'nvme' => [
                'defaults' => ['io-weight' => '200'],
                'limits'   => [],
            ],
            'bulk' => [
                'defaults' => ['io-weight' => '500', 'cpu-weight' => '300', 'tasks-max' => '8192'],
                'limits'   => [],
            ],
        ];

        $policy = $this->loadPolicy();
        if (isset($policy['profiles']['io']) && is_array($policy['profiles']['io'])) {
            foreach ($policy['profiles']['io'] as $profileName => $profileConfig) {
                if (!is_string($profileName) || $profileName === '' || !is_array($profileConfig)) {
                    continue;
                }

                $resolvedName = strtolower($profileName);
                $resolvedProfile = isset($profiles[$resolvedName]) && is_array($profiles[$resolvedName])
                    ? $profiles[$resolvedName]
                    : ['defaults' => [], 'limits' => []];
                $hasValidOverride = false;

                foreach ([['ioWeight', 'io-weight'], ['cpuWeight', 'cpu-weight'], ['tasksMax', 'tasks-max']] as $mapping) {
                    $policyKey = $mapping[0];
                    $targetKey = $mapping[1];
                    if (!isset($profileConfig[$policyKey]) || !is_numeric($profileConfig[$policyKey])) {
                        continue;
                    }
                    $numeric = (int)$profileConfig[$policyKey];
                    if ($numeric <= 0) {
                        continue;
                    }
                    $resolvedProfile['defaults'][$targetKey] = (string)$numeric;
                    $hasValidOverride = true;
                }

                foreach (['readBw', 'writeBw'] as $bandwidthKey) {
                    if (!isset($profileConfig[$bandwidthKey]) || !is_string($profileConfig[$bandwidthKey])) {
                        continue;
                    }
                    $limitValue = trim($profileConfig[$bandwidthKey]);
                    if ($limitValue === '') {
                        continue;
                    }
                    $resolvedProfile['limits'][$bandwidthKey] = $limitValue;
                    $hasValidOverride = true;
                }

                foreach (['readIops', 'writeIops'] as $iopsKey) {
                    if (!isset($profileConfig[$iopsKey]) || !is_numeric($profileConfig[$iopsKey])) {
                        continue;
                    }
                    $limitValue = (int)$profileConfig[$iopsKey];
                    if ($limitValue <= 0) {
                        continue;
                    }
                    $resolvedProfile['limits'][$iopsKey] = $limitValue;
                    $hasValidOverride = true;
                }

                if ($hasValidOverride) {
                    $profiles[$resolvedName] = $resolvedProfile;
                }
            }
        }

        $entry = isset($profiles[$profile]) ? $profiles[$profile] : null;
        if (!is_array($entry)) {
            return;
        }

        if (isset($entry['defaults']) && is_array($entry['defaults'])) {
            foreach ($entry['defaults'] as $key => $value) {
                if (!isset($opt[$key])) {
                    $opt[$key] = $value;
                }
            }
        }

        if (!isset($entry['limits']) || !is_array($entry['limits'])) {
            return;
        }

        foreach ([
            ['readBw', 'IOReadBandwidthMax'],
            ['writeBw', 'IOWriteBandwidthMax'],
            ['readIops', 'IOReadIOPSMax'],
            ['writeIops', 'IOWriteIOPSMax'],
        ] as $mapping) {
            $limitKey = $mapping[0];
            $propertyName = $mapping[1];
            if (!array_key_exists($limitKey, $entry['limits'])) {
                continue;
            }
            $pairs[] = $propertyName.'='.$dev.' '.(string)$entry['limits'][$limitKey];
        }
    }

    private function showConfig(string $slice): void
    {
        echo "\n[Config] $slice\n";
        $props = ['CPUWeight','IOWeight','IODeviceLatencyTargetSec','MemoryAccounting','CPUAccounting','IOAccounting','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec','CPUQuotaPeriodUSec'];
        $out = $this->sys->execute(\pmssBuildSystemdShowCommand($slice, $props));
        echo $out !== null ? trim($out)."\n" : "(no data)\n";
    }

    private function usageText(): string
    {
        $useColor = \pmssCliHelpSupportsColor();
        $derivedDefault = \pmssCliHelpDim(' (default: derive from MemoryHigh when omitted)', $useColor);
        $lines = [
            \pmssCliHelpHeading('Usage', $useColor),
            '  /scripts/util/userConfigCgroup.php USERNAME [--status] [--config]',
            '  /scripts/util/userConfigCgroup.php USERNAME --apply [--dry-run] [--defaults] [--respect-existing] [--cpu-weight=N] [--io-weight=N] [--tasks-max=N] [--memory-high=MiB] [--memory-max=MiB] [--cpu-quota-percent=N|infinity] [--io-latency-ms=MS] [--device=/dev/DEV|/home] [--io-profile=hdd|nvme|bulk] [--io-read-bw=/dev/DEV:RATE] [--io-write-bw=/dev/DEV:RATE] [--io-read-iops=/dev/DEV:IOPS] [--io-write-iops=/dev/DEV:IOPS] [--wipe]',
            '',
            \pmssCliHelpHeading('Actions', $useColor),
            \pmssCliHelpLine('--status', 'Show live slice counters from cgroupfs.'),
            \pmssCliHelpLine('--config', 'Show the current systemd slice properties.'),
            \pmssCliHelpLine('--apply', 'Apply the requested plan to the user slice.'),
            \pmssCliHelpLine('--dry-run', 'Print the planned properties without changing the system.'),
            \pmssCliHelpLine('--wipe', 'Reset the slice back to the PMSS baseline.'),
            '',
            \pmssCliHelpHeading('Resource Options', $useColor),
            \pmssCliHelpLine('--memory-high=MiB', 'MemoryHigh target in MiB; effective minimum is 250 MiB.'),
            \pmssCliHelpLine('--memory-max=MiB', 'MemoryMax target in MiB; capped to High + 2048 MiB.'),
            \pmssCliHelpLine('--cpu-weight=N', 'systemd CPUWeight; systemd expects 1-10000.'.$derivedDefault),
            \pmssCliHelpLine('--io-weight=N', 'systemd IOWeight; systemd expects 1-10000.'.$derivedDefault),
            \pmssCliHelpLine('--tasks-max=N', 'Process limit for the user slice; use a positive integer.'),
            \pmssCliHelpLine('--cpu-quota-percent=N|infinity', 'CPU quota percent; use 0 or infinity to remove the cap.'),
            \pmssCliHelpLine('--io-latency-ms=MS', 'IODeviceLatencyTargetSec target in milliseconds for the selected device or the /home backing device.'),
            \pmssCliHelpLine('--device=/dev/DEV|/home', 'Device selector for IO profiles and shorthand resolution.'),
            \pmssCliHelpLine('--io-profile=hdd|nvme|bulk', 'Apply a named IO profile to the selected device.'),
            \pmssCliHelpLine('--io-read-bw=/dev/DEV:RATE', 'Explicit read bandwidth cap, e.g. /dev/sda:20M.'),
            \pmssCliHelpLine('--io-write-bw=/dev/DEV:RATE', 'Explicit write bandwidth cap, e.g. /dev/sda:20M.'),
            \pmssCliHelpLine('--io-read-iops=/dev/DEV:IOPS', 'Explicit read IOPS cap, e.g. /dev/sda:500.'),
            \pmssCliHelpLine('--io-write-iops=/dev/DEV:IOPS', 'Explicit write IOPS cap, e.g. /dev/sda:500.'),
            '',
            \pmssCliHelpHeading('Profiles', $useColor),
            \pmssCliHelpLine('--defaults', 'Load PMSS policy defaults before applying explicit overrides.'),
            \pmssCliHelpLine('--respect-existing', 'Keep live properties when neither flags nor defaults set them.'),
            \pmssCliHelpLine('--cpu-profile=<name>', 'Apply a named CPU profile from cgroup.policy.php.'),
            \pmssCliHelpLine('--mem-profile=<name>', 'Apply a named memory profile from cgroup.policy.php.'),
            \pmssCliHelpLine('--tasks-profile=<name>', 'Apply a named TasksMax profile from cgroup.policy.php.'),
            \pmssCliHelpLine('-h, --help', 'Show this help and exit.'),
            '',
            \pmssCliHelpHeading('Examples', $useColor),
            '  /scripts/util/userConfigCgroup.php alice --status --config',
            '  /scripts/util/userConfigCgroup.php alice --apply --dry-run --memory-high=1024 --cpu-weight=320 --io-weight=320 --cpu-quota-percent=125 --io-latency-ms=50',
            '  /scripts/util/userConfigCgroup.php alice --apply --defaults --device=/home --io-profile=hdd',
            '',
            \pmssCliHelpHeading('Notes', $useColor),
            '  - Help is available without needing a real user lookup; normal runs still require an existing passwd entry.',
            '  - MemoryHigh below 250 MiB is raised to the PMSS floor before applying properties.',
        ];

        return implode("\n", $lines);
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
                'io.latency'      => $base.'/io.latency',
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
        $out = $this->sys->execute(\pmssBuildSystemdShowCommand($slice, $props));
        $map = is_string($out) ? \pmssParseSystemdPropertyOutput($props, $out) : [];
        if (isset($map['CPUQuotaPerSecUSec'])) {
            $map['CPUQuota'] = $map['CPUQuotaPerSecUSec'];
            unset($map['CPUQuotaPerSecUSec']);
        }
        return $map;
    }
}
