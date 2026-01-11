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
 */
// Update & check user quota information
const LOG_FILE     = '/var/log/pmss/updateQuotas.log';
const FALLBACK_LOG = '/tmp/updateQuotas.log';

require_once '/scripts/lib/logger.php';
require_once '/scripts/lib/userLifecycle.php';
$logger = new Logger(__FILE__);

$logger->msg('Updating quota information');
// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$changedConfig = array();

foreach ($users as $thisUser) {
#TODO(user-logs): log quota refresh success/failure to /var/log/pmss/user-<username>.log for support traceability
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
    $quotaCmd = 'quota -u '.escapeshellarg($thisUser).' -s 2>&1';
    $outputLines = [];
    $ret = 0;
    exec($quotaCmd, $outputLines, $ret);

    if ($ret !== 0) {
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
    } else {
        $content = implode(PHP_EOL, $outputLines).PHP_EOL;
        if (@file_put_contents($quotaFile, $content) !== false) {
            @chmod($quotaFile, 0644);
        }
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'quota',
                'refresh',
                $thisUser,
                array(
                    'status'  => 'OK',
                    'message' => 'Quota refreshed',
                )
            )
        );
    }
}
