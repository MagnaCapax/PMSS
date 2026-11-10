#!/usr/bin/env php
<?php
/**
 * Keep unwanted system services off and recover enabled required services.
 *
 * Policy list: pmssSeedboxSystemServiceSpecs().
 * See scripts/lib/update/services/systemd.php for the current list.
 * This is a drift guard against package manager actions and manual starts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/update/services/systemd.php';
require_once __DIR__.'/../lib/update/services/systemd/recovery.php';

if (!pmssTestModeEnabled()) {
    requireRoot();
}

if (!pmssSystemdRuntimeAvailable()) {
    exit(0);
}

foreach (pmssSeedboxSystemServiceSpecs() as $unit => $label) {
    if (!pmssSystemdUnitExists($unit)) {
        continue;
    }

    $active = pmssSystemdUnitState('is-active', $unit);
    $enabled = pmssSystemdUnitState('is-enabled', $unit);

    if (($active !== 'active' && $active !== 'activating') && $enabled === 'masked') {
        continue;
    }

    pmssStopDisableMaskSystemdUnit($unit, $label, true);
}

pmssRecoverRequiredSystemdServices();
