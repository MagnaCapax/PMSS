<?php
/** Cgroup plan finalization: retain live settings, display, then apply ordered steps.
 * @license GPL-3.0-only
 */
namespace PMSS\Cgroup;

require_once __DIR__.'/../systemdSliceProperties.php';
require_once __DIR__.'/../update/runtime/commands.php';

/** Consume one plan without unpacking parallel property and write argument lists. */
function pmssCgroupCliFinishPlan(SystemInterface $sys, array $plan, callable $stepRunner): int
{
    if ($plan['actions']['defaults'] && $plan['actions']['respectExisting'] && !empty($plan['properties'])) {
        $keys = ['CPUWeight','IOWeight','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec'];
        $out = $sys->execute(\pmssBuildSystemdShowCommand($plan['slice'], $keys));
        $current = is_string($out) ? \pmssParseSystemdPropertyOutput($keys, $out) : [];
        foreach (array_keys($plan['properties']) as $key) {
            // Read native keys directly; systemctl uses sentinels for unset controls.
            $nativeKey = $key === 'CPUQuota' ? 'CPUQuotaPerSecUSec' : $key;
            $liveValue = trim((string)($current[$nativeKey] ?? ''));
            $isSet = $nativeKey === 'CPUQuotaPerSecUSec'
                ? \pmssSystemdTimeSpanUsec($liveValue) !== null
                : $liveValue !== '' && strcasecmp($liveValue, 'infinity') !== 0 && strcasecmp($liveValue, '[not set]') !== 0;
            if ($isSet) unset($plan['properties'][$key]);
        }
    }
    pmssCgroupCliPrintPlan($plan);
    if (!$plan['actions']['wipe'] && empty($plan['properties']) && empty($plan['ioProperties']) && empty($plan['ioCostWrites'])) return 0;
    if (!$plan['actions']['apply'] || $plan['actions']['dryRun']) {
        echo "(dry-run or no --apply; not changing system)\n";
        return 0;
    }
    if ($plan['uid'] === 0) {
        return pmssCliReturnWithStderr("Refusing to apply cgroup changes to root slice; use cgroupRootCheck.php for root guard repair.\n", 1);
    }

    // Root checking precedes construction; all steps are built before any runs.
    $sys->requireRoot();
    $steps = [];
    if ($plan['actions']['wipe']) {
        $steps = [
            ['Reverting user slice', \pmssBuildCommand('systemctl', ['revert', $plan['slice']])],
            ['Unlimiting core properties', \pmssBuildCommand('systemctl', ['set-property', $plan['slice'], 'MemoryHigh=infinity', 'MemoryMax=infinity', 'TasksMax=infinity', 'CPUWeight=100', 'IOWeight=100'])],
        ];
    } else {
        $pairs = [];
        foreach ($plan['properties'] as $key => $value) $pairs[] = $key.'='.$value;
        $pairs = array_merge($pairs, $plan['ioProperties']);
        if (!empty($pairs)) {
            $steps[] = ['Applying cgroup properties', \pmssBuildCommand('systemctl', array_merge(['set-property', $plan['slice']], $pairs))];
        }
        foreach ($plan['ioCostWrites'] as $write) {
            $script = 'if [ -w '.escapeshellarg($write['path']).' ]; then printf \'%s\\n\' '
                .escapeshellarg($write['value'])
                .' > '.escapeshellarg($write['path'])
                .'; else echo '.escapeshellarg('[ERR] io.cost path not writable: '.$write['path']).'; exit 1; fi';
            $steps[] = ['Applying io.cost setting', \pmssBuildCommand('sh', ['-c', $script])];
        }
    }
    // A failed command does not suppress subsequent planned writes.
    $applyFailed = false;
    foreach ($steps as $step) {
        $applyFailed = (int) call_user_func($stepRunner, $step[0], $step[1]) !== 0 || $applyFailed;
    }
    return $applyFailed
        ? pmssCliReturnWithStderr("One or more cgroup apply operations failed; inspect the logged command output above.\n", 1)
        : 0;
}
