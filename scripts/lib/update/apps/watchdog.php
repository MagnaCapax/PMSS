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

/** Run a required watchdog setup step and stop before service activation on failure. */
function pmssWatchdogRunRequiredStep(string $description, string $command): bool
{
    if (runStep($description, $command) === 0) {
        return true;
    }

    logMessage('[WARN] '.$description.' failed; leaving watchdog service disabled.');
    return false;
}

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

// Detect the hardware watchdog device BEFORE writing any reboot-capable config.
// /dev/watchdog[0] are CHARACTER devices, so is_file() is always false for them
// (it matches regular files only); detect via existence + char-device filetype.
// Order matters: /etc/watchdog.conf carries load-reboot thresholds, so a
// deviceless guest must never be left holding that file next to a distro-enabled
// watchdog daemon — it would reboot the guest under load with no device backing
// it. With no usable device we disable the service instead of installing config.
$pmssWatchdogDeviceUsable = static function (string $path): bool {
    return @file_exists($path) && @filetype($path) === 'char';
};
$device = $pmssWatchdogDeviceUsable('/dev/watchdog') ? '/dev/watchdog'
        : ($pmssWatchdogDeviceUsable('/dev/watchdog0') ? '/dev/watchdog0' : '');
if ($device === '') {
    logMessage('[WARN] No watchdog character device present; disabling watchdog service.');
    runStep('Disabling watchdog service (no device)', 'systemctl disable --now watchdog || true');
    return;
}

if (!pmssWatchdogRunRequiredStep('Ensuring watchdog script directory exists', pmssBuildCommand('mkdir', ['-p', $scriptDir]))) {
    return;
}
if (!pmssWatchdogRunRequiredStep('Installing watchdog configuration', pmssBuildCommand('install', ['-m', '0644', $configTemplate, '/etc/watchdog.conf']))) {
    return;
}
if (!pmssWatchdogRunRequiredStep('Installing watchdog network check', pmssBuildCommand('install', ['-m', '0755', $scriptTemplate, $scriptTarget]))) {
    return;
}

if ($device !== '/dev/watchdog' && is_string($config = @file_get_contents('/etc/watchdog.conf'))) {
    $updated = preg_replace('/^watchdog-device\\s*=\\s*\\/dev\\/watchdog\\b/m', 'watchdog-device = '.$device, $config);
    if ($updated !== null && $updated !== $config) {
        if (@file_put_contents('/etc/watchdog.conf', $updated) === false) {
            logMessage('[WARN] Unable to update watchdog device path; leaving service disabled.');
            return;
        }
    }
}

runStep('Unmasking watchdog service', 'systemctl unmask watchdog || true');
runStep('Enabling watchdog service', 'systemctl enable --now watchdog');
