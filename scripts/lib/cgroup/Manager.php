<?php
/**
 * Cgroup CLI orchestration and stable public resource-sizing helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

require_once __DIR__ . '/SystemInterface.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/cliInput.php';
require_once __DIR__ . '/cliDisplay.php';
require_once __DIR__ . '/ioPlan.php';
require_once __DIR__ . '/../cli/helpText.php';
require_once __DIR__ . '/../systemdSliceProperties.php';
require_once __DIR__ . '/../update/runtime/commands.php'; // for runStep

class Manager
{
    private const ACTION_FLAG_MAP = ['status' => '--status', 'config' => '--config', 'apply' => '--apply', 'dryRun' => '--dry-run', 'respectExisting' => '--respect-existing', 'defaults' => '--defaults', 'wipe' => '--wipe'];

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
            echo pmssCgroupCliUsageText()."\n";
            return 0;
        }
        if (count($args) === 0) {
            return pmssCliReturnWithStderr(pmssCgroupCliUsageText()."\n", 2);
        }

        $user  = $args[0];
        $flags = array_slice($args, 1);
        $parseError = null;
        $parsedOptions = pmssCgroupCliParseFlagInputs($this->sys, $flags, $parseError) + ['options' => [], 'io' => []];
        $inlineOptions = $parsedOptions['options'];
        $uid   = $this->sys->getUid($user);

        if ($uid < 0) {
            return pmssCliReturnWithStderr("Unknown user: $user\n", 1);
        }

        $slice = "user-".$uid.".slice";
        $mode  = $this->sys->getCgroupMode();
        echo "user=$user uid=$uid slice=$slice mode=$mode\n";

        // Resolve top-level CLI booleans once, preserving exact flag matching.
        $actions = [];
        foreach (self::ACTION_FLAG_MAP as $key => $flag) $actions[$key] = in_array($flag, $flags, true);
        $device = (string) ($inlineOptions['device'] ?? '');
        $ioProfile = strtolower((string) ($inlineOptions['io-profile'] ?? ''));
        $ioCostQos = (string) ($inlineOptions['io-cost-qos'] ?? '');
        $ioCostModel = (string) ($inlineOptions['io-cost-model'] ?? '');
        $ioPairs = $parsedOptions['io'];
        $policyIoPairs = [];
        $opt = array_intersect_key($inlineOptions, PMSS_CGROUP_POLICY_OPTIONS + PMSS_CGROUP_NUMERIC_PROFILES);

        if ($parseError !== null) {
            return pmssCliReturnWithStderr($parseError."\n", 2);
        }

        if (($invalidMessage = pmssCgroupCliValidateFlagOptions($opt, $ioCostQos, $ioCostModel)) !== null) {
            return pmssCliReturnWithStderr($invalidMessage."\n", 2);
        }

        if ($actions['wipe'] && (!empty($opt) || !empty($ioPairs) || $actions['defaults'] || $actions['respectExisting']
            || $device !== '' || $ioProfile !== '' || $ioCostQos !== '' || $ioCostModel !== '')) {
            return pmssCliReturnWithStderr("Invalid --wipe combination: remove resource, IO, defaults, and respect-existing options before wiping\n", 2);
        }

        if ($actions['defaults']) {
            $policyIoPairs = pmssCgroupCliDefaultsApply($this->sys, $opt);
        }

        if ($actions['config']) pmssCgroupCliShowConfig($this->sys, $slice);
        if ($actions['status']) pmssCgroupCliShowStatus($this->sys, $slice, $uid);

        pmssCgroupCliExpandProfiles($opt);

        if (($invalidDeviceMessage = pmssCgroupCliValidateDeviceSelector($device)) !== null) {
            return pmssCliReturnWithStderr($invalidDeviceMessage."\n", 2);
        }

        $devResolved = pmssCgroupCliDeviceResolve($this->sys, $device, isset($opt['io-latency-ms']));
        if ($devResolved === null) return 2;

        if ($ioProfile !== '' && $devResolved !== '') {
            pmssCgroupCliApplyIoProfile($ioProfile, $devResolved, $opt, $ioPairs);
        }

        if (isset($opt['io-latency-ms']) && (int)$opt['io-latency-ms'] > 0) {
            if ($mode === 'v2' && $devResolved !== '') {
                $ioPairs[] = 'IODeviceLatencyTargetSec='.$devResolved.' '.(int)$opt['io-latency-ms'].'ms';
            } elseif ($mode !== 'v2') {
                echo "[SKIP] IODeviceLatencyTargetSec requires cgroup v2\n";
            }
        }
        $ioCostWrites = pmssCgroupCliIoCostWrites($this->sys, $slice, $mode, $devResolved, $ioCostQos, $ioCostModel);

        if (!empty($policyIoPairs) && empty($ioPairs) && $ioProfile === '' && $device === '') {
            $ioPairs = $policyIoPairs;
        }

        if (!$actions['status'] && !$actions['config'] && !$actions['wipe']
            && empty($opt) && empty($ioPairs) && empty($ioCostWrites) && empty($device) && empty($ioProfile)) {
            pmssCgroupCliShowConfig($this->sys, $slice);
            pmssCgroupCliShowStatus($this->sys, $slice, $uid);
        }

        $props = !empty($opt) ? $this->computeSetProps($opt, $this->sys->getTotalMemoryMiB()) : [];

        $this->filterExistingProps($slice, $actions['defaults'] && $actions['respectExisting'], $props);
        pmssCgroupCliPrintPlan($props, $ioPairs, $ioCostWrites);
        return $this->finishPlan($slice, $uid, $actions, $props, $ioPairs, $ioCostWrites);
    }

    public function computeSetProps(array $opts, int $sysMemMiB): array
    {
        $props = [];
        $memoryHighMiB = null;

        if (isset($opts['memory-high']) || isset($opts['memory-max'])) {
            $memory = self::computeMemoryProperties(
                isset($opts['memory-high']) ? (int)$opts['memory-high'] : null,
                isset($opts['memory-max']) ? (int)$opts['memory-max'] : null,
                $sysMemMiB
            );
            $memoryHighMiB = $memory['memoryHighMiB'];
            $props['MemoryHigh'] = $memory['MemoryHigh'];
            $props['MemoryMax'] = $memory['MemoryMax'];
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

    private function filterExistingProps(string $slice, bool $enabled, array &$props): void
    {
        if (!$enabled || empty($props)) return;
        $keys = ['CPUWeight','IOWeight','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec'];
        $out = $this->sys->execute(\pmssBuildSystemdShowCommand($slice, $keys));
        $current = is_string($out) ? \pmssParseSystemdPropertyOutput($keys, $out) : [];
        foreach (array_keys($props) as $key) {
            // Read the native systemd key without building a renamed property map.
            $nativeKey = $key === 'CPUQuota' ? 'CPUQuotaPerSecUSec' : $key;
            $liveValue = trim((string)($current[$nativeKey] ?? ''));
            // systemctl show uses these sentinels for unset resource controls.
            $isSet = $nativeKey === 'CPUQuotaPerSecUSec'
                ? \pmssSystemdTimeSpanUsec($liveValue) !== null
                : $liveValue !== '' && strcasecmp($liveValue, 'infinity') !== 0 && strcasecmp($liveValue, '[not set]') !== 0;
            if ($isSet) unset($props[$key]);
        }
    }

    private function finishPlan(string $slice, int $uid, array $actions, array $props, array $ioPairs, array $ioCostWrites): int
    {
        if (!$actions['wipe'] && empty($props) && empty($ioPairs) && empty($ioCostWrites)) return 0;
        if (!$actions['apply'] || $actions['dryRun']) {
            echo "(dry-run or no --apply; not changing system)\n";
            return 0;
        }
        if ($uid === 0) {
            return pmssCliReturnWithStderr("Refusing to apply cgroup changes to root slice; use cgroupRootCheck.php for root guard repair.\n", 1);
        }

        $this->sys->requireRoot();
        $applyFailed = false;
        foreach ($this->buildApplySteps($slice, $actions['wipe'], $props, $ioPairs, $ioCostWrites) as $step) {
            $applyFailed = (int) call_user_func($this->stepRunner, $step[0], $step[1]) !== 0 || $applyFailed;
        }
        if ($applyFailed) {
            return pmssCliReturnWithStderr("One or more cgroup apply operations failed; inspect the logged command output above.\n", 1);
        }

        return 0;
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

    /**
     * Compute the canonical memory properties used by userConfigCgroup.php.
     *
     * @return array{memoryHighMiB:int,memoryMaxMiB:int,MemoryHigh:string,MemoryMax:string}
     */
    public static function computeMemoryProperties(?int $memoryHighMiB, ?int $memoryMaxMiB, int $sysMemMiB): array
    {
        $minHigh = 250;
        $high = $memoryHighMiB !== null
            ? max($minHigh, $memoryHighMiB)
            : max($minHigh, (int)($sysMemMiB * 0.10));
        $maxCap = $sysMemMiB > 0 ? (int) floor($sysMemMiB * 0.95) : PHP_INT_MAX;
        $max = $memoryMaxMiB !== null
            ? $memoryMaxMiB
            : (int) floor($high * 1.25);
        // MemoryMax cannot exceed High + 2048 MiB whether explicit or derived.
        $max = max($high, min($max, $high + 2048, $maxCap));

        return [
            'memoryHighMiB' => $high,
            'memoryMaxMiB' => $max,
            'MemoryHigh' => $high.'M',
            'MemoryMax' => $max.'M',
        ];
    }
}
