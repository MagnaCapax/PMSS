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

$readProperty = static function (string $property): string {
    $output = trim((string) @shell_exec('systemctl show user-0.slice -p '.$property.' 2>/dev/null'));
    $separatorPos = strpos($output, '=');
    return $separatorPos !== false ? substr($output, $separatorPos + 1) : $output;
};

$fixes=[];
foreach (['MemoryHigh', 'MemoryMax', 'TasksMax'] as $prop) {
    $value = $readProperty($prop);
    if ($value !== '' && strtolower($value) !== 'infinity') {
        $fixes[$prop] = 'infinity';
    }
}

if ($fixes) {
    // In test mode, allow running without root to exercise logging paths
    if (!defined('PMSS_TEST_MODE') && getenv('PMSS_TEST_MODE') !== '1') {
        requireRoot();
    }
    $pairs = [];
    foreach ($fixes as $property => $value) { $pairs[] = $property.'='.$value; }
    runStep('Unlimiting root user slice', 'systemctl set-property user-0.slice '.implode(' ', $pairs));
} else {
    logmsg('[OK] Root slice already unlimited');
}
