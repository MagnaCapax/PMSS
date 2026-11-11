<?php
/** Hardware-summary publication for the applied sysctl profile. @license GPL-3.0-only */

require_once __DIR__.'/sysctlText.php';

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
    $payload = is_string($existing = @file_get_contents($target)) ? (pmssJsonDecodeAssoc($existing) ?? []) : [];

    // Materialization intentionally keeps the last value of a repeated setting.
    $applied = iterator_to_array(pmssSysctlSettingsValues($groupedSettings));
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
