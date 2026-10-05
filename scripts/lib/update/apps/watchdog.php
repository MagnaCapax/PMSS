<?php
/**
 * Watchdog management helper.
 *
 * Re-enable watchdog with a robust network check to avoid false positives.
 *
 * @author  Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
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

/** Return the first usable character device; candidates can be supplied by hermetic tests. */
function pmssWatchdogDevice(array $candidates = ['/dev/watchdog', '/dev/watchdog0']): string
{
    foreach ($candidates as $candidate) {
        if (file_exists($candidate) && filetype($candidate) === 'char') return $candidate;
    }
    return '';
}

// An operator mask takes precedence over package and PMSS service policy.
if (pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'masked') {
    logMessage('[INFO] Watchdog service masked by operator; leaving it unchanged.');
    return;
}

$device = pmssWatchdogDevice();
if ($device === '') {
    if (pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'enabled') {
        runStep('Disabling watchdog service without a device', 'systemctl disable --now watchdog.service');
    }
    logMessage('[WARN] Watchdog device missing; service left disabled.');
    return;
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

// Keep preparation ordered and stop before activation if any required step fails.
foreach ([
    ['Ensuring watchdog script directory exists', pmssBuildCommand('mkdir', ['-p', $scriptDir])],
    ['Installing watchdog configuration', pmssBuildCommand('install', ['-m', '0644', $configTemplate, '/etc/watchdog.conf'])],
    ['Installing watchdog network check', pmssBuildCommand('install', ['-m', '0755', $scriptTemplate, $scriptTarget])],
] as [$description, $command]) {
    if (!pmssWatchdogRunRequiredStep($description, $command)) return;
}

if ($device !== '/dev/watchdog') {
    $config = @file_get_contents('/etc/watchdog.conf');
    if (!is_string($config)) {
        logMessage('[WARN] Unable to read watchdog device configuration; leaving service disabled.');
        return;
    }

    $updated = preg_replace('/^watchdog-device\\s*=\\s*\\/dev\\/watchdog\\b/m', 'watchdog-device = '.$device, $config);
    if ($updated === null) {
        logMessage('[WARN] Unable to prepare watchdog device configuration; leaving service disabled.');
        return;
    }
    if ($updated !== $config) {
        if (@file_put_contents('/etc/watchdog.conf', $updated) !== strlen($updated)) {
            logMessage('[WARN] Unable to update watchdog device path; leaving service disabled.');
            return;
        }
    }
}

runStep('Enabling watchdog service', 'systemctl enable --now watchdog.service');
