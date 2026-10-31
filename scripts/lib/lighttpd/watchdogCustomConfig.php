<?php
/** Reversible recovery of one repeatedly unparsable customer fragment. @license GPL-3.0-only */

require_once __DIR__.'/userFileWrite.php';
require_once __DIR__.'/../user/watchdog.php';

/** Accept only one exact custom.d fragment named by a failed syntax check. */
function pmssLighttpdWatchdogCustomConfigFailure(string $home, array $result): ?string
{
    if (($result['rc'] ?? 0) === 0 || !empty($result['timed_out'])) return null;
    $output = (string) ($result['stderr'] ?? '')."\n".(string) ($result['stdout'] ?? '');
    preg_match_all('/\bsource: ([^\r\n]+) line: [0-9]+ pos: [0-9]+ parser failed[^\r\n]*/', $output, $matches);
    $paths = [];
    $directory = rtrim($home, '/').'/.lighttpd/custom.d';
    foreach ($matches[1] as $path) {
        if (dirname($path) === $directory
            && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*\.conf$/D', basename($path)) === 1) {
            $paths[$path] = true;
        }
    }
    return count($paths) === 1 ? (string) key($paths) : null;
}

/**
 * After an unsuccessful start, require three matching syntax failures before
 * setting aside one file. True requests one ordinary start retry by the caller.
 * Live processes, environmental failures and changed contents reset the count.
 */
function pmssLighttpdWatchdogCustomConfigRecover(string $username, string $home, string $configPath, bool $running): bool
{
    // Reuse the watchdog's private runtime ownership and permission checks.
    $runtimePath = pmssUserWatchdogBackoffPath($username, 'lighttpd');
    if ($runtimePath === null) return false;
    $statePath = dirname($runtimePath).'/checkLighttpdInstances-config-'.$username.'.json';
    if (!pmssPathTargetIsSafe($statePath, false, true)) return false;
    if ($running || pmssUserWatchdogProcessRunning($username, 'lighttpd')
        || pmssUserWatchdogBackoffActive($username, 'lighttpd')) {
        @unlink($statePath);
        return false;
    }

    $account = pmssAccountPathIdentity($username, $home);
    $lighttpd = pmssCommandPath('lighttpd');
    if (!is_array($account) || !isset($account['uid']) || $lighttpd === ''
        || !pmssPathTargetIsSafe($home, true) || !is_dir($home)
        || @fileowner($home) !== $account['uid'] || $configPath !== $home.'/.lighttpd.conf') {
        @unlink($statePath);
        return false;
    }
    // A fresh account-UID parse attributes the failed start; no root parsing or
    // generic launch error may authorize moving customer configuration.
    $command = 'cd '.escapeshellarg($home).' && '.pmssBuildCommand($lighttpd, ['-t', '-f', $configPath]);
    $result = pmssUserWatchdogCommandRun(pmssBuildUserServiceShellCommand($username, $command), 30, $username, 'lighttpd');
    $source = pmssLighttpdWatchdogCustomConfigFailure($home, $result);
    $content = $source !== null && pmssPathTargetIsSafe($source, false, true)
        ? pmssReadRegularFileContentsVerified($source, $account['uid'], 1048576, $metadata) : null;
    if ($content === null) {
        @unlink($statePath);
        return false;
    }

    $fingerprint = hash('sha256', $source."\0".$metadata['dev'].':'.$metadata['ino']."\0".$content);
    $old = pmssJsonFileReadAssoc($statePath, true);
    $count = ($old['fingerprint'] ?? null) === $fingerprint && is_int($old['count'] ?? null)
        ? min(3, max(0, $old['count']) + 1) : 1;
    // Persistence failure never authorizes a rename, even at the threshold.
    if (!pmssAtomicJsonFileWrite($statePath, ['fingerprint' => $fingerprint, 'count' => $count], 0600)
        || $count < 3) return false;

    $destination = $source.'.disabled-'.date('Ymd_His').'-'.getmypid();
    $src = escapeshellarg($source);
    $dst = escapeshellarg($destination);
    // Run as the account, recheck the inode/content, and preserve any prior backup.
    $command = 'test -f '.$src.' && test ! -L '.$src
        .' && test "$(stat -c %d:%i -- '.$src.')" = '.escapeshellarg($metadata['dev'].':'.$metadata['ino'])
        .' && test "$(sha256sum < '.$src.')" = '.escapeshellarg(hash('sha256', $content).'  -')
        .' && test ! -e '.$dst.' && test ! -L '.$dst
        .' && mv -nT -- '.$src.' '.$dst.' && test ! -e '.$src.' && test ! -L '.$src;
    $moved = pmssAccountPathRun($username, $home, [$source, $destination], $command, null,
        pmssUserWatchdogTimeoutSeconds(15), $timedOut);
    if ($timedOut) pmssUserWatchdogBackoffRecord($username, 'lighttpd');
    if (!$moved) {
        pmssUserLog($username, 'lighttpd custom config recovery refused or failed: '.$source);
        return false;
    }
    @unlink($statePath);
    pmssUserLog($username, 'lighttpd custom config set aside after 3 consecutive syntax failures: '.$source
        .' -> '.$destination.'; repair the fragment and rename it back to restore its routes');
    return true;
}
