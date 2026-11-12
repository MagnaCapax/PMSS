<?php
/** Account convergence; loaded by the userMaintenance.php facade. */

/** Return whether lighttpd deferred the marker; throwables remain owned by the queue. */
function pmssUserMaintenanceAccountRefresh(
    string $userTrim, string $rutorrentIndexSha, string $refreshSignature,
    bool $resumeAlreadyDone, UserConfigStore $userConfigStore, array $postChecks, float $userStart
): bool {
    if ($resumeAlreadyDone || !pmssUserCgroupSliceSelfHeal($userTrim, $userConfigStore)) {
        throw new RuntimeException('cgroup slice memory policy refresh failed');
    }

    // Environment convergence precedes linger and optional subprocess checks.
    pmssUserMaintenanceRepairLegacyCpuQuota($userTrim);
    $environmentReason = null;
    if (!pmssUpdateUserEnvironment($userTrim, $rutorrentIndexSha, $environmentReason)) {
        $environmentMessage = 'user environment convergence failed';
        if (is_string($environmentReason) && trim($environmentReason) !== '') {
            $environmentMessage .= ': '.$environmentReason;
        }
        throw new RuntimeException($environmentMessage);
    }
    pmssEnsureLingerAndDocker($userTrim);

    foreach ($postChecks as $helperPath => [, $label, $skipExitCodes]) {
        $rc = runUserStep($userTrim, $label, pmssBuildCommand($helperPath, [$userTrim]), $skipExitCodes);
        if ($rc !== 0 && !in_array($rc, $skipExitCodes, true)) {
            pmssUserLog($userTrim, sprintf('[WARN] %s failed (rc=%d)', $label, $rc));
        }
    }

    $userDuration = microtime(true) - $userStart;
    pmssUserLog($userTrim, sprintf('update-step2: user maintenance finished (%.2fs)', $userDuration));
    $lighttpdFailed = pmssUserLighttpdRefreshFailed($userTrim);
    if ($lighttpdFailed) {
        pmssUserLog($userTrim, '[WARN] Skipping user refresh marker: lighttpd refresh incomplete; will retry next update');
    }
    // Profile before publishing the marker so recording failures still force a retry.
    pmssCommandProfileRecord('updateUser '.$userTrim, '', $lighttpdFailed ? 'ERR' : 'OK', $lighttpdFailed ? 1 : 0, $userDuration, false);
    if (!$lighttpdFailed) pmssUserRefreshMarkDone($userTrim, $refreshSignature);
    return $lighttpdFailed;
}
