<?php
/**
 * SMART device observation; text parsing lives in deviceParse.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/deviceParse.php';

/**
 * @param array<string, mixed> $disk
 * @param array<string, mixed> $last
 * @return array<string, mixed>
 */
function pmssStorageHealthSnapshotSmart(array $disk, array $last, string $timestamp): array
{
    $dev = (string) $disk['path'];
    $failure = !is_readable($dev) ? ['device_unreadable', 'device unreadable']
        : (pmssCommandPath('smartctl') === '' ? ['smartctl_missing', 'smartctl missing'] : null);
    if ($failure !== null) {
        return pmssStorageHealthEntryFinalize(pmssStorageHealthDeviceEntryBuild('smart', $disk, $timestamp, 1), [$failure[0]], $failure[1]);
    }

    $cmd = 'smartctl -n standby -H -A -i '.escapeshellarg($dev);
    $probe = pmssStorageHealthProbeCommand('smart', (string) ($disk['kname'] ?? ''), $cmd);
    $res = pmssCommandCapture($probe['command'], 25);
    $out = $res['stdout']."\n".$res['stderr'];
    if (trim($out) === '') {
        $locked = $probe['lock_exit_code'] !== 0 && (int) $res['rc'] === $probe['lock_exit_code'];
        return pmssStorageHealthEntryFinalize(pmssStorageHealthDeviceEntryBuild('smart', $disk, $timestamp, 1),
            [$locked ? 'smartctl_probe_locked' : 'smartctl_empty'], $locked ? 'smartctl probe already running' : 'smartctl produced no output');
    }

    $prevMetrics = $last['smart::'.$dev]['metrics'] ?? null;
    if (is_array($prevMetrics) && isset($prevMetrics['udma_crc']) && !isset($prevMetrics['link_errors'])) {
        $prevMetrics['link_errors'] = $prevMetrics['udma_crc'];
    }

    $entry = pmssStorageHealthParseSmartctlOutput($out, $disk, $prevMetrics, $timestamp);
    return $res['rc'] === 124
        ? pmssStorageHealthEntryFinalize($entry, array_merge($entry['flags'], ['smartctl_timeout'])) : $entry;
}
