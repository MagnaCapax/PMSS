<?php
/**
 * One relaunch per boot for the media-stack watchdog (ADR 0302).
 *
 * The watchdog stays observe-only for crash loops: this helper only decides, from
 * the previously published snapshot, whether the first pass of a new boot should
 * bring back the apps the restart took down, and builds the detached tenant launch.
 * The decision record rides in the published snapshot (`relaunch`), so it needs no
 * extra state file and a failed snapshot write blocks the launch (latch, then launch).
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['user/watchdog.php']);
require_once __DIR__.'/rtorrent/processReadiness.php';

if (!defined('PMSS_MEDIA_STACK_RELAUNCH_MAX_DELAY')) {
    // Same window as the rTorrent post-boot stagger.
    define('PMSS_MEDIA_STACK_RELAUNCH_MAX_DELAY', 300);
}

/** Read the kernel boot ID; invalid paths or corrupt bytes retain the empty fallback. */
function pmssMediaStackBootIdRead(string $path = '/proc/sys/kernel/random/boot_id'): string
{
    $raw = $path === '' || pmssFilesystemPathHasNulByte($path) ? false : @file_get_contents($path);
    $id = is_string($raw) && strpos($raw, "\0") === false ? trim($raw) : '';
    return preg_match('/^[0-9a-f-]{8,64}$/D', $id) === 1 ? $id : '';
}

/** Read finite seconds since boot; invalid paths or corrupt bytes retain the zero fallback. */
function pmssMediaStackBootUptimeRead(string $path = '/proc/uptime'): float
{
    $raw = $path === '' || pmssFilesystemPathHasNulByte($path) ? false : @file_get_contents($path);
    $first = is_string($raw) && strpos($raw, "\0") === false ? explode(' ', trim($raw))[0] : '';
    return is_numeric($first) && is_finite((float) $first) && (float) $first > 0 ? (float) $first : 0.0;
}

/** Production context: boot ID, uptime, clock and the launcher that runs a root command. */
function pmssMediaStackBootRelaunchContext(): array
{
    return array(
        'bootId' => pmssMediaStackBootIdRead(),
        'uptime' => pmssMediaStackBootUptimeRead(),
        'now' => time(),
        // Output is passed through and never captured, so no pipe can be inherited by a daemon.
        'launcher' => static function (string $command): void {
            passthru($command);
        },
    );
}

/** Keep only app ids from the fixed definitions, in definition order; snapshot names are never trusted. */
function pmssMediaStackBootRelaunchAppsFilter($candidates, array $installed): array
{
    $candidates = is_array($candidates) ? array_filter($candidates, 'is_string') : array();
    return array_values(array_intersect(array_keys($installed), $candidates));
}

/** Apps not failed (running or stopped) in the pre-restart snapshot; failed and off apps stay down. */
function pmssMediaStackBootRelaunchSelect(array $previous, array $installed): array
{
    $previousApps = is_array($previous['apps'] ?? null) ? $previous['apps'] : array();
    $selected = array();
    foreach (array_keys($installed) as $app) {
        $state = is_array($previousApps[$app] ?? null) ? ($previousApps[$app]['state'] ?? null) : null;
        if ($state === 'running' || $state === 'stopped') $selected[] = $app;
    }
    return $selected;
}

/**
 * Decide this pass's relaunch step from the previous snapshot and the boot context.
 *
 * @return array{record:?array,action:string,apps:array} action is none, launch or suspended.
 */
function pmssMediaStackBootRelaunchPlan(string $username, string $homeRoot, array $previous, array $installed, array $boot): array
{
    $bootId = (string) ($boot['bootId'] ?? '');
    if ($bootId === '') {
        return array('record' => null, 'action' => 'none', 'apps' => array());
    }
    $latched = static function (array $apps = array(), string $skipped = '') use ($bootId): array {
        return array('bootId' => $bootId, 'apps' => $apps, 'done' => true) + ($skipped === '' ? array() : array('skipped' => $skipped));
    };

    $record = is_array($previous['relaunch'] ?? null) ? $previous['relaunch'] : null;
    $recordApps = $record === null ? array() : pmssMediaStackBootRelaunchAppsFilter($record['apps'] ?? array(), $installed);
    if ($record !== null && ($record['bootId'] ?? null) === $bootId) {
        // This boot already has its decision: a latched one is final, a pending one waits for its stagger.
        if (!empty($record['done'])) {
            return array('record' => $latched($recordApps, (string) ($record['skipped'] ?? '')), 'action' => 'none', 'apps' => array());
        }
    } else {
        // Another boot's state counts only from a snapshot written before this boot began. A later one
        // came from a deploy onto a running host, a transfer from another host (the target home may
        // still be copying in) or an edit by the tenant: none of them relaunches.
        $stamp = is_string($previous['timestamp'] ?? null) ? strtotime($previous['timestamp']) : false;
        $bootStart = (int) ($boot['now'] ?? 0) - (int) ($boot['uptime'] ?? 0);
        if ($stamp === false || $stamp >= $bootStart) {
            return array('record' => $latched(), 'action' => 'none', 'apps' => array());
        }
    }
    // A selection still pending from an earlier boot survives a second restart; otherwise select afresh.
    $apps = $record !== null && empty($record['done']) ? $recordApps : pmssMediaStackBootRelaunchSelect($previous, $installed);

    if ($apps === array()) {
        return array('record' => $latched(), 'action' => 'none', 'apps' => array());
    }
    if (pmssUserServicesParked($username, $homeRoot)) {
        return array('record' => $latched($apps, 'suspended'), 'action' => 'suspended', 'apps' => $apps);
    }
    if ((float) ($boot['uptime'] ?? 0) < rtorrentProcessStaggerDelay($username, PMSS_MEDIA_STACK_RELAUNCH_MAX_DELAY)) {
        // Carry the selection: later passes overwrite the previous states, and absent apps turn failed.
        return array('record' => array('bootId' => $bootId, 'apps' => $apps, 'done' => false), 'action' => 'none', 'apps' => array());
    }
    return array('record' => $latched($apps), 'action' => 'launch', 'apps' => $apps);
}

/**
 * The tenant-side command: returns at once, runs only an installer copy that has the start-stopped mode.
 *
 * The redirect sits on the whole backgrounded group, not on the installer alone: the group's
 * subshell would otherwise keep the caller's stdout pipe open and passthru() would wait for it.
 */
function pmssMediaStackBootRelaunchInnerCommand(array $apps): string
{
    $ids = implode(',', array_values(array_intersect(array_keys(pmssMediaStackWatchdogAppDefinitions()), $apps)));
    return 'cd ~; { grep -q -- '.escapeshellarg('--start-stopped)').' install-media-stack.sh && PMSS_MEDIA_STACK_START_APPS='
        .escapeshellarg($ids).' nohup /bin/bash install-media-stack.sh --start-stopped; } >/dev/null 2>&1 </dev/null &';
}

/** Root command: close the cron lock descriptors, then start the tenant command in user-UID.slice (non-login). */
function pmssMediaStackBootRelaunchCommand(string $username, array $apps): string
{
    return pmssLockChildClosePrefix().pmssBuildUserServiceShellCommand($username, pmssMediaStackBootRelaunchInnerCommand($apps));
}

/** Log and perform the relaunch the plan asks for; the caller has already published the latch. */
function pmssMediaStackBootRelaunchRun(string $username, array $plan, callable $launcher): void
{
    if ($plan['action'] === 'none') return;
    $apps = implode(',', $plan['apps']);
    $launch = $plan['action'] === 'launch';
    $message = 'Media stack watchdog: '.$username.' boot relaunch '.($launch ? 'apps=' : 'skipped (suspended or moved) apps=').$apps;
    pmssUserWatchdogLogTransition($username, $message, array('event' => 'media_stack_boot_relaunch', 'user' => $username, 'apps' => $apps, 'launched' => $launch));
    if ($launch) {
        $launcher(pmssMediaStackBootRelaunchCommand($username, $plan['apps']));
    }
}
