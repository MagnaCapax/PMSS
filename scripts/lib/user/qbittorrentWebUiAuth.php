<?php
/** Reconcile qBittorrent WebUI settings before a running daemon can retain old values. */

require_once __DIR__.'/torrentPort.php';
require_once __DIR__.'/watchdog.php';
require_once __DIR__.'/../arrRootGuard.php';
require_once __DIR__.'/qbittorrentWebUiAuthState.php';

/** A fixed PBKDF2-shaped value that asks the panel to collect a new password. */
function pmssQbittorrentWebUiAuthSentinel(): string
{
    return '@ByteArray('.base64_encode(str_repeat("\0", 16)).':'.base64_encode(str_repeat("\0", 64)).')';
}

/** Update every copy of both managed keys in every Preferences section. */
function pmssQbittorrentWebUiAuthForce(string $config): string
{
    list($lines, $trailing, $lineEnding) = pmssQbittorrentConfigSplitLines($config);
    // A line in another section does not supply Preferences with a password.
    $passwordPresent = false;
    $passwordSection = false;
    foreach ($lines as $line) {
        if (preg_match('/^\[[^\]]+\]$/', $line) === 1) $passwordSection = $line === '[Preferences]';
        if ($passwordSection && strpos($line, 'WebUI\\Password_PBKDF2=') === 0) {
            $passwordPresent = true;
            break;
        }
    }
    $managed = [
        'WebUI\\LocalHostAuth' => 'true',
        'WebUI\\AuthSubnetWhitelistEnabled' => 'false',
    ];
    // The sentinel is stable across passes and is never added over an existing value.
    if (!$passwordPresent) $managed['WebUI\\Password_PBKDF2'] = pmssQbittorrentWebUiAuthSentinel();
    $result = [];
    $inPreferences = false;
    $hasPreferences = false;
    $seen = [];
    foreach ($lines as $line) {
        // Finish each section independently, including repeated Preferences sections.
        if (preg_match('/^\[[^\]]+\]$/', $line) === 1) {
            if ($inPreferences) foreach ($managed as $key => $value) if (!isset($seen[$key])) $result[] = $key.'='.$value;
            $inPreferences = $line === '[Preferences]';
            $hasPreferences = $hasPreferences || $inPreferences;
            $seen = [];
        }
        if ($inPreferences) {
            // Replace every copy so a later duplicate cannot restore an old value.
            foreach ($managed as $key => $value) {
                if (strpos($line, $key.'=') !== 0) continue;
                $line = $key.'='.$value;
                $seen[$key] = true;
                break;
            }
        }
        $result[] = $line;
    }
    if ($inPreferences) foreach ($managed as $key => $value) if (!isset($seen[$key])) $result[] = $key.'='.$value;
    if (!$hasPreferences) {
        // The template may be incomplete on older accounts.
        if ($result !== [] && end($result) !== '') $result[] = '';
        $result[] = '[Preferences]';
        foreach ($managed as $key => $value) $result[] = $key.'='.$value;
    }
    return pmssQbittorrentConfigJoinLines($result, $trailing, $lineEnding);
}

/** Confirm the executable and mount context still identify a native daemon. */
function pmssQbittorrentWebUiAuthPidIsNative(int $pid, array $ops = []): bool
{
    $roots = $ops['roots'] ?? pmssRootGuardInstallPrefixes()['qBittorrent'];
    $exeRead = $ops['exe'] ?? static function (int $candidate) { return @readlink('/proc/'.$candidate.'/exe'); };
    $mntNsRead = $ops['mntNs'] ?? static function (int $candidate) {
        return @readlink('/proc/'.($candidate === 0 ? 'self' : $candidate).'/ns/mnt');
    };
    $ownNs = $mntNsRead(0);
    $pidNs = $mntNsRead($pid);
    if (!is_string($ownNs) || $ownNs === '' || $ownNs !== $pidNs) return false;
    $exe = $exeRead($pid);
    if (!is_string($exe) || $exe === '') return false;
    // A replaced binary retains its original path with this proc suffix.
    if (substr($exe, -10) === ' (deleted)') $exe = substr($exe, 0, -10);
    if ($exe === '') return false;
    foreach ($roots as $root) {
        $resolved = @realpath($root);
        if ($resolved !== false && pmssRootGuardPathMatchesRoot($exe, $resolved)) return true;
    }
    return false;
}

/** Match only the account's known native binary locations. */
function pmssQbittorrentWebUiAuthNativePids(string $user, array $ops = []): array
{
    $pids = isset($ops['pids']) ? $ops['pids']($user) : pmssUserWatchdogProcessPids($user, 'qbittorrent-nox', ['exact' => true]);
    $native = [];
    foreach ($pids as $pid) if (pmssQbittorrentWebUiAuthPidIsNative((int) $pid, $ops)) $native[] = (int) $pid;
    return $native;
}

/** Return true only when this pass issued a bounded native-daemon stop. */
function pmssQbittorrentWebUiAuthEnsure(string $user, string $home, array $ops = []): bool
{
    $enabled = $ops['enabled'] ?? static function (): bool { return is_file('/etc/seedbox/config/qbittorrentWebuiEnforce.enabled'); };
    if (!$enabled()) return false;
    $contextSafe = $ops['contextSafe'] ?? 'pmssTorrentPortUserHomeContextIsSafe';
    $pathSafe = $ops['pathSafe'] ?? 'pmssUserFilePathIsSafe';
    $configPath = rtrim($home, '/').'/.config/qBittorrent/qBittorrent.conf';
    // Use the writer's path predicate before any read to avoid unrepairable paths.
    if (!$contextSafe($user, $home) || !$pathSafe($configPath)) return false;
    $read = $ops['read'] ?? 'pmssReadRegularFileContents';
    $config = $read($configPath);
    if (!is_string($config)) return false;
    $forced = pmssQbittorrentWebUiAuthForce($config);
    $changed = $forced !== $config;
    if (!$changed && !pmssQbittorrentWebUiAuthStateActive($user, $ops)) return false;
    $write = static function () use ($user, $configPath, $ops): bool {
        $writer = $ops['write'] ?? static function (string $name, string $path): bool {
            return pmssQbittorrentConfigMutate($name, 'pmssQbittorrentWebUiAuthForce', $path);
        };
        return $writer($user, $configPath);
    };
    $pids = pmssQbittorrentWebUiAuthNativePids($user, $ops);
    // With no native process, the next start reads the written file directly.
    if ($pids === []) {
        if ($changed && !$write()) {
            pmssQbittorrentWebUiAuthLog($user, 'qBittorrent WebUI settings write failed', $ops);
            return false;
        }
        pmssQbittorrentWebUiAuthStop($user, [], false, $ops);
        return false;
    }
    $status = pmssQbittorrentWebUiAuthStop($user, $pids, $changed, $ops, $write);
    // The state helper holds its lock across the file write and signal.
    if ($status === 'idle') {
        pmssQbittorrentWebUiAuthStateShouldLog($user, 'idle', $ops);
        return false;
    }
    $message = [
        'stop' => 'qBittorrent stopped to apply WebUI settings',
        'deferred' => 'qBittorrent WebUI settings apply deferred',
        'state-failed' => 'qBittorrent WebUI settings state unavailable',
        'stop-failed' => 'qBittorrent WebUI settings stop failed',
        'write-failed' => 'qBittorrent WebUI settings write failed',
    ][$status] ?? 'qBittorrent WebUI settings apply deferred';
    if (pmssQbittorrentWebUiAuthStateShouldLog($user, $status, $ops)) {
        pmssQbittorrentWebUiAuthLog($user, $message, $ops);
    }
    return $status === 'stop';
}

/** Log one transition to the account and cron streams. */
function pmssQbittorrentWebUiAuthLog(string $user, string $message, array $ops = []): void
{
    if (isset($ops['log'])) { $ops['log']($user, $message); return; }
    pmssUserWatchdogLogTransition($user, $message, ['event' => 'qbittorrent_webui_reconcile', 'user' => $user]);
}
