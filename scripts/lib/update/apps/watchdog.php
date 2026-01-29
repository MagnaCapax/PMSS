<?php
/**
 * Watchdog management helper.
 *
 * Re-enable watchdog with a robust network check to avoid false positives.
 *
 * @author  Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../runtime/commands.php';

// Template sources live under /etc/seedbox/config by convention.
$configDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
$configTemplate = $configDir.'/template.watchdog.conf';
$scriptTemplate = $configDir.'/template.watchdog.network-check.sh';
$scriptDir = '/etc/watchdog.d';
$scriptTarget = $scriptDir.'/network-check.sh';

if (!is_file($configTemplate) || !is_file($scriptTemplate)) {
    logMessage('[WARN] Watchdog templates missing; skipping watchdog enablement.');
    return;
}

runStep('Ensuring watchdog script directory exists', 'mkdir -p '.$scriptDir);
runStep('Installing watchdog configuration', 'install -m 0644 '.$configTemplate.' /etc/watchdog.conf');
runStep('Installing watchdog network check', 'install -m 0755 '.$scriptTemplate.' '.$scriptTarget);

// Prefer /dev/watchdog, but allow watchdog0 if that is what the kernel exposes.
$device = '/dev/watchdog';
if (!is_file($device) && is_file('/dev/watchdog0')) {
    $device = '/dev/watchdog0';
}

if (!is_file($device)) {
    logMessage('[WARN] Watchdog device missing; leaving service disabled.');
    return;
}

if ($device !== '/dev/watchdog') {
    $config = @file_get_contents('/etc/watchdog.conf');
    if ($config !== false) {
        $updated = preg_replace('/^watchdog-device\\s*=\\s*\\/dev\\/watchdog\\b/m', 'watchdog-device = '.$device, $config);
        if ($updated !== null && $updated !== $config) {
            file_put_contents('/etc/watchdog.conf', $updated);
        }
    }
}

runStep('Unmasking watchdog service', 'systemctl unmask watchdog || true');
runStep('Enabling watchdog service', 'systemctl enable --now watchdog');
