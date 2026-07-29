<?php
/**
 * Media-stack runtime observation and per-account status publication.
 *
 * The cron consumer runs as root, while the panel only reads the published
 * customer-visible artifact. Automatic restarts are intentionally not part of
 * this helper: repeated failures must remain visible instead of becoming a
 * quiet restart loop.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/pathSafety.php';
require_once __DIR__.'/user/identity.php';
require_once __DIR__.'/user/log.php';

if (!defined('PMSS_MEDIA_STACK_WATCHDOG_FAILURE_CYCLES')) {
    define('PMSS_MEDIA_STACK_WATCHDOG_FAILURE_CYCLES', 3);
}

/** @return array<string,array{label:string,config:string,binary:string}> */
function pmssMediaStackWatchdogAppDefinitions(): array
{
    return array(
        'sonarr' => array('label' => 'Sonarr', 'config' => '.config/sonarr', 'binary' => '.bin/Sonarr/Sonarr.dll'),
        'radarr' => array('label' => 'Radarr', 'config' => '.config/radarr', 'binary' => '.bin/Radarr/Radarr.dll'),
        'prowlarr' => array('label' => 'Prowlarr', 'config' => '.config/prowlarr', 'binary' => '.bin/Prowlarr/Prowlarr.dll'),
        'sabnzbd' => array('label' => 'SABnzbd', 'config' => '.config/sabnzbd', 'binary' => '.bin/sabnzbd/sabnzbd/SABnzbd.py'),
        'cloudplow' => array('label' => 'Cloudplow', 'config' => '.config/cloudplow', 'binary' => '.bin/cloudplow/cloudplow/cloudplow.py'),
        'jellyfin' => array('label' => 'Jellyfin', 'config' => '.config/jellyfin', 'binary' => '.bin/jellyfin/jellyfin.dll'),
    );
}

/** Return the managed app sessions present in an account's media-stack files. */
function pmssMediaStackWatchdogExpectedApps(string $home): array
{
    $apps = array();
    foreach (pmssMediaStackWatchdogAppDefinitions() as $app => $definition) {
        if (is_dir($home.'/'.$definition['config']) || is_file($home.'/'.$definition['binary'])) {
            $apps[$app] = $definition;
        }
    }

    return $apps;
}

/** Return the existing panel install marker. */
function pmssMediaStackWatchdogInstalled(string $home): bool
{
    return is_file($home.'/.config/jellyfin/config/network.xml');
}

/** Resolve the published status path without following an unsafe home target. */
function pmssMediaStackWatchdogStatusPath(string $home): string
{
    $path = rtrim($home, '/').'/.media-stack-status.json';
    return is_dir($home) && !is_link($home) && pmssPathTargetIsSafe($path, false, true) ? $path : '';
}

/** Probe a user's tmux server without changing its sessions. */
function pmssMediaStackWatchdogSessionRunning(string $username, string $app, ?callable $probe = null): bool
{
    if (!pmssValidateUsername($username) || !isset(pmssMediaStackWatchdogAppDefinitions()[$app])) {
        return false;
    }
    if ($probe !== null) {
        return (bool) $probe($username, $app);
    }

    $command = pmssBuildUserShellCommand($username, 'tmux has-session -t '.escapeshellarg($app));
    return pmssCommandCapture($command, 10)['rc'] === 0;
}

/** Build one observation snapshot from the current and previous probes. */
function pmssMediaStackWatchdogSnapshot(string $username, array $apps, array $previous = array(), ?callable $probe = null): array
{
    $states = array();
    $hasStopped = false;
    $hasFailed = false;
    $previousApps = isset($previous['apps']) && is_array($previous['apps']) ? $previous['apps'] : array();

    foreach ($apps as $app => $definition) {
        $running = pmssMediaStackWatchdogSessionRunning($username, $app, $probe);
        $oldFailures = isset($previousApps[$app]['consecutiveFailures']) ? (int) $previousApps[$app]['consecutiveFailures'] : 0;
        $failures = $running ? 0 : max(0, $oldFailures) + 1;
        $state = $running ? 'running' : ($failures >= PMSS_MEDIA_STACK_WATCHDOG_FAILURE_CYCLES ? 'failed' : 'stopped');
        $hasStopped = $hasStopped || $state === 'stopped';
        $hasFailed = $hasFailed || $state === 'failed';
        $states[$app] = array(
            'label' => $definition['label'],
            'state' => $state,
            'consecutiveFailures' => $failures,
        );
    }

    return array(
        'timestamp' => date('c'),
        'username' => $username,
        'state' => $hasFailed ? 'failed' : ($hasStopped ? 'degraded' : 'healthy'),
        'failureThreshold' => PMSS_MEDIA_STACK_WATCHDOG_FAILURE_CYCLES,
        'restartPolicy' => 'observe-only',
        'apps' => $states,
    );
}

/** Atomically publish a customer-readable status artifact. */
function pmssMediaStackWatchdogStatusWrite(string $home, string $path, array $status): bool
{
    if ($path === '' || !pmssPathTargetIsSafe($path, false, true) || !pmssPathWithinResolvedRoot($path, $home)) {
        return false;
    }
    $encoded = pmssJsonEncodePrettyLine($status);
    $temporary = @tempnam(dirname($path), '.media-stack-status.');
    if (!is_string($encoded) || $temporary === false) {
        return false;
    }
    if (@file_put_contents($temporary, $encoded, LOCK_EX) === false || !@chmod($temporary, 0644) || !@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }

    return true;
}

/** Log state transitions to both the host cron stream and the per-user log. */
function pmssMediaStackWatchdogLogTransition(string $username, string $app, string $state, int $failures): void
{
    $message = 'Media stack watchdog: '.$username.' '.$app.' state='.$state.' consecutive_failures='.$failures;
    echo date('Y-m-d H:i:s').' '.$message.PHP_EOL;
    pmssUserLog($username, $message);
    if (function_exists('pmssLogJson')) {
        pmssLogJson(array('event' => 'media_stack_watchdog', 'user' => $username, 'app' => $app, 'state' => $state, 'consecutive_failures' => $failures));
    }
}

/** Observe one installed account and publish its current app states. */
function pmssMediaStackWatchdogRunUser(string $username, string $homeRoot = '/home', ?callable $probe = null): ?array
{
    if (!pmssValidateUsername($username)) {
        return null;
    }
    $home = rtrim($homeRoot, '/').'/'.$username;
    if (!is_dir($home) || !pmssMediaStackWatchdogInstalled($home)) {
        return null;
    }
    $apps = pmssMediaStackWatchdogExpectedApps($home);
    $path = pmssMediaStackWatchdogStatusPath($home);
    if ($apps === array() || $path === '') {
        return null;
    }

    $previous = pmssJsonFileReadAssoc($path, true) ?? array();
    $status = pmssMediaStackWatchdogSnapshot($username, $apps, $previous, $probe);
    if (!pmssMediaStackWatchdogStatusWrite($home, $path, $status)) {
        echo date('Y-m-d H:i:s').' Media stack watchdog: unable to publish status for '.$username.PHP_EOL;
        return $status;
    }

    $previousApps = isset($previous['apps']) && is_array($previous['apps']) ? $previous['apps'] : array();
    foreach ($status['apps'] as $app => $appStatus) {
        $state = (string) $appStatus['state'];
        $oldState = isset($previousApps[$app]['state']) ? (string) $previousApps[$app]['state'] : '';
        if ($state !== 'running' && $state !== $oldState) {
            pmssMediaStackWatchdogLogTransition($username, $app, $state, (int) $appStatus['consecutiveFailures']);
        } elseif ($state === 'running' && $oldState !== '' && $oldState !== 'running') {
            pmssMediaStackWatchdogLogTransition($username, $app, $state, 0);
        }
    }

    return $status;
}
