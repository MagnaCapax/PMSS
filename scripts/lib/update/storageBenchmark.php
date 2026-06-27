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
require_once __DIR__.'/managedPath.php';
require_once __DIR__.'/../user/selection.php';

/** Return the durable success marker path for the post-install benchmark. */
function pmssStorageBenchmarkPostInstallMarkerPath(): string
{
    return pmssResolvePathFromEnv('PMSS_STORAGE_BENCHMARK_INSTALL_MARKER', '/var/lib/pmss/storage-benchmark-post-install.json');
}

/** Return true only when a prior successful post-install benchmark is marked. */
function pmssStorageBenchmarkPostInstallMarkerExists(): bool
{
    $path = pmssStorageBenchmarkPostInstallMarkerPath();
    return pmssPathAbsoluteStringIsSafe($path, ['allowRoot' => false, 'allowTrailingSlash' => false])
        && is_file($path)
        && !is_link($path);
}

/** Decide whether the post-install benchmark should run. */
function pmssStorageBenchmarkPostInstallDecision(array $managedUsers, bool $markerExists): string
{
    if ($managedUsers !== array()) {
        return 'users_present';
    }
    if ($markerExists) {
        return 'already_completed';
    }
    return 'run';
}

/** Build the storage benchmark CLI command with the required idle/device gates. */
function pmssStorageBenchmarkPostInstallCommand(): string
{
    return pmssBuildCommand('php', [
        '/scripts/util/storageBenchmark.php',
        '--require-idle',
        '--devices',
        '--label=post-install',
    ]);
}

/** Sanitize one benchmark output line before mirroring it into the update log. */
function pmssStorageBenchmarkPostInstallLogLine(string $line): string
{
    $line = preg_replace('/[[:cntrl:]]+/', ' ', $line);
    $line = trim(is_string($line) ? $line : '');
    return strlen($line) > 1000 ? substr($line, 0, 1000).' ...' : $line;
}

/** Mirror captured benchmark stdout into the update log so WARN lines persist. */
function pmssStorageBenchmarkPostInstallLogCapturedOutput(callable $logger, int $maxLines = 250): void
{
    $stdout = (string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '');
    $lines = preg_split('/\R/', trim($stdout));
    if (!is_array($lines) || $lines === array()) {
        return;
    }

    $logged = 0;
    foreach ($lines as $line) {
        $line = pmssStorageBenchmarkPostInstallLogLine((string) $line);
        if ($line === '') {
            continue;
        }
        if ($logged >= $maxLines) {
            $logger('[storage-benchmark] output truncated after '.$maxLines.' lines');
            return;
        }
        $logger('[storage-benchmark] '.$line);
        $logged++;
    }
}

/** Record that the post-install storage benchmark completed successfully. */
function pmssStorageBenchmarkPostInstallWriteMarker(callable $logger): bool
{
    $path = pmssStorageBenchmarkPostInstallMarkerPath();
    $payload = pmssJsonEncodePrettyLine([
        'timestamp' => date('c'),
        'event'     => 'storage_benchmark_post_install',
        'rc'        => 0,
    ]);
    if (!is_string($payload)) {
        $logger('[WARN] Unable to encode post-install storage benchmark marker payload');
        return false;
    }

    return pmssRefreshManagedPathFile($path, $payload, 'post-install storage benchmark marker', $logger, [
        'directoryMode'       => 0755,
        'mode'                => 0644,
        'successMessage'      => 'Recorded post-install storage benchmark marker at '.$path,
        'writeFailureMessage' => '[WARN] Unable to record post-install storage benchmark marker at '.$path,
    ]);
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
    $decision = pmssStorageBenchmarkPostInstallDecision($managedUsers, pmssStorageBenchmarkPostInstallMarkerExists());
    if ($decision === 'users_present') {
        $log('[SKIP] Managed users already exist; skipping post-install storage benchmark');
        return 0;
    }
    if ($decision === 'already_completed') {
        $log('[SKIP] Post-install storage benchmark already completed');
        return 0;
    }

    $run = $runner ?: 'runStep';
    $rc = (int) $run('Running post-install idle storage benchmark', pmssStorageBenchmarkPostInstallCommand());
    pmssStorageBenchmarkPostInstallLogCapturedOutput($log);
    if ($rc !== 0) {
        $log('[WARN] Post-install storage benchmark did not complete (rc='.$rc.'); leaving marker unset');
        return $rc;
    }

    return pmssStorageBenchmarkPostInstallWriteMarker($log) ? 0 : 1;
}
