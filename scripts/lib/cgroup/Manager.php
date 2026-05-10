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

        $flagSet = array_flip($flags);
        $opt = [];
        $wantStatus = isset($flagSet['--status']);
        $wantConfig = isset($flagSet['--config']);
        $apply      = isset($flagSet['--apply']);
        $dryRun     = isset($flagSet['--dry-run']);
        $respectExisting = isset($flagSet['--respect-existing']);
        $defaultsRequested = isset($flagSet['--defaults']);
        $doWipe = isset($flagSet['--wipe']);
        $device = '';
        $ioProfile = '';
        $ioCostQos = '';
        $ioCostModel = '';
        $hasIoFlag = false;
        $ioPairs = [];
        $policyIoPairs = [];
        $ioCostWrites = [];
        $ioMap = ['io-read-bw' => 'IOReadBandwidthMax', 'io-write-bw' => 'IOWriteBandwidthMax', 'io-read-iops' => 'IOReadIOPSMax', 'io-write-iops' => 'IOWriteIOPSMax'];
        $optTargets = ['cpu-weight' => true, 'io-weight' => true, 'tasks-max' => true, 'memory-high' => true, 'memory-max' => true, 'cpu-quota-percent' => true, 'io-latency-ms' => true, 'cpu-profile' => true, 'mem-profile' => true, 'tasks-profile' => true];
        $ioSpecs = [];
        $optLowercase = ['cpu-profile' => true, 'mem-profile' => true, 'tasks-profile' => true];
        $scalarTargets = ['device' => 'device', 'io-profile' => 'ioProfile', 'io-cost-qos' => 'ioCostQos', 'io-cost-model' => 'ioCostModel'];

        // Scan value flags once, then replay IO specs in canonical property order.
        foreach ($flags as $flag) {
            if (strpos($flag, '--') !== 0 || ($separator = strpos($flag, '=')) === false) {
                continue;
            }

            $name = substr($flag, 2, $separator - 2);
            $value = substr($flag, $separator + 1);

            if (isset($ioMap[$name])) {
                $ioSpecs[$name][] = $value;
                $hasIoFlag = true;
                continue;
            }

            if (isset($scalarTargets[$name])) {
                $target = $scalarTargets[$name];
                $$target = ($name === 'io-profile') ? strtolower($value) : $value;
                continue;
            }

            if (isset($optTargets[$name])) {
                $opt[$name] = isset($optLowercase[$name]) ? strtolower($value) : $value;
            }
        }

        foreach ($ioMap as $flagName => $propertyName) {
            foreach ($ioSpecs[$flagName] ?? [] as $spec) {
                $parsedIoPair = $this->parseIoPropertyPair($propertyName, $spec);
                if ($parsedIoPair === null) {
                    fwrite(STDERR, 'Invalid --'.$flagName.' specification: '.$spec."\n");
                    return 2;
                }
                $ioPairs[] = $parsedIoPair;
            }
        }

        if (($invalidMessage = $this->validateFlagOptions($opt, $ioCostQos, $ioCostModel)) !== null) {
            fwrite(STDERR, $invalidMessage."\n");
            return 2;
        }

        // Defaults policy
        if ($defaultsRequested) {
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

        if ($ioCostQos !== '' || $ioCostModel !== '') {
            $ioCostPlan = $this->buildIoCostWrites($slice, $mode, $devResolved, $ioCostQos, $ioCostModel);
            if (!empty($ioCostPlan['messages'])) {
                foreach ($ioCostPlan['messages'] as $message) {
                    echo $message."\n";
                }
            }
            if (!empty($ioCostPlan['writes'])) {
                $ioCostWrites = $ioCostPlan['writes'];
            }
        }

        // Policy mount defaults apply only when no explicit IO input is given.
        if (!empty($policyIoPairs) && !$hasIoFlag && $ioProfile === '' && $device === '') {
            $ioPairs = array_merge($ioPairs, $policyIoPairs);
        }

        // Default view if no actions
        if (!$wantStatus && !$wantConfig) {
            $hasPlanInput = !empty($opt) || !empty($ioPairs) || !empty($ioCostWrites) || $device !== '' || $ioProfile !== '' || $hasIoFlag || $doWipe;
            if (!$hasPlanInput) {
                $this->showConfig($slice);
                $this->showStatus($slice, $uid);
            }
        }

        $sysMem = $this->sys->getTotalMemoryMiB();
        $props = !empty($opt) ? $this->computeSetProps($opt, $sysMem) : [];

        if ($defaultsRequested && $respectExisting && !empty($props)) {
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
        if (!empty($ioCostWrites)) {
            echo "[Planned io.cost writes]\n";
            foreach ($ioCostWrites as $write) {
                echo $write['path'].' <= '.$write['value']."\n";
            }
        }

        $hasPlan = !empty($props) || !empty($ioPairs) || !empty($ioCostWrites) || $doWipe || $hasIoFlag;

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
                    if (!empty($allPairs)) {
                        $cmd = \pmssBuildCommand('systemctl', array_merge(['set-property', $slice], $allPairs));
                        \runStep('Applying cgroup properties', $cmd);
                    }
                    foreach ($ioCostWrites as $write) {
                        $cmd = $this->buildIoCostWriteCommand($write['path'], $write['value']);
                        \runStep('Applying io.cost setting', $cmd);
                    }
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
    private function validateFlagOptions(array $opt, string $ioCostQos, string $ioCostModel): ?string
    {
        foreach (['cpu-weight', 'io-weight', 'tasks-max', 'memory-high', 'memory-max', 'io-latency-ms'] as $key) {
            if (isset($opt[$key]) && preg_match('/^-?[0-9]+$/', (string)$opt[$key]) !== 1) {
                return 'Invalid --'.$key.' value: expected integer';
            }
        }

        if (isset($opt['io-latency-ms']) && (int)$opt['io-latency-ms'] <= 0) {
            return 'Invalid --io-latency-ms value: expected positive integer';
        }

        if (!$this->isValidIoCostSetting($ioCostQos)) {
            return 'Invalid --io-cost-qos value: newline and NUL bytes are not allowed';
        }
        if (!$this->isValidIoCostSetting($ioCostModel)) {
            return 'Invalid --io-cost-model value: newline and NUL bytes are not allowed';
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

        foreach (['cpu-weight' => 'cpuWeight', 'io-weight' => 'ioWeight', 'tasks-max' => 'tasksMax', 'cpu-quota-percent' => 'cpuQuotaPercent', 'io-latency-ms' => 'ioLatencyMs', 'memory-high' => 'memoryHighMiB', 'memory-max' => 'memoryMaxMiB'] as $optionKey => $policyKey) {
            if (!isset($opt[$optionKey]) && isset($policy[$policyKey]) && is_numeric($policy[$policyKey])) {
                $opt[$optionKey] = (string)$policy[$policyKey];
            }
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

            foreach ([['ioWeight', 'IODeviceWeight', true], ['readBw', 'IOReadBandwidthMax', false], ['writeBw', 'IOWriteBandwidthMax', false], ['readIops', 'IOReadIOPSMax', true], ['writeIops', 'IOWriteIOPSMax', true]] as $mapping) {
                $policyKey = $mapping[0];
                $propertyName = $mapping[1];
                $numeric = $mapping[2];
                if (!isset($mountPolicy[$policyKey])) {
                    continue;
                }

                if ($numeric) {
                    if (!is_numeric($mountPolicy[$policyKey]) || ($value = (int)$mountPolicy[$policyKey]) <= 0) {
                        continue;
                    }
                } else {
                    $value = trim((string)$mountPolicy[$policyKey]);
                    if ($value === '') {
                        continue;
                    }
                }

                $pairsByKey[$propertyName.'|'.$devicePath] = $propertyName.'='.$devicePath.' '.$value;
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

                foreach ([['readBw', false], ['writeBw', false], ['readIops', true], ['writeIops', true]] as $mapping) {
                    $limitKey = $mapping[0];
                    $numeric = $mapping[1];
                    if (!isset($profileConfig[$limitKey])) {
                        continue;
                    }

                    if ($numeric) {
                        if (!is_numeric($profileConfig[$limitKey]) || ($limitValue = (int)$profileConfig[$limitKey]) <= 0) {
                            continue;
                        }
                    } else {
                        $limitValue = trim((string)$profileConfig[$limitKey]);
                        if ($limitValue === '') {
                            continue;
                        }
                    }

                    $resolvedProfile['limits'][$limitKey] = $limitValue;
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

    /** Validate free-form io.cost setting strings before shell execution. */
    private function isValidIoCostSetting(string $value): bool
    {
        return $value === ''
            || (strpos($value, "\0") === false && strpos($value, "\n") === false && strpos($value, "\r") === false);
    }

    /**
     * Build io.cost write operations for qos/model with scheduler safeguards.
     *
     * @return array<string,array<int,array<string,string>>|array<int,string>>
     */
    private function buildIoCostWrites(string $slice, string $mode, string $resolvedDevice, string $ioCostQos, string $ioCostModel): array
    {
        $messages = [];
        $writes = [];

        if ($mode !== 'v2') {
            $messages[] = '[SKIP] io.cost requires cgroup v2';
            return ['writes' => $writes, 'messages' => $messages];
        }

        if ($resolvedDevice === '') {
            $resolvedDevice = trim((string) $this->sys->resolveDevice('/home'));
        }
        if ($resolvedDevice === '') {
            $messages[] = '[WARN] io.cost skipped: unable to resolve /home backing device';
            return ['writes' => $writes, 'messages' => $messages];
        }

        $majorMinor = $this->resolveIoCostMajorMinor($resolvedDevice);
        if ($majorMinor === '') {
            $messages[] = '[WARN] io.cost skipped: unable to resolve major:minor for '.$resolvedDevice;
            return ['writes' => $writes, 'messages' => $messages];
        }

        $bfqProbe = trim((string) $this->sys->execute("grep -l '\\[bfq\\]' /sys/class/block/*/queue/scheduler 2>/dev/null | head -n 1"));
        if ($bfqProbe !== '') {
            $messages[] = '[SKIP] io.cost skipped: BFQ scheduler active ('.$bfqProbe.')';
            return ['writes' => $writes, 'messages' => $messages];
        }

        foreach ([
            ['io.cost.qos', $ioCostQos],
            ['io.cost.model', $ioCostModel],
        ] as $entry) {
            $fileName = $entry[0];
            $setting = trim((string) $entry[1]);
            if ($setting === '') {
                continue;
            }
            $normalized = $this->normalizeIoCostWriteValue($setting, $majorMinor);
            if ($normalized === null) {
                $messages[] = '[WARN] io.cost skipped: invalid '.$fileName.' setting';
                continue;
            }

            $writes[] = ['path' => '/sys/fs/cgroup/'.$fileName, 'value' => $normalized];
            $slicePath = '/sys/fs/cgroup/user.slice/'.$slice.'/'.$fileName;
            if ($this->sys->readFile($slicePath) !== null) {
                $writes[] = ['path' => $slicePath, 'value' => $normalized];
            }
        }

        if (!empty($writes)) {
            $messages[] = '[INFO] io.cost target '.$resolvedDevice.' ('.$majorMinor.')';
        }

        return ['writes' => $writes, 'messages' => $messages];
    }

    /** Resolve device major:minor in decimal form for io.cost lines. */
    private function resolveIoCostMajorMinor(string $devicePath): string
    {
        $majorMinor = trim((string) $this->sys->execute('lsblk -dn -o MAJ:MIN '.escapeshellarg($devicePath).' 2>/dev/null'));
        if (preg_match('/^[0-9]+:[0-9]+$/', $majorMinor) === 1) {
            return $majorMinor;
        }

        if (strpos($devicePath, '/dev/') === 0) {
            $blockName = basename($devicePath);
            if ($blockName !== '') {
                $devValue = trim((string) $this->sys->readFile('/sys/class/block/'.$blockName.'/dev'));
                if (preg_match('/^[0-9]+:[0-9]+$/', $devValue) === 1) {
                    return $devValue;
                }
            }
        }

        return '';
    }

    /** Prefix plain nested keys with the resolved major:minor device token. */
    private function normalizeIoCostWriteValue(string $setting, string $majorMinor): ?string
    {
        if ($setting === '') {
            return null;
        }
        if (preg_match('/^[0-9]+:[0-9]+\s+/', $setting) === 1) {
            return $setting;
        }
        return $majorMinor.' '.$setting;
    }

    /** Build a shell-safe writer command for cgroup io.cost files. */
    private function buildIoCostWriteCommand(string $path, string $value): string
    {
        $script = 'if [ -w '.escapeshellarg($path).' ]; then printf \'%s\\n\' '
            .escapeshellarg($value)
            .' > '.escapeshellarg($path)
            .'; else echo '.escapeshellarg('[SKIP] io.cost path not writable: '.$path).'; fi';
        return \pmssBuildCommand('sh', ['-c', $script]);
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
            '  /scripts/util/userConfigCgroup.php USERNAME --apply [--dry-run] [--defaults] [--respect-existing] [--cpu-weight=N] [--io-weight=N] [--tasks-max=N] [--memory-high=MiB] [--memory-max=MiB] [--cpu-quota-percent=N|infinity] [--io-latency-ms=MS] [--io-cost-qos=SETTING] [--io-cost-model=SETTING] [--device=/dev/DEV|/home] [--io-profile=hdd|nvme|bulk] [--io-read-bw=/dev/DEV:RATE] [--io-write-bw=/dev/DEV:RATE] [--io-read-iops=/dev/DEV:IOPS] [--io-write-iops=/dev/DEV:IOPS] [--wipe]',
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
            \pmssCliHelpLine('--io-cost-qos=SETTING', 'io.cost.qos nested keys; defaults to the /home backing device major:minor.'),
            \pmssCliHelpLine('--io-cost-model=SETTING', 'io.cost.model nested keys; defaults to the /home backing device major:minor.'),
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
            '  /scripts/util/userConfigCgroup.php alice --apply --dry-run --memory-high=1024 --cpu-weight=320 --io-weight=320 --cpu-quota-percent=125 --io-latency-ms=50 --io-cost-qos="enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00"',
            '  /scripts/util/userConfigCgroup.php alice --apply --defaults --device=/home --io-profile=hdd',
            '',
            \pmssCliHelpHeading('Notes', $useColor),
            '  - Help is available without needing a real user lookup; normal runs still require an existing passwd entry.',
            '  - MemoryHigh below 250 MiB is raised to the PMSS floor before applying properties.',
            '  - io.cost writes are skipped when BFQ is active on any block scheduler queue.',
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
                'io.cost.weight'  => $base.'/io.cost.weight',
                'io.cost.qos'     => $base.'/io.cost.qos',
                'io.cost.model'   => $base.'/io.cost.model',
                'cpu.weight'      => $base.'/cpu.weight',
            ];
            if ($this->sys->readFile($base.'/io.cost.qos') === null) {
                $pairs['io.cost.qos'] = '/sys/fs/cgroup/io.cost.qos';
            }
            if ($this->sys->readFile($base.'/io.cost.model') === null) {
                $pairs['io.cost.model'] = '/sys/fs/cgroup/io.cost.model';
            }
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
