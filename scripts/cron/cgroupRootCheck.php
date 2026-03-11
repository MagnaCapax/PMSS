#!/usr/bin/env php
<?php
/**
 * Ensure root (user-0.slice) is not limited by memory/tasks policies.
 * Runs safely in cron; fixes limits if detected.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// TODO(complexity-refactor): Extract tiny helpers for:
//  - reading unit properties
//  - computing required fixes
//  - applying changes
// so the main flow reads as detect → decide → apply. Add dry-run toggle.

require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

$fixes = [];
foreach (['MemoryHigh', 'MemoryMax', 'TasksMax'] as $prop) {
    $output = trim((string) @shell_exec('systemctl show user-0.slice -p '.$prop.' 2>/dev/null'));
    $separatorPos = strpos($output, '=');
    $value = $separatorPos !== false ? substr($output, $separatorPos + 1) : $output;
    if ($value !== '' && strtolower($value) !== 'infinity') {
        $fixes[] = $prop.'=infinity';
    }
}

if ($fixes) {
    // In test mode, allow running without root to exercise logging paths
    if (!defined('PMSS_TEST_MODE') && getenv('PMSS_TEST_MODE') !== '1') {
        requireRoot();
    }
    runStep('Unlimiting root user slice', 'systemctl set-property user-0.slice '.implode(' ', $fixes));
} else {
    logmsg('[OK] Root slice already unlimited');
}
