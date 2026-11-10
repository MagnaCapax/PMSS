<?php
/** Account quota refresh policy behind the locked cron entrypoint. @license GPL-3.0-only */
require_once dirname(__DIR__).'/quotaSnapshot.php';
require_once dirname(__DIR__).'/userLifecycle.php';

/** Keep lifecycle event fields and optional account-log mirroring in one path. */
function pmssQuotaSnapshotRefreshUserLog(
    string $user, string $action, string $status, string $contextMessage,
    array $context = [], ?string $userLogMessage = null, bool $mirrorUserLog = true
): void {
    pmssUserLifecycleContextLog('quota', $action, $user, array('status' => $status, 'message' => $contextMessage) + $context);
    if ($mirrorUserLog) {
        pmssUserLog($user, $userLogMessage === null ? $contextMessage : $userLogMessage);
    }
}

/** Refresh one managed account; false means a timed-out probe must stop the sweep. */
function pmssQuotaSnapshotRefreshUser(string $thisUser, callable $log): bool
{
    // #TODO Check that quota is working.
    // Require the exact home before any snapshot read, command or publication.
    $expectedHome = "/home/{$thisUser}";
    $realHome = realpath($expectedHome);
    if ($realHome === false || $realHome !== $expectedHome) {
        $log("Refusing quota refresh for {$thisUser}: unexpected home path '{$realHome}'");
        pmssQuotaSnapshotRefreshUserLog($thisUser, 'invariant_home_prefix', 'ERR',
            'Refusing quota refresh due to unexpected home path',
            array('expected_home' => $expectedHome, 'real_home' => $realHome), null, false);
        return true;
    }

    $quotaFile = "/home/{$thisUser}/.quota";
    // Special files cannot block the sweep; preserve a recognized previous snapshot.
    $existingQuotaContent = pmssReadRegularFileContents($quotaFile) ?? '';
    $hasExistingSnapshot = strpos($existingQuotaContent, 'Disk quotas') !== false;
    $quotaCmd = 'quota -u '.escapeshellarg($thisUser).' -v -s 2>&1';
    $quotaRun = pmssQuotaReportCapture($quotaCmd);
    if ($quotaRun['timed_out']) {
        // A hung quota usually means /home is unhealthy; do not attempt fallback writes.
        $log("quota command for {$thisUser} exceeded its ".PMSS_QUOTA_REPORT_TIMEOUT_SECONDS.'s deadline and was killed');
        pmssQuotaSnapshotRefreshUserLog($thisUser, 'refresh', 'ERR', 'Quota command timed out',
            array('rc' => $quotaRun['rc'], 'timeout_seconds' => PMSS_QUOTA_REPORT_TIMEOUT_SECONDS),
            sprintf('quota refresh timed out after %ds', PMSS_QUOTA_REPORT_TIMEOUT_SECONDS));
        return false;
    }

    $content = pmssQuotaSnapshotNormalizeHumanReadableOutput(implode(PHP_EOL, $quotaRun['lines']).PHP_EOL);
    if ($quotaRun['rc'] > 1 || count($quotaRun['lines']) === 0 || strpos($content, 'Disk quotas') === false) {
        $log("quota command failed for {$thisUser} (exit {$quotaRun['rc']})");
        pmssQuotaSnapshotRefreshUserLog($thisUser, 'refresh', 'ERR', 'Quota command failed',
            array('rc' => $quotaRun['rc']), sprintf('quota refresh failed (rc=%d)', $quotaRun['rc']));
        if (!$hasExistingSnapshot && !is_link($quotaFile)) {
            // Only a missing/unrecognized display copy receives the parseable fallback.
            $fallbackContent = implode(PHP_EOL, array(
                "Disk quotas for user {$thisUser} (uid 0):",
                '     Filesystem   space   quota   limit   grace   files   quota   limit   grace',
                '       /dev/null      0K      0K      0K               0       0       0',
                '',
            ));
            if (pmssQuotaSnapshotWrite($quotaFile, $fallbackContent)) {
                $log("quota fallback snapshot written for {$thisUser}");
                pmssQuotaSnapshotRefreshUserLog($thisUser, 'fallback', 'WARN',
                    'Quota fallback snapshot written after refresh failure', [], 'Quota fallback snapshot written');
            } else {
                $log("quota fallback snapshot write failed for {$thisUser}");
                pmssQuotaSnapshotRefreshUserLog($thisUser, 'fallback', 'ERR', 'Quota fallback snapshot write failed');
            }
        }
        return true;
    }

    // Exit 1 with valid output means over quota. Publish the root copy before the display copy.
    if (!pmssQuotaRootCopyWrite($thisUser, $content)) {
        $log("quota root copy write failed for {$thisUser}");
    }
    if (!pmssQuotaSnapshotWrite($quotaFile, $content)) {
        $log("quota snapshot write failed for {$thisUser}");
        pmssQuotaSnapshotRefreshUserLog($thisUser, 'write', 'ERR', 'Quota snapshot write failed');
        return true;
    }
    pmssQuotaSnapshotRefreshUserLog($thisUser, 'refresh', $quotaRun['rc'] === 1 ? 'WARN' : 'OK',
        $quotaRun['rc'] === 1 ? 'Quota refreshed (user over quota)' : 'Quota refreshed');
    return true;
}
