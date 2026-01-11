#!/usr/bin/env php
<?php
/**
 * Enforce that unwanted system-wide services stay stopped/disabled/masked.
 *
 * Policy list: pmssSeedboxSystemServiceSpecs() + apache2 legacy hardening.
 * See scripts/lib/update/services/systemd.php for the current list.
 * This is a drift guard against package manager actions and manual starts.
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/update/services/systemd.php';
require_once __DIR__.'/../lib/update/runtime/processes.php';

if (!defined('PMSS_TEST_MODE') && getenv('PMSS_TEST_MODE') !== '1') {
    requireRoot();
}

if (!is_dir('/run/systemd/system')) {
    exit(0);
}

function pmssSystemdUnitState(string $subcommand, string $unit): string
{
    $out = @shell_exec('systemctl '.$subcommand.' '.escapeshellarg($unit).' 2>/dev/null');
    return trim(is_string($out) ? $out : '');
}

$specs = pmssSeedboxSystemServiceSpecs();
$specs[] = ['unit' => 'apache2', 'label' => 'Apache httpd (legacy)', 'mask' => true];

$touched = false;
foreach ($specs as $spec) {
    $unit = (string) ($spec['unit'] ?? '');
    if ($unit === '') {
        continue;
    }
    if (!pmssSystemdUnitExists($unit)) {
        continue;
    }
    $label = (string) ($spec['label'] ?? $unit);
    $shouldMask = (bool) ($spec['mask'] ?? false);

    $active = pmssSystemdUnitState('is-active', $unit);
    $enabled = pmssSystemdUnitState('is-enabled', $unit);

    $needsHardening = ($active === 'active' || $active === 'activating');
    if (!$needsHardening) {
        $needsHardening = $shouldMask ? ($enabled !== 'masked') : ($enabled === 'enabled');
    }
    if (!$needsHardening) {
        continue;
    }

    $touched = true;
    if ($unit === 'apache2') {
        pmssStopDisableMaskApacheLegacy();
        continue;
    }
    pmssStopDisableMaskSystemdUnit($unit, $label, $shouldMask);
}

if (!$touched) {
    exit(0);
}
