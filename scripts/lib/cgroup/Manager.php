<?php
/**
 * Library helper: Manager.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

require_once __DIR__ . '/SystemInterface.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/../cli/helpText.php';
require_once __DIR__ . '/../systemdSliceProperties.php';
require_once __DIR__ . '/../update/runtime/commands.php'; // for runStep

class Manager
{
    private const IO_CLI_PROPERTY_MAP = ['io-read-bw' => 'IOReadBandwidthMax', 'io-write-bw' => 'IOWriteBandwidthMax', 'io-read-iops' => 'IOReadIOPSMax', 'io-write-iops' => 'IOWriteIOPSMax'];
    private const RESOURCE_OPTION_NAMES = ['cpu-weight', 'io-weight', 'tasks-max', 'memory-high', 'memory-max', 'cpu-quota-percent', 'io-latency-ms', 'cpu-profile', 'mem-profile', 'tasks-profile'];
    private const INTEGER_OPTION_NAMES = ['cpu-weight', 'io-weight', 'tasks-max', 'memory-high', 'memory-max', 'io-latency-ms'];
    private const POLICY_OPTION_MAP = ['cpu-weight' => 'cpuWeight', 'io-weight' => 'ioWeight', 'tasks-max' => 'tasksMax', 'cpu-quota-percent' => 'cpuQuotaPercent', 'io-latency-ms' => 'ioLatencyMs', 'memory-high' => 'memoryHighMiB', 'memory-max' => 'memoryMaxMiB'];
    private const IO_PROFILE_MAP = [
        'hdd' => ['defaults' => ['io-weight' => '200'], 'limits' => ['readBw' => '5M', 'writeBw' => '10M', 'readIops' => 100, 'writeIops' => 100]],
        'nvme' => ['defaults' => ['io-weight' => '200'], 'limits' => []],
        'bulk' => ['defaults' => ['io-weight' => '500', 'cpu-weight' => '300', 'tasks-max' => '8192'], 'limits' => []],
    ];
    private const NUMERIC_PROFILE_MAP = [
        'cpu' => ['cpu-profile', 'cpu-weight', '100', ['low' => '50', 'high' => '300']],
        'tasks' => ['tasks-profile', 'tasks-max', '4096', ['low' => '1024', 'high' => '8192']],
        'mem' => ['mem-profile', 'memory-high', '500', ['low' => '250', 'heavy' => '1024']],
    ];

    /** @var SystemInterface */
    private $sys;

    /** @var callable */
    private $stepRunner;

    public function __construct(SystemInterface $sys, ?callable $stepRunner = null)
    {
        $this->sys = $sys;
        $this->stepRunner = $stepRunner ?? static function (string $description, string $command): int {
            return \runStep($description, $command);
        };
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
        $parseError = null;
        $parsedOptions = $this->parseFlagInputs($flags, $parseError);
        $inlineOptions = $parsedOptions['inline'];
        $uid   = $this->sys->getUid($user);

        if ($uid < 0) {
            fwrite(STDERR, "Unknown user: $user\n");
            return 1;
        }

        $slice = "user-".$uid.".slice";
        $mode  = $this->sys->getCgroupMode();
        echo "user=$user uid=$uid slice=$slice mode=$mode\n";

        $wantStatus = in_array('--status', $flags, true);
        $wantConfig = in_array('--config', $flags, true);
        $apply      = in_array('--apply', $flags, true);
        $dryRun     = in_array('--dry-run', $flags, true);
        $respectExisting = in_array('--respect-existing', $flags, true);
        $defaultsRequested = in_array('--defaults', $flags, true);
        $doWipe = in_array('--wipe', $flags, true);
        $device = (string) ($inlineOptions['device'] ?? '');
        $ioProfile = strtolower((string) ($inlineOptions['io-profile'] ?? ''));
        $ioCostQos = (string) ($inlineOptions['io-cost-qos'] ?? '');
        $ioCostModel = (string) ($inlineOptions['io-cost-model'] ?? '');
        $ioPairs = $parsedOptions['io'];
        $policyIoPairs = [];
        $ioCostWrites = [];
        $opt = $parsedOptions['resource'];

        if ($parseError !== null) {
            fwrite(STDERR, $parseError."\n");
            return 2;
        }

        if (($invalidMessage = $this->validateFlagOptions($opt, $ioCostQos, $ioCostModel)) !== null) {
            fwrite(STDERR, $invalidMessage."\n");
            return 2;
        }

        if ($doWipe) {
            $hasConflictingInput = !empty($opt)
                || !empty($ioPairs)
                || $defaultsRequested
                || $respectExisting
                || $device !== ''
                || $ioProfile !== ''
                || $ioCostQos !== ''
                || $ioCostModel !== '';
            if ($hasConflictingInput) {
                fwrite(STDERR, "Invalid --wipe combination: remove resource, IO, defaults, and respect-existing options before wiping\n");
                return 2;
            }
        }

        // Defaults policy
        if ($defaultsRequested) {
            $policyIoPairs = $this->applyDefaults($opt);
        }

        if ($wantConfig) $this->showConfig($slice);
        if ($wantStatus) $this->showStatus($slice, $uid);

        // Profiles
        $this->expandProfiles($opt);

        if (($invalidDeviceMessage = $this->validateDeviceSelector($device)) !== null) {
            fwrite(STDERR, $invalidDeviceMessage."\n");
            return 2;
        }

        // IO Device resolution
        $devResolved = '';
        if ($device !== '') {
            $devResolved = strpos($device, '/dev/') === 0 ? $device : $this->sys->resolveDevice($device);

            if ($devResolved !== '' && !\pmssCgroupPolicyDeviceTargetIsSafe($devResolved)) {
                fwrite(STDERR, "Invalid resolved --device target: expected /dev/... without whitespace or NUL bytes\n");
                return 2;
            }
        }

        if (isset($opt['io-latency-ms']) && $devResolved === '') {
            $devResolved = $this->sys->resolveDevice('/home');
            if ($devResolved !== '' && !\pmssCgroupPolicyDeviceTargetIsSafe($devResolved)) {
                echo "[WARN] IODeviceLatencyTargetSec skipped: unsafe /home backing device target\n";
                $devResolved = '';
            }
        }

        // IO Profile
        if ($ioProfile !== '' && $devResolved !== '') {
            $this->applyIoProfile($ioProfile, $devResolved, $opt, $ioPairs);
        }

        if (isset($opt['io-latency-ms']) && (int)$opt['io-latency-ms'] > 0) {
            if ($mode === 'v2' && $devResolved !== '') {
                $ioPairs[] = 'IODeviceLatencyTargetSec='.$devResolved.' '.(int)$opt['io-latency-ms'].'ms';
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
        if (!empty($policyIoPairs) && empty($ioPairs) && $ioProfile === '' && $device === '') {
            $ioPairs = array_merge($ioPairs, $policyIoPairs);
        }

        // Default view if no actions
        if (!$wantStatus && !$wantConfig) {
            $hasPlanInput = !empty($opt) || !empty($ioPairs) || !empty($ioCostWrites) || $device !== '' || $ioProfile !== '' || $doWipe;
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

        $hasPlan = !empty($props) || !empty($ioPairs) || !empty($ioCostWrites) || $doWipe;

        if ($hasPlan) {
            if ($apply && !$dryRun) {
                if ($uid === 0) {
                    fwrite(STDERR, "Refusing to apply cgroup changes to root slice; use cgroupRootCheck.php for root guard repair.\n");
                    return 1;
                }

                $this->sys->requireRoot();
                $applyFailed = false;
                foreach ($this->buildApplySteps($slice, $doWipe, $props, $ioPairs, $ioCostWrites) as $step) {
                    $applyFailed = (int) call_user_func($this->stepRunner, $step[0], $step[1]) !== 0 || $applyFailed;
                }

                if ($applyFailed) {
                    fwrite(STDERR, "One or more cgroup apply operations failed; inspect the logged command output above.\n");
                    return 1;
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

        if (isset($opts['memory-high']) || isset($opts['memory-max'])) {
            $memoryHighMiB = isset($opts['memory-high'])
                ? max($minHigh, (int)$opts['memory-high'])
                : max($minHigh, (int)($sysMemMiB*0.10));
            $props['MemoryHigh'] = $memoryHighMiB.'M';
            $memoryMax = isset($opts['memory-max'])
                ? (int)$opts['memory-max']
                : (int) floor($memoryHighMiB * 1.25);
            // MemoryMax cannot exceed High + 2048 MiB whether explicit or derived.
            $props['MemoryMax'] = max($memoryHighMiB, min($memoryMax, $memoryHighMiB + 2048, $maxCap)).'M';
        }

        $derivedWeight = $memoryHighMiB !== null ? self::calculateWeightFromMemory($memoryHighMiB) : null;

        // CPU keeps the full MemoryHigh curve; derived IOWeight stops at the BFQ-effective ceiling.
        foreach ([
            'cpu-weight' => ['CPUWeight', $derivedWeight],
            'io-weight' => ['IOWeight', $derivedWeight !== null ? min($derivedWeight, 200) : null],
            'tasks-max' => ['TasksMax', null],
        ] as $option => $target) {
            if (isset($opts[$option])) {
                $props[$target[0]] = (int)$opts[$option];
                continue;
            }
            if ($target[1] !== null) {
                $props[$target[0]] = $target[1];
            }
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

    private function parseFlagInputs(array $flags, ?string &$error): array
    {
        $error = null;
        $inlineOptions = $ioSpecs = $ioPairs = $resourceOptions = [];
        foreach ($flags as $flag) {
            if (strpos($flag, '--') !== 0 || ($separator = strpos($flag, '=')) === false) {
                continue;
            }

            $name = substr($flag, 2, $separator - 2);
            $value = substr($flag, $separator + 1);
            if (isset(self::IO_CLI_PROPERTY_MAP[$name])) {
                $ioSpecs[$name][] = $value;
                continue;
            }

            $inlineOptions[$name] = $value;
        }

        foreach (self::RESOURCE_OPTION_NAMES as $name) {
            if (!array_key_exists($name, $inlineOptions)) continue;
            $resourceOptions[$name] = strpos($name, '-profile') !== false ? strtolower((string) $inlineOptions[$name]) : (string) $inlineOptions[$name];
        }
        foreach (self::IO_CLI_PROPERTY_MAP as $flagName => $propertyName) {
            foreach ($ioSpecs[$flagName] ?? [] as $spec) {
                $specText = trim($spec);
                if (in_array($flagName, ['io-read-iops', 'io-write-iops'], true) && $specText === '/home:max') {
                    $homeDevice = trim($this->sys->resolveDevice('/home'));
                    if ($homeDevice === '' || !\pmssCgroupPolicyDeviceTargetIsSafe($homeDevice)) {
                        $error = 'Invalid --'.$flagName.' clear target: unable to resolve safe /home backing device';
                        return [];
                    }
                    $ioPairs[] = $propertyName.'='.$homeDevice.' infinity';
                    continue;
                }

                if (preg_match('/^([^:\s]+):([^\s]+)$/', $specText, $matches) !== 1
                    || !\pmssCgroupPolicyDeviceTargetIsSafe($matches[1])
                    || strpos($matches[2], "\0") !== false) {
                    $error = 'Invalid --'.$flagName.' specification: '.$spec;
                    return [];
                }
                $ioPairs[] = $propertyName.'='.$matches[1].' '.$matches[2];
            }
        }

        return ['inline' => $inlineOptions, 'resource' => $resourceOptions, 'io' => $ioPairs];
    }

    private function buildApplySteps(string $slice, bool $doWipe, array $props, array $ioPairs, array $ioCostWrites): array
    {
        if ($doWipe) {
            return [
                ['Reverting user slice', \pmssBuildCommand('systemctl', ['revert', $slice])],
                ['Unlimiting core properties', \pmssBuildCommand('systemctl', ['set-property', $slice, 'MemoryHigh=infinity', 'MemoryMax=infinity', 'TasksMax=infinity', 'CPUWeight=100', 'IOWeight=100'])],
            ];
        }

        $steps = [];
        $propertyPairs = [];
        foreach ($props as $key => $value) { $propertyPairs[] = $key.'='.$value; }
        $allPairs = array_merge($propertyPairs, $ioPairs);
        if (!empty($allPairs)) {
            $steps[] = ['Applying cgroup properties', \pmssBuildCommand('systemctl', array_merge(['set-property', $slice], $allPairs))];
        }
        foreach ($ioCostWrites as $write) {
            $script = 'if [ -w '.escapeshellarg($write['path']).' ]; then printf \'%s\\n\' '
                .escapeshellarg($write['value'])
                .' > '.escapeshellarg($write['path'])
                .'; else echo '.escapeshellarg('[ERR] io.cost path not writable: '.$write['path']).'; exit 1; fi';
            $steps[] = ['Applying io.cost setting', \pmssBuildCommand('sh', ['-c', $script])];
        }

        return $steps;
    }

    /**
     * Reject malformed CLI values before they reach systemctl.
     */
    private function validateFlagOptions(array $opt, string $ioCostQos, string $ioCostModel): ?string
    {
        foreach (self::INTEGER_OPTION_NAMES as $key) {
            if (isset($opt[$key]) && preg_match('/^-?[0-9]+$/', (string)$opt[$key]) !== 1) {
                return 'Invalid --'.$key.' value: expected integer';
            }
        }

        if (isset($opt['io-latency-ms']) && (int)$opt['io-latency-ms'] <= 0) {
            return 'Invalid --io-latency-ms value: expected positive integer';
        }

        foreach (['io-cost-qos' => $ioCostQos, 'io-cost-model' => $ioCostModel] as $flagName => $value) {
            if ($value !== '' && (strpos($value, "\0") !== false || strpos($value, "\n") !== false || strpos($value, "\r") !== false)) {
                return 'Invalid --'.$flagName.' value: newline and NUL bytes are not allowed';
            }
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
        return max(10, min(1000, (int) round(8 * sqrt(max(0, $memoryHighMiB)))));
    }

    private function applyDefaults(array &$opt): array
    {
        $policy = \pmssCgroupPolicyLoad();

        foreach (self::POLICY_OPTION_MAP as $optionKey => $policyKey) {
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
                : ($this->validateDeviceSelector($mountPath) === null ? trim($this->sys->resolveDevice($mountPath)) : '');
            if ($devicePath === '' || !\pmssCgroupPolicyDeviceTargetIsSafe($devicePath)) {
                continue;
            }

            foreach (\pmssCgroupPolicyIoPairs($mountPolicy, $devicePath) as $pair) {
                $propertyName = substr($pair, 0, strpos($pair, '='));
                $pairsByKey[$propertyName.'|'.$devicePath] = $pair;
            }
        }

        return array_values($pairsByKey);
    }

    private function expandProfiles(array &$opt): void
    {
        $policy = \pmssCgroupPolicyLoad();
        foreach (self::NUMERIC_PROFILE_MAP as $family => $profile) {
            $profileKey = $profile[0];
            if (!isset($opt[$profileKey])) {
                continue;
            }

            $profileName = strtolower($opt[$profileKey]);
            $opt[$profileKey] = $profileName;
            $targetKey = $profile[1];
            if (!isset($opt[$targetKey])) {
                $opt[$targetKey] = \pmssCgroupPolicyNumericProfileValue($policy, $family, $profileName, $profile[3], $profile[2]);
            }
        }
    }

    private function applyIoProfile(string $profile, string $dev, array &$opt, array &$pairs): void
    {
        $profiles = \pmssCgroupPolicyIoProfiles(\pmssCgroupPolicyLoad(), self::IO_PROFILE_MAP);
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

        foreach (\pmssCgroupPolicyIoPairs($entry['limits'], $dev, false) as $pair) {
            $pairs[] = $pair;
        }
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
        if (!\pmssCgroupPolicyDeviceTargetIsSafe($resolvedDevice)) {
            $messages[] = '[WARN] io.cost skipped: unsafe backing device target';
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

        foreach (['io.cost.qos' => $ioCostQos, 'io.cost.model' => $ioCostModel] as $fileName => $rawSetting) {
            $setting = trim((string) $rawSetting);
            if ($setting === '') {
                continue;
            }
            $majorMinorMatch = [];
            $hasMajorMinor = preg_match('/^([0-9]+:[0-9]+)\s+/', $setting, $majorMinorMatch) === 1;
            if ($hasMajorMinor && $majorMinorMatch[1] !== $majorMinor) {
                $messages[] = '[WARN] io.cost skipped: invalid '.$fileName.' setting';
                continue;
            }
            $normalized = $hasMajorMinor ? $setting : $majorMinor.' '.$setting;

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

    /** Reject shell-sensitive device selectors before mount resolution. */
    private function validateDeviceSelector(string $device): ?string
    {
        if ($device === '') {
            return null;
        }

        if (strpos($device, "\0") !== false || preg_match('/\s/', $device) === 1) {
            return 'Invalid --device value: whitespace or NUL bytes are not allowed';
        }

        return null;
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
            $this->showStatusPairs($pairs);
        } else {
            $base = "/sys/fs/cgroup";
            $pids = $base."/pids/user.slice/user-".$uid.".slice/pids.current";
            $meml = $base."/memory/user.slice/user-".$uid.".slice/memory.limit_in_bytes";
            $memu = $base."/memory/user.slice/user-".$uid.".slice/memory.usage_in_bytes";
            $this->showStatusPairs([ 'pids.current' => $pids, 'memory.limit_in_bytes' => $meml, 'memory.usage_in_bytes' => $memu ]);
        }
    }

    private function showStatusPairs(array $pairs): void
    {
        foreach ($pairs as $label => $path) {
            $val = $this->sys->readFile($path);
            echo $label.': '.($val === null ? '(unavailable)' : trim($val))."\n";
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
