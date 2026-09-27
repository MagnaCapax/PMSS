<?php
/**
 * Hardware-aware sysctl profile helpers for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/sysctlProfile.php';
require_once __DIR__.'/sysctlSettings.php';

/** Refresh one managed sysctl-adjacent file and report whether it changed. */
function pmssSysctlManagedContentRefresh(string $path, string $content, string $label, callable $log, int $mode = 0644): array
{
    $warnLog = '[WARN] Unable to write '.$label.' at '.$path;
    if (($existing = @file_get_contents($path)) !== false && trim($existing) === trim($content)) {
        $log('[SKIP] '.ucfirst($label).' already present and up to date');
        return [true, true];
    }

    if (!pmssDirEnsureExists(dirname($path), 0755)) {
        $log($warnLog);
        return [false, false];
    }

    return [false, pmssWriteManagedPathFile($path, $content, $label, $log, null, null, $mode, $warnLog)];
}

/**
 * Recreate the PMSS-owned hardware-aware sysctl baseline.
 */
function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true, ?string $modulesLoadOverride = null): void
{
    $log             = $logger ?: 'logMessage';
    $target          = $targetOverride ?? '/etc/sysctl.d/99-pmss.conf';
    $modulesLoadPath = $modulesLoadOverride ?? '/etc/modules-load.d/pmss-bbr.conf';
    $overridePath    = pmssResolvePathFromEnv('PMSS_SYSCTL_OVERRIDES_PATH', '/etc/sysctl.d/90-pmss-overrides.conf');
    // Persist TCP BBR module loading across reboots.
    $modulesContent = "# PMSS: enable TCP BBR\ntcp_bbr\n";

    // /sys block tuning is handled by the boot-time tuning service; sysctl only covers /proc/sys.
    $profile = pmssSysctlProfileDetect();
    $overrideKeys = pmssSysctlOverridesParse($overridePath);
    $groupedSettings = pmssSysctlSettingsFilterOverrides(pmssSysctlSettingsBuild($profile), $overrideKeys);
    $changes = pmssSysctlChangesDescribe(pmssSysctlFileParse($target), $groupedSettings);

    [$sysctlUpToDate, $sysctlWriteOk] = pmssSysctlManagedContentRefresh(
        $target,
        pmssSysctlConfigRender($groupedSettings).PHP_EOL,
        'legacy sysctl defaults',
        $log
    );

    pmssSysctlSummaryWrite($logger, $profile, $groupedSettings, $overrideKeys, $changes);

    [$modulesUpToDate, $modulesWriteOk] = pmssSysctlManagedContentRefresh(
        $modulesLoadPath,
        $modulesContent,
        'TCP BBR modules-load configuration',
        $log
    );
    if (!$modulesUpToDate && $modulesWriteOk) {
        $log('Refreshed TCP BBR modules-load configuration at '.$modulesLoadPath);
    }

    if ($sysctlUpToDate || !$sysctlWriteOk) {
        return;
    }

    $reload ? runStep('Reloading sysctl configuration', 'sysctl --system') : $log('[SKIP] sysctl reload disabled');
    $log('Refreshed legacy sysctl defaults at '.$target);
}

/** Persist the detected sysctl profile to hardware.json without clobbering peers. */
function pmssSysctlSummaryWrite(?callable $logger, array $profile, array $groupedSettings, array $overrideKeys, array $changes): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    if (!pmssDirEnsureExists($cfgDir, 0755)) {
        $log('[WARN] Unable to create hardware summary directory: '.$cfgDir);
        return;
    }

    $target = $cfgDir.'/hardware.json';
    $existing = @file_get_contents($target);
    $payload = is_string($existing) ? (pmssJsonDecodeAssoc($existing) ?? []) : [];

    $applied = [];
    foreach ($groupedSettings as $settings) {
        if (!is_array($settings)) continue;
        foreach ($settings as $key => $value) $applied[(string) $key] = (string) $value;
    }
    ksort($applied);

    $payload['timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
    $payload['sysctl'] = [
        'detection' => [
            'ram_gb' => (int) ($profile['ram_gb'] ?? 0),
            'has_swap' => !empty($profile['has_swap']),
            'swap_is_fast' => !empty($profile['swap_is_fast']),
            'nic_speed_mbps' => (int) ($profile['nic_speed_mbps'] ?? 0),
            'nic_speed_gbps' => (int) ($profile['nic_speed_gbps'] ?? 0),
            'is_vm' => !empty($profile['is_vm']),
            'has_conntrack' => !empty($profile['has_conntrack']),
        ],
        'applied' => $applied,
        'overrides_respected' => array_values($overrideKeys),
        'changes_made' => array_values($changes),
    ];

    if (($json = pmssJsonEncodePrettyLine($payload)) === null) {
        $log('[WARN] Unable to encode hardware summary JSON for '.$target);
        return;
    }

    pmssWriteManagedPathFile(
        $target,
        $json,
        'hardware summary JSON',
        $log,
        null,
        null,
        0644,
        '[WARN] Unable to write hardware summary JSON at '.$target
    );
}
