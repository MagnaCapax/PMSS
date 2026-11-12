<?php
/**
 * User maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime/commands.php';
pmssRequireRelativeFiles(__DIR__, [
    'systemPrep.php', 'users.php', '../pathSafety.php', '../users.php', '../userLifecycle.php',
    '../user/userCgroupSliceHealth.php', '../user/directories.php',
]);
require_once __DIR__.'/users/docker.php';
// Source-contract marker for the delegated Docker module: 'users/docker.php'.

pmssRequireRelativeFiles(__DIR__.'/users', ['refreshState.php', 'legacyCpuQuota.php', 'maintenanceAccount.php']);

/**
 * Refresh ruTorrent and skeleton data for every provisioned user.
 */
function pmssUpdateAllUsers(string $rutorrentIndexSha): array
{
    if (!pmssEnvFlagEnabled('PMSS_DRY_RUN') && !pmssEnvFlagEnabled('PMSS_TEST_MODE')) {
        $staleLingerCount = pmssUserMaintenancePruneStaleLinger();
        logMessage(sprintf('Removed %d stale systemd linger flag(s)', $staleLingerCount));
    }
    $users = users::listHomeUsers();
    sort($users, SORT_NATURAL | SORT_FLAG_CASE);
    $summary = ['total' => count($users), 'processed' => 0, 'skipped' => 0, 'skip_reasons' => []];
    $incomplete = false;
    $refreshSignature = pmssUserRefreshSignature($rutorrentIndexSha);
    $dockerPackagesChanged = getenv('PMSS_DOCKER_PACKAGES_CHANGED') === '1';
    $userConfigStore = new UserConfigStore();
    logMessage(sprintf('Per-user maintenance: %d user(s) to process', $summary['total']));
    $isTty = pmssStreamIsTty(STDOUT);
    // Each optional check owns its display phase, command label and accepted exit codes.
    $postChecks = array_filter([
        '/scripts/util/checkUserHtpasswd.php' => ['Legacy htpasswd sync', 'Synchronizing per-user htpasswd', []],
        '/scripts/cron/checkLighttpdInstances.php' => ['Lighttpd instance check', 'Checking lighttpd instance', [PMSS_CRON_LOCK_BUSY_EXIT]],
    ], static function (string $path): bool { return is_file($path); }, ARRAY_FILTER_USE_KEY);
    $phases = array_merge(['Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)'], array_column($postChecks, 0));

    foreach ($users as $user) {
        if (($userTrim = trim($user)) === '' || !pmssValidateUsername($userTrim)) {
            $summary['skipped']++;
            $reason = $userTrim === '' ? 'empty username entry' : 'invalid username';
            $label = $userTrim === '' ? '(empty)' : $userTrim;
            $summary['skip_reasons'][] = $label.': '.$reason;
            logMessage(sprintf("[WARN] Account '%s' skipped during environment refresh: %s", $label, $reason));
            continue;
        }

        $resumeAlreadyDone = pmssUserRefreshAlreadyDone($userTrim, $refreshSignature);
        if (!$dockerPackagesChanged && $resumeAlreadyDone && pmssUserCgroupSliceSelfHeal($userTrim, $userConfigStore)) {
            $summary['processed']++;
            logMessage(sprintf('User %s already refreshed this version; skipping (resume)', $userTrim));
            continue;
        }

        if ($isTty) {
            echo PHP_EOL.pmssCliAnsiStyle('Updating user '.$userTrim, '35').PHP_EOL;
            foreach ($phases as $phase) {
                echo '  '.pmssCliAnsiStyle('* '.$phase, '33').PHP_EOL;
            }
        } else {
            logMessage(sprintf('Updating user %s phases: %s', $userTrim, implode(', ', $phases)));
        }

        $userStart = microtime(true);
        // A retry in this process starts with fresh per-user refresh state.
        pmssUserLighttpdRefreshFailed($userTrim, false);

        try {
            $incomplete = pmssUserMaintenanceAccountRefresh(
                $userTrim, $rutorrentIndexSha, $refreshSignature, $resumeAlreadyDone, $userConfigStore, $postChecks, $userStart
            ) || $incomplete;
            // Processed includes completed passes with a deferred marker;
            // skipped remains reserved for accounts whose maintenance aborted.
            $summary['processed']++;
        } catch (\Throwable $throwable) {
            $summary['skipped']++;
            $userDuration = microtime(true) - $userStart;
            $reason = get_class($throwable).($throwable->getMessage() === '' ? '' : ': '.$throwable->getMessage());
            $summary['skip_reasons'][] = $userTrim.': '.$reason;

            logMessage(sprintf("[WARN] Account '%s' skipped during environment refresh: %s", $userTrim, $reason));
            pmssUserLog($userTrim, '[WARN] update-step2 user maintenance aborted: '.$reason);
            pmssCommandProfileRecord('updateUser '.$userTrim, '', 'ERR', 1, $userDuration, false, '', substr(pmssLogWhitespaceCollapse($reason), 0, 300));
        }
    }

    $summaryStatus = $summary['processed'] < $summary['total'] || $incomplete ? 'warn' : 'ok';
    logMessage(sprintf('%sProcessed %d of %d users', $summaryStatus === 'warn' ? '[WARN] ' : '', $summary['processed'], $summary['total']));
    pmssLogJson([
        'event'     => 'user_maintenance_summary',
        'status'    => $summaryStatus,
    ] + $summary);

    return $summary;
}
