<?php
/**
 * Make it impossible for root to run a Servarr (*ARR) application.
 *
 * PMSS installs these applications and MUST NEVER execute them. They are per-account services
 * that run as the customer's own uid: etc/skel/install-media-stack.sh gives every account a
 * private copy under ~/.bin. The shared /opt install stays world-executable so any customer can
 * use it at will -- this module does not touch /opt in any way.
 *
 * Root has no legitimate instance. When something starts one by accident the application writes
 * its OWN first-run defaults -- AuthenticationMethod=None and BindAddress=* -- i.e. an
 * unauthenticated root-privileged HTTP API on a public port. That produced the 2026-07-03 fleet
 * root compromise, exploited in 4.1 seconds (unauthenticated backup-restore ZIP write as root).
 *
 * MECHANISM: occupy the application's data-directory path with a regular FILE. With no --data
 * flag the app derives its data directory from $HOME/.config/<App>, which for root is
 * /root/.config/<App>. A regular file there makes that directory un-creatable, and the app aborts
 * during logger initialisation -- BEFORE it opens a socket.
 *
 * MEASURED on /opt/Radarr/Radarr, running as ROOT with the file in place: rc=134,
 * "System.IO.DirectoryNotFoundException", the port was never bound, and the file's sha256 was
 * unchanged afterwards -- the application does not unlink what blocks it, even with root
 * privileges. Positive control, same binary with a creatable data directory: starts normally and
 * reaches ApplicationStartedEvent. That control is why customers keep full use of the shared
 * install; the abort is caused by the file, not by uid or /opt permissions.
 *
 * WHY NOT seed an authenticated config instead (Forms + AuthenticationRequired=Enabled, which was
 * measured to genuinely deny: unauthenticated API 401, / 302, valid ApiKey 200): that still leaves
 * a RUNNING root instance, reachable from every local shell on a multi-tenant host. Prevention
 * beats mitigation, and the two are mutually exclusive -- this file occupies the very directory a
 * seeded config would live in.
 *
 * WHY NOT delete a leftover root config: an absent config IS the first-run condition, so the app
 * would regenerate AuthenticationMethod=None / BindAddress=* on the next launch. Deletion is
 * security-negative here. Existing directories are moved aside, never removed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/managedPath.php';
require_once __DIR__.'/apps/arr.php';

// Suffix marking a root config directory that was moved aside to free the path.
const PMSS_ARR_ROOT_BLOCK_SUFFIX = '.pmss-disabled-';

/**
 * Body of the block file. Explains itself in place: the next person to meet this file is someone
 * whose root launch just aborted, and the abort message only names the path.
 */
function pmssArrRootBlockNotice(string $app): string
{
    return 'PMSS: root must never run '.$app.".\n"
        ."This regular file occupies the path the application uses for its data directory, so a\n"
        ."root launch aborts before it opens a socket. Customers are unaffected: they run their own\n"
        ."copy from ~/.bin (install-media-stack.sh) and the shared /opt install stays executable.\n"
        ."Removing this file re-opens the path that produced the 2026-07-03 root compromise.\n"
        .'See scripts/lib/update/arrRootExecutionBlock.php for the measured evidence.'."\n";
}

/**
 * Free the app's data path when a directory occupies it, preserving that directory's contents.
 *
 * Returns true when the path is free for a file write. A move that would clobber an existing
 * backup, or that fails outright, returns false so the caller leaves the host untouched rather
 * than destroying a forensic record.
 */
function pmssArrRootBlockClearDirectory(string $path, string $app, string $timestamp, callable $log): bool
{
    if (!is_dir($path)) {
        return true;
    }

    $backup = $path.PMSS_ARR_ROOT_BLOCK_SUFFIX.$timestamp;
    if (file_exists($backup)) {
        $log('[WARN] '.$app.': '.$backup.' already exists, leaving '.$path.' untouched');
        return false;
    }
    if (!@rename($path, $backup)) {
        $log('[WARN] '.$app.': unable to move '.$path.' aside, leaving it untouched');
        return false;
    }

    $log($app.': moved leftover root config '.$path.' to '.$backup.' (kept, never deleted)');
    return true;
}

/**
 * Ensure every *ARR application is unlaunchable by root on this host.
 *
 * Applied unconditionally rather than only where an install exists: the file is tiny, and a host
 * that gains an install later is then already protected instead of waiting for the next update.
 */
function pmssEnsureArrRootExecutionBlocked(callable $log, string $configRoot = '/root/.config', ?string $timestamp = null): void
{
    $stamp = $timestamp ?? gmdate('YmdHis');

    foreach (array_keys(PMSS_ARR_APP_BRANCHES) as $app) {
        $path = $configRoot.'/'.$app;

        if (is_link($path)) {
            $log('[WARN] '.$app.': symlinked root config path, refusing to touch '.$path);
            continue;
        }
        if (!pmssArrRootBlockClearDirectory($path, $app, $stamp, $log)) {
            continue;
        }

        if (pmssRefreshManagedPathFile($path, pmssArrRootBlockNotice($app), $app.' root execution block', $log, [
            'mode' => 0444,
            'directoryMode' => 0700,
        ])) {
            $log($app.': root execution blocked at '.$path);
        }
    }
}
