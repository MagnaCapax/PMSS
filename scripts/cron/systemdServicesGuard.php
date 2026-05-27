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

if (!pmssTestModeEnabled()) {
    requireRoot();
}

if (!is_dir('/run/systemd/system')) {
    exit(0);
}

foreach (pmssSeedboxSystemServiceSpecs() + ['apache2' => 'Apache httpd (legacy)'] as $unit => $label) {
    if ($unit === '' || !pmssSystemdUnitExists($unit)) {
        continue;
    }

    $active = trim((string) @shell_exec('systemctl is-active '.escapeshellarg($unit).' 2>/dev/null'));
    $enabled = trim((string) @shell_exec('systemctl is-enabled '.escapeshellarg($unit).' 2>/dev/null'));

    if (($active !== 'active' && $active !== 'activating')
        && $enabled === 'masked'
    ) {
        continue;
    }

    pmssStopDisableMaskSystemdUnit($unit, $label, true);
}
