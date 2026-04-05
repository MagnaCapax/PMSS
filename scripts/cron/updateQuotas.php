#!/usr/bin/env php
<?php
/**
 * Nightly quota refresher.
 *
 * - Requires `quota` utilities and filesystem support for per-user quotas.
 * - Runs `quota -u <user> -v -s` for every tenant, storing the human-readable output
 *   in `/home/<user>/.quota` for support tooling.
 * - Logs failures via `Logger`, then continues to the next user so one broken
 *   account does not halt the sweep.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
// Update & check user quota information

require_once '/scripts/lib/logger.php';
require_once '/scripts/lib/userLifecycle.php';
require_once __DIR__.'/../lib/quotaSnapshot.php';
require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';
$logger = new Logger(__FILE__);

/**
 * Atomically replace a quota snapshot without exposing a missing-file window.
 *
 * Existing owner/group metadata is preserved when the refresher runs as root so
 * routine writes do not drift away from the current on-disk policy.
 */
function pmssQuotaSnapshotWrite(string $path, string $content, int $mode = 0644): bool
{
    $owner = null;
    $group = null;
    $canAdjustOwnership = function_exists('posix_geteuid') && @posix_geteuid() === 0;
    if ($canAdjustOwnership && is_file($path)) {
        $existingOwner = @fileowner($path);
        $existingGroup = @filegroup($path);
        if ($existingOwner !== false) {
            $owner = $existingOwner;
        }
        if ($existingGroup !== false) {
            $group = $existingGroup;
        }
    }

    return pmssReplaceUserFile($path, $content, static function (string $tmp) use ($group, $mode, $owner): void {
        @chmod($tmp, $mode);
        if ($owner !== null) {
            @chown($tmp, $owner);
        }
        if ($group !== null) {
            @chgrp($tmp, $group);
        }
    });
}

$logger->msg('Updating quota information');
// Get & parse users list
$users = pmssListManagedUsers('/scripts/listUsers.php');

// Keep the structured quota event payload and the optional per-user log line in
// one place so status/message pairs stay aligned across outcomes.
$writeQuotaUserLogs = static function (
    string $user,
    string $action,
    string $status,
    string $contextMessage,
    array $context = [],
    ?string $userLogMessage = null,
    bool $mirrorUserLog = true
): void {
    pmssUserLifecycleContextLog('quota', $action, $user, array('status' => $status, 'message' => $contextMessage) + $context);
    if ($mirrorUserLog) {
        pmssUserLog($user, $userLogMessage === null ? $contextMessage : $userLogMessage);
    }
};

foreach ($users as $thisUser) {
#TODO Check that quota is working
    // Invariant: verify the resolved home directory matches the expected
    // /home/<username> prefix before touching any files.
    $expectedHome = "/home/{$thisUser}";
    $realHome = realpath($expectedHome);
    if ($realHome === false || strpos($realHome, $expectedHome) !== 0) {
        $logger->msg("Refusing quota refresh for {$thisUser}: unexpected home path '{$realHome}'");
        $writeQuotaUserLogs(
            $thisUser,
            'invariant_home_prefix',
            'ERR',
            'Refusing quota refresh due to unexpected home path',
            array(
                'expected_home' => $expectedHome,
                'real_home' => $realHome,
            ),
            null,
            false
        );
        continue;
    }

    $quotaFile = "/home/{$thisUser}/.quota";
    // Keep the previous snapshot until a new one is validated; this avoids
    // leaving .quota missing/empty if the quota command fails.
    $existingQuotaContent = (file_exists($quotaFile) && !is_link($quotaFile))
        ? (string) @file_get_contents($quotaFile)
        : '';
    $hasExistingSnapshot = strpos($existingQuotaContent, 'Disk quotas') !== false;

    // Call quota once with a safely quoted username and capture its output.
    // Note: `quota` returns exit code 1 when user is over quota, but still
    // produces valid output. We only treat it as failure if no output or
    // exit code > 1 (which indicates actual errors like invalid user).
    $quotaCmd = 'quota -u '.escapeshellarg($thisUser).' -v -s 2>&1';
    $outputLines = [];
    $ret = 0;
    exec($quotaCmd, $outputLines, $ret);

    $content = implode(PHP_EOL, $outputLines).PHP_EOL;
    $content = pmssQuotaSnapshotNormalizeHumanReadableOutput($content);
    $hasValidOutput = count($outputLines) > 0 && strpos($content, 'Disk quotas') !== false;

    if ($ret > 1 || !$hasValidOutput) {
        // Actual failure: exit > 1 or no parseable output
        $logger->msg("quota command failed for {$thisUser} (exit {$ret})");
        $writeQuotaUserLogs(
            $thisUser,
            'refresh',
            'ERR',
            'Quota command failed',
            array('rc' => $ret),
            sprintf('quota refresh failed (rc=%d)', $ret)
        );

        if (!$hasExistingSnapshot && !is_link($quotaFile)) {
            // Emit a parseable fallback so UI consumers that read ~/.quota do
            // not crash on missing or empty files while quota tooling is down.
            $fallbackContent = implode(PHP_EOL, array(
                "Disk quotas for user {$thisUser} (uid 0):",
                '     Filesystem   space   quota   limit   grace   files   quota   limit   grace',
                '       /dev/null      0K      0K      0K               0       0       0',
                '',
            ));
            if (pmssQuotaSnapshotWrite($quotaFile, $fallbackContent)) {
                $logger->msg("quota fallback snapshot written for {$thisUser}");
                $writeQuotaUserLogs(
                    $thisUser,
                    'fallback',
                    'WARN',
                    'Quota fallback snapshot written after refresh failure',
                    [],
                    'Quota fallback snapshot written'
                );
            } else {
                $logger->msg("quota fallback snapshot write failed for {$thisUser}");
                $writeQuotaUserLogs(
                    $thisUser,
                    'fallback',
                    'ERR',
                    'Quota fallback snapshot write failed'
                );
            }
        }
    } else {
        // Success: exit 0 (normal) or exit 1 (over quota but valid output)
        if (!pmssQuotaSnapshotWrite($quotaFile, $content)) {
            $logger->msg("quota snapshot write failed for {$thisUser}");
            $writeQuotaUserLogs(
                $thisUser,
                'write',
                'ERR',
                'Quota snapshot write failed'
            );
            continue;
        }
        $logStatus = ($ret === 1) ? 'WARN' : 'OK';
        $logMsg = ($ret === 1) ? 'Quota refreshed (user over quota)' : 'Quota refreshed';
        $writeQuotaUserLogs(
            $thisUser,
            'refresh',
            $logStatus,
            $logMsg
        );
    }
}
