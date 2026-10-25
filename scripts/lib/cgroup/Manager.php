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
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/../cli/helpText.php';

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
        $plan = pmssCgroupCliParseFlagInputs($this->sys, $flags, $parseError) + ['options' => [], 'ioProperties' => []];
        $uid   = $this->sys->getUid($user);

        if ($uid < 0) {
            return pmssCliReturnWithStderr("Unknown user: $user\n", 1);
        }

        $slice = "user-".$uid.".slice";
        $mode  = $this->sys->getCgroupMode();
        echo "user=$user uid=$uid slice=$slice mode=$mode\n";

        // Resolve top-level CLI booleans once, preserving exact flag matching.
        $plan += ['slice' => $slice, 'uid' => $uid, 'actions' => []];
        foreach (self::ACTION_FLAG_MAP as $key => $flag) $plan['actions'][$key] = in_array($flag, $flags, true);
        $device = (string) ($plan['options']['device'] ?? '');
        $ioProfile = strtolower((string) ($plan['options']['io-profile'] ?? ''));
        $ioCostQos = (string) ($plan['options']['io-cost-qos'] ?? '');
        $ioCostModel = (string) ($plan['options']['io-cost-model'] ?? '');
        $policyIoPairs = [];
        $plan['options'] = array_intersect_key($plan['options'], PMSS_CGROUP_POLICY_OPTIONS + PMSS_CGROUP_NUMERIC_PROFILES);

        if ($parseError !== null) {
            return pmssCliReturnWithStderr($parseError."\n", 2);
        }

        if (($invalidMessage = pmssCgroupCliValidateFlagOptions($plan['options'], $ioCostQos, $ioCostModel)) !== null) {
            return pmssCliReturnWithStderr($invalidMessage."\n", 2);
        }

        if ($plan['actions']['wipe'] && (!empty($plan['options']) || !empty($plan['ioProperties']) || $plan['actions']['defaults'] || $plan['actions']['respectExisting']
            || $device !== '' || $ioProfile !== '' || $ioCostQos !== '' || $ioCostModel !== '')) {
            return pmssCliReturnWithStderr("Invalid --wipe combination: remove resource, IO, defaults, and respect-existing options before wiping\n", 2);
        }

        if ($plan['actions']['defaults']) {
            $policyIoPairs = pmssCgroupCliDefaultsApply($this->sys, $plan['options']);
        }

        if ($plan['actions']['config']) pmssCgroupCliShowConfig($this->sys, $slice);
        if ($plan['actions']['status']) pmssCgroupCliShowStatus($this->sys, $slice, $uid);

        pmssCgroupCliExpandProfiles($plan['options']);

        if (($invalidDeviceMessage = pmssCgroupCliValidateDeviceSelector($device)) !== null) {
            return pmssCliReturnWithStderr($invalidDeviceMessage."\n", 2);
        }

        $devResolved = pmssCgroupCliDeviceResolve($this->sys, $device, isset($plan['options']['io-latency-ms']));
        if ($devResolved === null) return 2;

        if ($ioProfile !== '' && $devResolved !== '') {
            pmssCgroupCliApplyIoProfile($ioProfile, $devResolved, $plan);
        }

        if (isset($plan['options']['io-latency-ms']) && (int)$plan['options']['io-latency-ms'] > 0) {
            if ($mode === 'v2' && $devResolved !== '') {
                $plan['ioProperties'][] = 'IODeviceLatencyTargetSec='.$devResolved.' '.(int)$plan['options']['io-latency-ms'].'ms';
            } elseif ($mode !== 'v2') {
                echo "[SKIP] IODeviceLatencyTargetSec requires cgroup v2\n";
            }
        }
        $plan['ioCostWrites'] = pmssCgroupCliIoCostWrites($this->sys, $slice, $mode, $devResolved, $ioCostQos, $ioCostModel);

        if (!empty($policyIoPairs) && empty($plan['ioProperties']) && $ioProfile === '' && $device === '') {
            $plan['ioProperties'] = $policyIoPairs;
        }

        if (!$plan['actions']['status'] && !$plan['actions']['config'] && !$plan['actions']['wipe']
            && empty($plan['options']) && empty($plan['ioProperties']) && empty($plan['ioCostWrites']) && empty($device) && empty($ioProfile)) {
            pmssCgroupCliShowConfig($this->sys, $slice);
            pmssCgroupCliShowStatus($this->sys, $slice, $uid);
        }

        $plan['properties'] = !empty($plan['options']) ? $this->computeSetProps($plan['options'], $this->sys->getTotalMemoryMiB()) : [];
        return pmssCgroupCliFinishPlan($this->sys, $plan, $this->stepRunner);
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
        $high = max($minHigh, $memoryHighMiB ?? (int)($sysMemMiB * 0.10));
        $maxCap = $sysMemMiB > 0 ? (int) floor($sysMemMiB * 0.95) : PHP_INT_MAX;
        $max = $memoryMaxMiB ?? (int) floor($high * 1.25);
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
