<?php
/** Bounded, root-owned stop state for qBittorrent WebUI reconciliation. */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../runtime/filesystem.php';

/** Rewrite the locked marker completely or report failure. */
function pmssQbittorrentWebUiAuthStateWrite($handle, string $payload): bool
{
    rewind($handle);
    return @fwrite($handle, $payload) === strlen($payload)
        && @ftruncate($handle, strlen($payload))
        && @fflush($handle);
}

/** Check whether a prior pass still has a timestamp or an unfinished apply. */
function pmssQbittorrentWebUiAuthStateActive(string $user, array $ops = []): bool
{
    $dir = $ops['stateDir'] ?? '/run/pmss';
    $path = rtrim($dir, '/').'/qbittorrentWebui-'.$user.'.state';
    if (!pmssPathTargetIsSafe($path, false, true, false)) return false;
    $handle = @fopen($path, 'r');
    if ($handle === false) return false;
    if (!@flock($handle, LOCK_SH)) { fclose($handle); return false; }
    $value = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if (!is_string($value)) return false;
    $parts = explode("\n", trim($value));
    if (!ctype_digit($parts[0] ?? '')) return false;
    $last = (int) $parts[0];
    return $last > 0 || ($parts[1] ?? '') === '1';
}

/** Persist the last reported state in the existing locked marker. */
function pmssQbittorrentWebUiAuthStateShouldLog(string $user, string $status, array $ops = []): bool
{
    $dir = $ops['stateDir'] ?? '/run/pmss';
    $path = rtrim($dir, '/').'/qbittorrentWebui-'.$user.'.state';
    // A missing marker cannot retain a transition; report its own failure.
    if (!pmssPathTargetIsSafe($path, false, true, false)) return $status === 'state-failed';
    $handle = @fopen($path, 'r+');
    if ($handle === false) return $status === 'state-failed';
    if (!@flock($handle, LOCK_EX)) { fclose($handle); return $status === 'state-failed'; }
    $stat = fstat($handle);
    if (!is_array($stat) || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0022) !== 0) {
        flock($handle, LOCK_UN); fclose($handle); return $status === 'state-failed';
    }
    $parts = explode("\n", (string) stream_get_contents($handle));
    $prior = $parts[2] ?? '';
    $shouldLog = $status === 'stop' || $prior !== $status;
    $payload = ($parts[0] ?? '0')."\n".($parts[1] ?? '0')."\n".$status."\n";
    $written = pmssQbittorrentWebUiAuthStateWrite($handle, $payload);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $written && $shouldLog;
}

/** Keep a changed config pending until the daemon has stopped once. */
function pmssQbittorrentWebUiAuthStop(string $user, array $pids, bool $changed, array $ops = [], ?callable $write = null): string
{
    $dir = $ops['stateDir'] ?? '/run/pmss';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return 'state-failed';
    $dirStat = @stat($dir);
    if (!pmssPathTargetIsSafe($dir, true) || !is_array($dirStat)
        || $dirStat['uid'] !== posix_geteuid() || ($dirStat['mode'] & 0022) !== 0) return 'state-failed';
    $path = rtrim($dir, '/').'/qbittorrentWebui-'.$user.'.state';
    // Only a root-owned regular marker can authorize a later stop.
    if (!pmssPathTargetIsSafe($path, false, true, false)) return 'state-failed';
    $handle = @fopen($path, 'c+');
    if ($handle === false) return 'state-failed';
    @chmod($path, 0600);
    if (!@flock($handle, LOCK_EX)) { fclose($handle); return 'state-failed'; }
    $stat = fstat($handle);
    if (!is_array($stat) || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0022) !== 0) {
        flock($handle, LOCK_UN); fclose($handle); return 'state-failed';
    }

    rewind($handle);
    $previous = (string) stream_get_contents($handle);
    $parts = explode("\n", trim($previous));
    $last = ctype_digit($parts[0] ?? '') ? (int) $parts[0] : 0;
    $pending = ($parts[1] ?? '') === '1';
    $logged = $parts[2] ?? '';
    // Pending survives a deferred pass even after the config file converges.
    $now = isset($ops['now']) ? (int) $ops['now'] : time();
    $status = 'idle';
    if ($pids === [] || (!$changed && !$pending)) {
        // A stopped daemon or a verified later pass completes the prior apply.
        $last = 0; $pending = false;
    } elseif ($changed || $pending) {
        $pending = true;
        if ($last > 0 && $now - $last < 86400) {
            $status = 'deferred';
        } else {
            $last = $now;
            $status = 'stop';
        }
    }

    $payload = $last."\n".($pending ? '1' : '0')."\n".$logged."\n";
    if (!pmssQbittorrentWebUiAuthStateWrite($handle, $payload)) {
        $status = 'state-failed';
    }
    // Record the pending state before changing a file held in daemon memory.
    if ($status !== 'state-failed' && $changed && $write !== null && !$write()) {
        pmssQbittorrentWebUiAuthStateWrite($handle, $previous);
        $status = 'write-failed';
    }
    if ($status === 'stop') {
        // Do not ask qBittorrent to save its in-memory config on exit.
        $signal = $ops['signal'] ?? static function (int $pid): bool { return @posix_kill($pid, 9); };
        $sent = true;
        $eligible = 0;
        foreach ($pids as $pid) {
            // Recheck after the config write; a numeric process id may be reused.
            if (!pmssQbittorrentWebUiAuthPidIsNative((int) $pid, $ops)) continue;
            $eligible++;
            $sent = $signal($pid) && $sent;
        }
        if ($eligible === 0) $sent = false;
        if (!$sent) {
            pmssQbittorrentWebUiAuthStateWrite($handle, $previous);
            $status = 'stop-failed';
        } else {
            // A delivered KILL prevents a clean save of the old in-memory map.
            pmssQbittorrentWebUiAuthStateWrite($handle, $last."\n0\n".$logged."\n");
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $status;
}
