#!/usr/bin/env php
<?php
/** GUI watchdog account traversal and CLI entrypoint. @license GPL-3.0-only */

require_once __DIR__.'/../lib/runtime.php';
pmssRequireRelativeFiles(__DIR__.'/../lib', [
    'logger.php', 'lighttpd/userFileWrite.php', 'user/selection.php',
    'gui/watchdogPaths.php', 'gui/watchdogRepair.php',
    'update/users/filesystem.php', 'update/users/context.php', 'update/users/webRootReconcile.php',
]);

/**
 * Run the GUI watchdog for every managed home user.
 */
function pmssCheckGuiMain(array $argv): int
{
    $log = [new Logger(__FILE__), 'msg'];
    $homeRoot = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/');

    foreach (pmssManagedHomeUsersList() as $thisUser) {
        $thisUser = pmssCheckGuiManagedUserNameNormalize($thisUser);
        if ($thisUser === null) {
            $log('Skipping invalid managed-user entry while checking GUI files');
            continue;
        }

        // User suspended check after the iterator value has been revalidated.
        $homeDir = $homeRoot."/{$thisUser}";
        if (file_exists($homeDir.'/www-disabled')) continue;
        $wwwDir = $homeDir.'/www';

        if (!is_dir($homeDir) || is_link($homeDir)) {
            $log("Skipping {$thisUser}: home directory missing or unsafe at {$homeDir}");
            continue;
        }

        // The shared reconciler is intentionally gated so healthy users keep
        // the cheap watchdog path while damaged roots converge completely.
        pmssCheckGuiWebRootReconcileIfSentinelMissing($thisUser, $homeDir, $log);

        // Recreate core userspace paths if users accidentally remove them.
        if (!pmssCheckGuiEnsureUserDirectory($wwwDir, $thisUser, 'www', $log, $homeDir)) {
            continue;
        }
        pmssCheckGuiEnsureUserDirectory($homeDir.'/data', $thisUser, 'data', $log, $homeDir);

        // Restore the catalog before the split helpers that require it.
        foreach (array_merge(['toggleAppCatalog.php', 'index.php', 'scriptsInc.php'], pmssUserPanelHelperFiles()) as $guiFile) {
            pmssCheckGuiRestoreUserFile(
                $wwwDir.'/'.$guiFile,
                '/etc/skel/www/'.$guiFile,
                $thisUser,
                $guiFile,
                $log,
                $homeDir
            );
        }

        #TODO Check responsiveness etc. other common stuff as well.
    }

    return 0;
}

$pmssCheckGuiScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
if ($pmssCheckGuiScript !== '' && realpath($pmssCheckGuiScript) === realpath(__FILE__)) {
    exit(pmssCheckGuiMain($argv ?? []));
}
