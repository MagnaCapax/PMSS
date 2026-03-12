#!/usr/bin/env php
<?php
/**
 * Enforce that unwanted system-wide services stay stopped/disabled/masked.
 *
 * Policy list: pmssSeedboxSystemServiceSpecs() + apache2 legacy hardening.
 * See scripts/lib/update/services/systemd.php for the current list.
 * This is a drift guard against package manager actions and manual starts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/update/services/systemd.php';
require_once __DIR__.'/../lib/update/runtime/processes.php';

if (!defined('PMSS_TEST_MODE') && getenv('PMSS_TEST_MODE') !== '1') {
    requireRoot();
}

if (!is_dir('/run/systemd/system')) {
    exit(0);
}

$specs = pmssSeedboxSystemServiceSpecs();
$specs[] = ['unit' => 'apache2', 'label' => 'Apache httpd (legacy)', 'mask' => true];

foreach ($specs as $spec) {
    $unit = (string) ($spec['unit'] ?? '');
    if ($unit === '' || !pmssSystemdUnitExists($unit)) {
        continue;
    }
    $label = (string) ($spec['label'] ?? $unit);
    $shouldMask = (bool) ($spec['mask'] ?? false);

    $active = trim((string) @shell_exec('systemctl is-active '.escapeshellarg($unit).' 2>/dev/null'));
    $enabled = trim((string) @shell_exec('systemctl is-enabled '.escapeshellarg($unit).' 2>/dev/null'));

    if (($active !== 'active' && $active !== 'activating')
        && ($shouldMask ? ($enabled === 'masked') : ($enabled !== 'enabled'))
    ) {
        continue;
    }

    pmssStopDisableMaskSystemdUnit($unit, $label, $shouldMask);
}
