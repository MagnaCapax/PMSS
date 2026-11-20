<?php
/** php-cgi binary drift: an account pool still running a replaced PHP binary. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime/processExe.php';
require_once dirname(__DIR__).'/user/identity.php';

/** Return the per-user marker path recording the binary a drift restart was made for. */
function pmssLighttpdWatchdogPhpCgiDriftStatePath(string $username, string $runtimeDir = ''): string
{
    if (!pmssValidateUsername($username)) {
        return '';
    }
    $runtimeDir = $runtimeDir === '' ? pmssRuntimeDir() : rtrim($runtimeDir, '/');
    if ($runtimeDir === '' || !pmssPathAbsoluteStringIsSafe($runtimeDir) || !pmssPathTargetIsSafe($runtimeDir, true)) {
        return '';
    }
    $statePath = $runtimeDir.'/checkLighttpdInstances-phpcgi-'.$username.'.mtime';
    return pmssPathTargetIsSafe($statePath, false) ? $statePath : '';
}

/**
 * Decide whether the account's php-cgi pool runs a binary that a package upgrade replaced.
 *
 * Covers every upgrade route (update, unattended or manual apt), as ADR 0286 does for
 * rootless Docker. At most one restart per account per installed binary (its mtime):
 * a restart that does not heal is left to the existing socket and liveness paths,
 * and a marker that cannot be written never authorizes a restart.
 *
 * @param array{binary?:string,runtimeDir?:string,pids?:callable,isDeleted?:callable,uid?:int} $options
 */
function pmssLighttpdWatchdogPhpCgiBinaryReplaced(string $username, array $options = array()): bool
{
    $binary = (string) ($options['binary'] ?? '/usr/bin/php-cgi');
    $target = @realpath($binary);
    clearstatcache(true, is_string($target) ? $target : $binary);
    $binaryMtime = is_string($target) ? @filemtime($target) : false;
    $account = isset($options['uid']) ? array('uid' => (int) $options['uid']) : pmssUserAccountLookup($username);
    if (!is_int($binaryMtime) || $binaryMtime < 1 || !is_array($account)) {
        return false;
    }

    $pids = isset($options['pids'])
        ? ($options['pids'])($username)
        : pmssUserWatchdogProcessPids($username, 'php-cgi', array('exact' => true));
    $isDeleted = $options['isDeleted'] ?? 'pmssProcessExeDeleted';
    $drift = false;
    foreach ($pids as $pid) {
        // Children fork from the pool parent and share its executable; first hit decides.
        if ($isDeleted((int) $pid, (int) $account['uid'], '/usr/bin/php-cgi')) {
            $drift = true;
            break;
        }
    }
    if (!$drift) {
        return false;
    }

    $statePath = pmssLighttpdWatchdogPhpCgiDriftStatePath($username, (string) ($options['runtimeDir'] ?? ''));
    if ($statePath === '' || !pmssDirEnsureExists(dirname($statePath), 0755)
        || pmssReadRegularFileInt($statePath) === $binaryMtime) {
        return false;
    }
    // Record before restarting so a restart that does not heal cannot loop every pass.
    return pmssFileWriteComplete($statePath, (string) $binaryMtime, LOCK_EX);
}
