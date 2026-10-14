<?php
/** PMSS-managed Intel render-node access for shared hardware transcoding. */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/commands.php';

/** Install the Intel-only udev rule and apply it to existing DRM devices. */
function pmssEnsureIntelRenderNodeAccess(?callable $logger = null, ?string $target = null, ?callable $commandRunner = null, ?callable $commandPath = null): void
{
    $log = $logger ?: 'logMessage';
    $target = $target ?? '/etc/udev/rules.d/70-pmss-render-node.rules';
    $commandRunner = $commandRunner ?: 'runStep';
    $commandPath = $commandPath ?: 'pmssCommandPath';
    $content = "# PMSS-managed: allow all local accounts to use Intel render nodes.\n"
        ."SUBSYSTEM==\"drm\", KERNEL==\"renderD*\", DRIVERS==\"i915|xe\", MODE=\"0666\"\n";
    $label = 'Intel render-node access rule';
    $options = pmssManagedPathInstallOptions($target, $label, ['mode' => 0644]);
    if (!pmssRefreshManagedPathFile($target, $content, $label, $log, $options)) {
        return;
    }

    $udevadm = $commandPath('udevadm');
    if ($udevadm === '') {
        $log('[WARN] udevadm unavailable; Intel render-node rule will apply on the next device event');
        return;
    }
    if ($commandRunner('Reloading udev rules for Intel render nodes', escapeshellarg($udevadm).' control --reload') !== 0) {
        $log('[WARN] Unable to reload udev rules for Intel render nodes');
        return;
    }
    if ($commandRunner('Applying Intel render-node access', escapeshellarg($udevadm).' trigger --subsystem-match=drm --action=change') !== 0) {
        $log('[WARN] Unable to trigger DRM devices for Intel render-node access');
    }
}
