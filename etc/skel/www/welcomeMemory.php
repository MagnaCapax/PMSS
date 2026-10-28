<?php
/** Welcome RAM state and rendering; loaded through webCgroupMemoryStatus.php.
 * @license GPL-3.0-only
 */

/**
 * Gather the welcome-page RAM counters from customer-readable sources.
 *
 * @return array<string,mixed>
 */
function pmssWelcomeMemoryStateBuild($pressureStatusOverride = null)
{
    $memory = pmssCustomerSerializedArrayFileRead('../.resourceData', 1048576)['memory'] ?? array();
    $memory = is_array($memory) ? $memory : array();
    foreach (array('current', 'anon', 'file') as $key) {
        $memory[$key] = is_numeric($memory[$key] ?? null) ? (float) $memory[$key] : null;
    }

    $uid = function_exists('posix_getuid') ? (int) posix_getuid() : null;
    if ($uid === null
        && function_exists('pmssFrontendShellExecAvailable')
        && function_exists('pmssFrontendShellExec')
        && pmssFrontendShellExecAvailable()) {
        $uidRaw = @pmssFrontendShellExec('/usr/bin/id -u 2>/dev/null');
        $uidValue = is_string($uidRaw) ? pmssCustomerUnsignedIntegerValue(trim($uidRaw)) : null;
        if ($uidValue !== null) $uid = $uidValue;
    }
    $uid = is_int($uid) && $uid >= 0 ? $uid : null;

    // The facade loads state and pressure together; saved counters still take precedence.
    $readPressureStatus = pmssWebCgroupMemoryStatusRead();
    if ($memory['current'] === null && !empty($readPressureStatus['available']) && is_numeric($readPressureStatus['memory_current'] ?? null)) {
        $memory['current'] = (float) $readPressureStatus['memory_current'];
    }

    if (!isset($memory['anon'], $memory['file']) && $uid !== null) {
        $parsed = pmssWebCgroupMemoryStatusMemoryStatBreakdownRead(
            pmssCustomerCgroupCounterPaths($uid, '', 'memory.stat', 'memory', 'memory.stat'), ['anon', 'file']
        );
        if (isset($parsed['anon'], $parsed['file'])) $memory = array_replace($memory, $parsed);
    }

    $ramMiB = pmssWelcomeUserConfigNumber('ramMiB');
    $pressureStatus = is_array($pressureStatusOverride) ? $pressureStatusOverride : $readPressureStatus;

    return array(
        'currentBytes' => $memory['current'],
        'limitBytes' => $ramMiB !== null && $ramMiB > 0 ? $ramMiB * 1024 * 1024 : null,
        'processBytes' => $memory['anon'],
        'cacheBytes' => $memory['file'],
        'pressureStatus' => !empty($pressureStatus['available']) ? $pressureStatus : null,
    );
}

/** Render the welcome-page RAM usage section. */
function pmssWelcomeMemorySectionHtmlBuild($pressureStatusOverride = null)
{
    $state = pmssWelcomeMemoryStateBuild($pressureStatusOverride);
    $currentBytes = $state['currentBytes'];
    $limitBytes = $state['limitBytes'];
    $processBytes = $state['processBytes'];
    $cacheBytes = $state['cacheBytes'];
    $pressureStatus = $state['pressureStatus'];

    if ($currentBytes === null && $limitBytes === null && $processBytes === null && $cacheBytes === null) {
        return pmssWelcomeMetricSectionHtmlBuild('RAM Info', '<b>RAM usage data is unavailable right now.</b>');
    }

    $currentText = $currentBytes === null ? 'n/a' : pmssFormatBytes($currentBytes, 2, 0, true);
    $processText = $processBytes === null ? 'n/a' : pmssFormatBytes($processBytes, 2, 0, true);
    $cacheText = $cacheBytes === null ? 'n/a' : pmssFormatBytes($cacheBytes, 2, 0, true);
    if ($limitBytes === null || $limitBytes <= 0) {
        $breakdownText = ($processBytes !== null || $cacheBytes !== null)
            ? '<br />Process memory: '.$processText.'<br />Page cache: '.$cacheText
            : '';
        return pmssWelcomeMetricSectionHtmlBuild('RAM Info', "\nCurrent RAM usage: {$currentText}{$breakdownText}<br />\nRAM limit: n/a\n");
    }

    $limitText = pmssFormatBytes($limitBytes, 2, 0, true);
    if ($currentBytes === null && $processBytes === null && $cacheBytes === null) {
        return pmssWelcomeMetricSectionHtmlBuild('RAM Info', "\nCurrent RAM usage: n/a<br />\nRAM limit: {$limitText}\n");
    }

    $warningBytes = $processBytes !== null ? $processBytes : $currentBytes;
    $warningPercent = pmssWelcomePercent($warningBytes, $limitBytes, 1);
    if ($processBytes !== null && $cacheBytes !== null) {
        $usedBytes = max($processBytes + $cacheBytes, $currentBytes !== null ? $currentBytes : 0);
        $processPercent = pmssWelcomePercent($processBytes, $limitBytes, 1);
        $cachePercent = pmssWelcomePercent($cacheBytes, $limitBytes, 1);
        $titleText = 'Process: '.$processText.' | Cache: '.$cacheText.' | Limit: '.$limitText;
        $gauge = createStackedGauge(
            $titleText,
            $titleText,
            pmssWelcomePercent($usedBytes, $limitBytes, 1),
            array(
                array('width' => $processPercent, 'color' => '#'.gaugeColor($warningPercent)),
                array('width' => $cachePercent, 'color' => '#b0bec5'),
                array('width' => max(0, 100 - max(0, min(100, $processPercent)) - max(0, min(100, $cachePercent))), 'color' => 'transparent'),
            )
        );
    } else {
        $titleText = "{$currentText} / {$limitText}";
        $gauge = createGauge($titleText, $titleText, pmssWelcomePercent($currentBytes !== null ? $currentBytes : 0, $limitBytes, 1));
    }

    $hasOomEvents = is_array($pressureStatus)
        && ((int) ($pressureStatus['max_events'] ?? 0) > 0
            || (int) ($pressureStatus['oom_events'] ?? 0) > 0
            || !empty($pressureStatus['oom_kill_recent']));
    $isThrottleActive = is_array($pressureStatus)
        && (string) ($pressureStatus['status'] ?? '') === 'THROTTLED'
        && !$hasOomEvents;
    if ($isThrottleActive) {
        $warning = '<br /><b style="color: #d2691e;">RAM THROTTLE ACTIVE</b><br />Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed.<br />';
    } elseif ($warningPercent > 100) {
        $warning = '<br /><b style="color: red;">RAM LIMIT EXCEEDED</b><br />Processes may be killed (OOM) until memory usage drops.<br />';
    } elseif ($warningPercent >= 80) {
        $warning = '<br /><b style="color: #d2691e;">RAM WARNING</b><br />You are close to your RAM limit. Consider reducing running services or upgrading your plan.<br />';
    } else {
        $warning = '';
    }

    $pressureIndicator = '';
    if (is_array($pressureStatus)) {
        $pressureParts = array(
            '<br /><b>Memory pressure:</b> <span style="color: '.$pressureStatus['status_color'].';">&#9679; '.pmssCustomerHtmlAttr($pressureStatus['status']).'</span>',
        );
        if ($pressureStatus['throttle_events'] !== null) {
            $pressureParts[] = '<br />Throttle events: '.number_format((int) $pressureStatus['throttle_events']);
        }
        if ($pressureStatus['message'] !== '') {
            $pressureParts[] = '<br /><b style="color: '.$pressureStatus['status_color'].';">'.pmssCustomerHtmlAttr($pressureStatus['message']).'</b>';
        }
        $pressureIndicator = implode('', $pressureParts).'<br />';
    }

    return pmssWelcomeMetricSectionHtmlBuild('RAM Info', "\n{$gauge}\n{$pressureIndicator}\n{$warning}\n");
}
