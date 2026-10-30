<?php
/** Environmental gates for automatic rTorrent session resets. @license GPL-3.0-only */

require_once __DIR__.'/../lighttpd/accountPath.php';

/**
 * Refuse to blame session data when account startup prerequisites are unavailable.
 * This is a preflight, not proof of corruption; the caller retains its failure threshold.
 */
function pmssCheckRtorrentSessionResetEnvironmentReady(string $user, string $home, bool $debug): bool
{
    $home = rtrim($home, '/');
    if (!rtorrentProcessUserTargetPathIsSafe($home, $user, 'session', true, true)) {
        pmssCheckRtorrentLogBoth($user, 'session reset deferred: unsafe session path', $debug);
        return false;
    }

    $directories = [$home];
    if (is_dir($home.'/session')) $directories[] = $home.'/session';
    foreach ($directories as $directory) {
        $free = @disk_free_space($directory);
        if ($free === false || !is_finite($free) || $free <= 0) {
            pmssCheckRtorrentLogBoth($user, 'session reset deferred: free space exhausted or unavailable', $debug);
            return false;
        }
    }

    // Root's access checks miss account permissions and reserved blocks. Run the
    // same bounded account operation used by managed file writers and the launcher.
    $command = 'test -r '.escapeshellarg($home.'/.rtorrent.rc')
        .' && test -x /usr/local/bin/rtorrent && command -v screen >/dev/null';
    if (file_exists('/etc/seedbox/config/localnet') || is_link('/etc/seedbox/config/localnet')) {
        $command .= ' && test -r /etc/seedbox/config/localnet && test -s /etc/seedbox/config/localnet';
    }
    if (file_exists($home.'/.rtorrentExecute.php') || is_link($home.'/.rtorrentExecute.php')) {
        $command .= ' && test -x '.escapeshellarg($home.'/.rtorrentExecute.php')
            .' && test -r '.escapeshellarg($home.'/.scriptsInc.php').' && command -v php >/dev/null';
    }

    // Creating and writing one private temporary file also detects quota, inode
    // exhaustion and read-only mounts. Never truncate a session or account file.
    $command .= ' || exit 1; for directory in '.implode(' ', array_map('escapeshellarg', $directories)).'; do ';
    $command .= <<<'SH'
probe=$(mktemp -- "$directory/.pmss-session-reset.XXXXXX") || exit 1
trap 'rm -f -- "$probe"' EXIT
trap 'exit 1' HUP INT TERM
printf x > "$probe" || exit 1
rm -f -- "$probe" || exit 1
trap - EXIT HUP INT TERM
done
SH;
    $timedOut = false;
    if (!pmssAccountPathRun($user, $home, $directories, $command, null,
        pmssUserWatchdogTimeoutSeconds(10), $timedOut)) {
        if ($timedOut) pmssUserWatchdogBackoffRecord($user, 'rtorrent');
        pmssCheckRtorrentLogBoth($user, 'session reset deferred: account startup or write preflight failed', $debug);
        return false;
    }

    return true;
}
