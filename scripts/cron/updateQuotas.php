#!/usr/bin/env php
<?php
/**
 * Nightly quota refresher.
 *
 * - Requires `quota` utilities and filesystem support for per-user quotas.
 * - Runs `quota -u <user>` for every tenant, storing the human-readable output
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
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}
$logger = new Logger(__FILE__);

$logger->msg('Updating quota information');
// Get & parse users list
$users = explode("\n", trim((string) shell_exec('/scripts/listUsers.php')));
$changedConfig = array();

foreach ($users as $thisUser) {
#TODO Check that quota is working
    $thisUser = trim($thisUser);
    if ($thisUser === '') {
        continue;
    }
    if (!pmssValidateUsername($thisUser)) {
        $logger->msg("Skipping invalid username {$thisUser} during quota refresh");
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'quota',
                'validate',
                $thisUser,
                array(
                    'status'  => 'ERR',
                    'message' => 'Invalid username encountered during quota refresh',
                )
            )
        );
        continue;
    }

    // Invariant: verify the resolved home directory matches the expected
    // /home/<username> prefix before touching any files.
    $expectedHome = "/home/{$thisUser}";
    $realHome = realpath($expectedHome);
    if ($realHome === false || strpos($realHome, $expectedHome) !== 0) {
        $logger->msg("Refusing quota refresh for {$thisUser}: unexpected home path '{$realHome}'");
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'quota',
                'invariant_home_prefix',
                $thisUser,
                array(
                    'status'        => 'ERR',
                    'message'       => 'Refusing quota refresh due to unexpected home path',
                    'expected_home' => $expectedHome,
                    'real_home'     => $realHome,
                )
            )
        );
        continue;
    }

    $quotaFile = "/home/{$thisUser}/.quota";
    // Remove any existing quota file via PHP to avoid multi-command shell strings.
    if (file_exists($quotaFile) && !is_link($quotaFile)) {
        @unlink($quotaFile);
    }

    // Call quota once with a safely quoted username and capture its output.
    // Note: `quota` returns exit code 1 when user is over quota, but still
    // produces valid output. We only treat it as failure if no output or
    // exit code > 1 (which indicates actual errors like invalid user).
    $quotaCmd = 'quota -u '.escapeshellarg($thisUser).' -s 2>&1';
    $outputLines = [];
    $ret = 0;
    exec($quotaCmd, $outputLines, $ret);

    $content = implode(PHP_EOL, $outputLines).PHP_EOL;
    $hasValidOutput = count($outputLines) > 0 && strpos($content, 'Disk quotas') !== false;

    if ($ret > 1 || !$hasValidOutput) {
        // Actual failure: exit > 1 or no parseable output
        $logger->msg("quota command failed for {$thisUser} (exit {$ret})");
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'quota',
                'refresh',
                $thisUser,
                array(
                    'status'  => 'ERR',
                    'message' => 'Quota command failed',
                    'rc'      => $ret,
                )
            )
        );
        if (function_exists('pmssUserLog')) {
            pmssUserLog($thisUser, sprintf('quota refresh failed (rc=%d)', $ret));
        }
    } else {
        // Success: exit 0 (normal) or exit 1 (over quota but valid output)
        if (@file_put_contents($quotaFile, $content) !== false) {
            @chmod($quotaFile, 0644);
        }
        $logStatus = ($ret === 1) ? 'WARN' : 'OK';
        $logMsg = ($ret === 1) ? 'Quota refreshed (user over quota)' : 'Quota refreshed';
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'quota',
                'refresh',
                $thisUser,
                array(
                    'status'  => $logStatus,
                    'message' => $logMsg,
                )
            )
        );
        if (function_exists('pmssUserLog')) {
            pmssUserLog($thisUser, $logMsg);
        }
    }
}
