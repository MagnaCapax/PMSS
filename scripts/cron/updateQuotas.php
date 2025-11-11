#!/usr/bin/php
<?php
/**
 * Nightly quota refresher.
 *
 * - Requires `quota` utilities and filesystem support for per-user quotas.
 * - Runs `quota -u <user>` for every tenant, storing the human-readable output
 *   in `/home/<user>/.quota` for support tooling.
 * - Logs failures via `Logger`, then continues to the next user so one broken
 *   account does not halt the sweep.
 */
// Update & check user quota information
const LOG_FILE     = '/var/log/pmss/updateQuotas.log';
const FALLBACK_LOG = '/tmp/updateQuotas.log';

require_once '/scripts/lib/logger.php';
$logger = new Logger(__FILE__);

$logger->msg('Updating quota information');
// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$changedConfig = array();

foreach($users AS $thisUser) {
#TODO(user-logs): log quota refresh success/failure to /var/log/pmss/user-<username>.log for support traceability
#TODO Check that quota is working
    $command = "rm -rf /home/{$thisUser}/.quota; quota -u {$thisUser} -s >> /home/{$thisUser}/.quota; chmod o+r /home/{$thisUser}/.quota";
    // Capture the exit status so we can log quota retrieval failures
    $ret = 0;
    system($command, $ret);
    if ($ret !== 0) {
        $logger->msg("quota command failed for {$thisUser} (exit {$ret})");
    }
}
