<?php
/**
 * Post-install storage benchmark trigger for update-step2.
 *
 * Runs the existing non-destructive benchmark once on empty hosts so fresh
 * installs capture a storage baseline before the first tenant is created.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
pmssRequireRelativeFiles(__DIR__, ['managedPath.php', '../user/selection.php']);

/** Return the durable success marker path for the post-install benchmark. */
function pmssStorageBenchmarkPostInstallMarkerPath(): string
{
    return pmssResolvePathFromEnv('PMSS_STORAGE_BENCHMARK_INSTALL_MARKER', '/var/lib/pmss/storage-benchmark-post-install.json');
}

/**
 * Run the post-install storage benchmark when the host is still tenant-empty.
 *
 * @param callable|null $logger
 * @param callable|null $runner
 * @param callable|null $userLister
 */
function pmssStorageBenchmarkPostInstallRun(?callable $logger = null, ?callable $runner = null, ?callable $userLister = null): int
{
    $log = $logger ?: 'logmsg';
    $rawUsers = $userLister === null ? pmssManagedHomeUsersList(true) : $userLister();
    if (!is_array($rawUsers)) {
        $log('[WARN] Unable to confirm no managed users; skipping post-install storage benchmark');
        return 0;
    }

    $managedUsers = pmssManagedUsersNormalizeList($rawUsers);
    // Observe the marker before either skip, retaining managed-user precedence.
    $path = pmssStorageBenchmarkPostInstallMarkerPath();
    $markerExists = pmssPathAbsoluteStringIsSafe($path, ['allowRoot' => false, 'allowTrailingSlash' => false])
        && is_file($path)
        && !is_link($path);
    if ($managedUsers !== array()) {
        $log('[SKIP] Managed users already exist; skipping post-install storage benchmark');
        return 0;
    }
    if ($markerExists) {
        $log('[SKIP] Post-install storage benchmark already completed');
        return 0;
    }

    $run = $runner ?: 'runStep';
    $rc = (int) $run('Running post-install idle storage benchmark', pmssBuildCommand('php', [
        '/scripts/util/storageBenchmark.php', '--require-idle', '--devices', '--label=post-install',
    ]));
    // Mirror captured stdout into the update log so WARN lines persist.
    $stdout = (string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '');
    $lines = preg_split('/\R/', trim($stdout));
    $logged = 0;
    foreach (is_array($lines) ? $lines : [] as $line) {
        $line = pmssLogExcerpt(trim(pmssLogControlCharactersReplace((string) $line)), 1000, ' ...');
        if ($line === '') continue;
        if ($logged >= 250) {
            $log('[storage-benchmark] output truncated after 250 lines');
            break;
        }
        $log('[storage-benchmark] '.$line);
        $logged++;
    }
    if ($rc !== 0) {
        $log('[WARN] Post-install storage benchmark did not complete (rc='.$rc.'); leaving marker unset');
        return $rc;
    }

    // Resolve the marker again after the runner and logger have completed.
    $path = pmssStorageBenchmarkPostInstallMarkerPath();
    $payload = pmssJsonEncodePrettyLine([
        'timestamp' => date('c'),
        'event'     => 'storage_benchmark_post_install',
        'rc'        => 0,
    ]);
    if (!is_string($payload)) {
        $log('[WARN] Unable to encode post-install storage benchmark marker payload');
        return 1;
    }

    return pmssRefreshManagedPathFile($path, $payload, 'post-install storage benchmark marker', $log, [
        'directoryMode'       => 0755,
        'mode'                => 0644,
        'successMessage'      => 'Recorded post-install storage benchmark marker at '.$path,
        'writeFailureMessage' => '[WARN] Unable to record post-install storage benchmark marker at '.$path,
    ]) ? 0 : 1;
}
