<?php
require_once __DIR__.'/scriptsInc.php';
// Port enforcement is operator-side work; this customer endpoint only toggles daemons.
/** Lightweight frontend toggle for qBittorrent. */

$action = pmssFrontendActionRequest();
pmssQbittorrentFrontendPasswordSync($action);

pmssFrontendToggleAction('../.qbittorrentEnable', static function () { passthru('zsh -c "qbittorrent-nox -d" >> /dev/null 2>&1 &'); }, 'killall -u $(whoami) -9 qbittorrent-nox;', 'killall -u $(whoami) qbittorrent-nox; sleep 3; killall -u $(whoami) -9 qbittorrent-nox');
function pmssQbittorrentFrontendPasswordSync($action) {
    if ($action !== 'start' && $action !== 'restart') return;
    $configPath = __DIR__.'/../.config/qBittorrent/qBittorrent.conf';
    if (!pmssQbittorrentFrontendPasswordRequired($configPath)) return;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['qbittorrentPassword'])) {
        http_response_code(428);
        die('qBittorrent WebUI password sync required');
    }
    if (!pmssQbittorrentFrontendPasswordApply($configPath, (string) $_POST['qbittorrentPassword'])) {
        http_response_code(400);
        die('qBittorrent WebUI password sync failed');
    }
}
function pmssQbittorrentFrontendPasswordRequired($configPath) {
    if (!pmssCustomerPathIsSafe($configPath) || !is_file($configPath) || is_link($configPath)) return false;
    $config = @file_get_contents($configPath);
    return is_string($config) && preg_match('/^WebUI\\\\Password_PBKDF2=/m', $config) !== 1;
}
function pmssQbittorrentFrontendPasswordApply($configPath, $password) {
    if ($password === '' || strpos($password, "\0") !== false || !pmssCustomerPathIsSafe($configPath) || !is_file($configPath) || is_link($configPath)) return false;
    $config = @file_get_contents($configPath);
    if (!is_string($config)) return false;
    $updated = pmssQbittorrentFrontendPreferenceSet($config, 'WebUI\\Password_PBKDF2', pmssQbittorrentFrontendPasswordHash($password));
    if ($updated === $config) return true;
    $tmp = @tempnam(dirname($configPath), '.qbt-pw-');
    if ($tmp === false || @file_put_contents($tmp, $updated) === false) {
        if (is_string($tmp)) @unlink($tmp);
        return false;
    }
    $mode = @fileperms($configPath);
    @chmod($tmp, is_int($mode) ? ($mode & 0777) : 0600);
    if (!pmssCustomerPathIsSafe($configPath) || !@rename($tmp, $configPath)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
function pmssQbittorrentFrontendPasswordHash($password) {
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha512', (string) $password, $salt, 100000, 64, true);
    return '@ByteArray(' . base64_encode($salt) . ':' . base64_encode($hash) . ')';
}
function pmssQbittorrentFrontendPreferenceSet($config, $key, $value) {
    $lines = preg_split('/\n/', str_replace(array("\r\n", "\r"), "\n", (string) $config));
    if (!is_array($lines)) return (string) $config;
    $section = null;
    $end = count($lines);
    for ($i = 0; $i < count($lines); $i++) {
        if ($lines[$i] === '[Preferences]') { $section = $i; continue; }
        if ($section !== null && preg_match('/^\[[^\]]+\]$/', $lines[$i]) === 1) { $end = $i; break; }
    }
    if ($section === null) {
        $lines[] = '[Preferences]';
        $lines[] = $key.'='.$value;
        return implode("\n", $lines);
    }
    for ($i = $section + 1; $i < $end; $i++) {
        if (strpos($lines[$i], $key.'=') === 0) {
            $lines[$i] = $key.'='.$value;
            return implode("\n", $lines);
        }
    }
    array_splice($lines, $end, 0, array($key.'='.$value));
    return implode("\n", $lines);
}
