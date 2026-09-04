<?php
/**
 * Customer-side flat-file panel session storage.
 *
 * @license GPL-3.0-only
 */

const PMSS_PANEL_SESSION_COOKIE = 'pmss_panel_session';
const PMSS_PANEL_SESSION_FILE = 'panel-session';
const PMSS_PANEL_SESSION_ABSOLUTE_LIFETIME = 28800;
const PMSS_PANEL_SESSION_IDLE_TIMEOUT = 1800;

function pmssPanelSessionPaths(?string $homeDir = null): array
{
    $homeDir = rtrim($homeDir ?: dirname(__DIR__), '/');
    $lighttpdDir = $homeDir.'/.lighttpd';

    return ['home' => $homeDir, 'dir' => $lighttpdDir, 'session' => $lighttpdDir.'/'.PMSS_PANEL_SESSION_FILE, 'htpasswd' => $lighttpdDir.'/.htpasswd', 'log' => $lighttpdDir.'/error.log'];
}

function pmssPanelSessionUsername(?string $homeDir = null): string
{
    $user = basename(rtrim($homeDir ?: dirname(__DIR__), '/'));
    return preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) === 1 ? $user : '';
}

function pmssPanelSessionToken(): string { return bin2hex(random_bytes(32)); }

function pmssPanelSessionHexOrEmpty($value): string { $value = is_scalar($value) ? (string) $value : ''; return preg_match('/^[0-9a-f]{64}$/D', $value) === 1 ? $value : ''; }

function pmssPanelSessionIntOrZero($value): int { return is_numeric($value) && (int) $value >= 0 ? (int) $value : 0; }

function pmssPanelSessionParse(string $content): array
{
    $session = [];
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^([a-z]+)=([A-Za-z0-9]*)$/D', rtrim($line, "\r"), $matches) === 1) {
            $session[$matches[1]] = in_array($matches[1], ['created', 'seen', 'failed'], true)
                ? pmssPanelSessionIntOrZero($matches[2])
                : $matches[2];
        }
    }

    return $session;
}

function pmssPanelSessionSerialize(array $session): string
{
    $fields = [
        'id' => pmssPanelSessionHexOrEmpty($session['id'] ?? ''),
        'csrf' => pmssPanelSessionHexOrEmpty($session['csrf'] ?? ''),
        'created' => (string) pmssPanelSessionIntOrZero($session['created'] ?? 0),
        'seen' => (string) pmssPanelSessionIntOrZero($session['seen'] ?? 0),
        'failed' => (string) pmssPanelSessionIntOrZero($session['failed'] ?? 0),
    ];

    $content = '';
    foreach ($fields as $key => $value) {
        $content .= $key.'='.$value."\n";
    }
    return $content;
}

function pmssPanelSessionRead(array $paths): array
{
    $path = (string) ($paths['session'] ?? '');
    if ($path === '' || !is_file($path) || is_link($path) || filesize($path) > 4096) {
        return [];
    }

    $content = @file_get_contents($path);
    return is_string($content) ? pmssPanelSessionParse($content) : [];
}

function pmssPanelSessionWrite(array $paths, array $session): bool
{
    $dir = (string) ($paths['dir'] ?? '');
    $path = (string) ($paths['session'] ?? '');
    if ($dir === '' || $path === '' || is_link($path) || (file_exists($path) && !is_file($path))) {
        return false;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0751, true)) {
        return false;
    }

    $tmp = @tempnam($dir, 'panel-session-');
    if (!is_string($tmp) || $tmp === '' || is_link($tmp)) {
        return false;
    }
    @chmod($tmp, 0600);
    if (@file_put_contents($tmp, pmssPanelSessionSerialize($session), LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    @chmod($path, 0600);
    return is_file($path) && !is_link($path);
}

function pmssPanelSessionDelete(array $paths): void { $path = (string) ($paths['session'] ?? ''); if ($path !== '' && is_file($path) && !is_link($path)) @unlink($path); }

function pmssPanelSessionLog(array $paths, string $message): void
{
    $path = (string) ($paths['log'] ?? '');
    if ($path !== '' && strpbrk($message, "\r\n\0") === false) {
        @error_log(date('c').' pmss panelSession '.$message."\n", 3, $path);
    }
}

function pmssPanelSessionCsrfEnsure(array $paths, ?int $now = null): string
{
    $now = $now ?? time();
    $session = pmssPanelSessionRead($paths);
    $csrf = pmssPanelSessionHexOrEmpty($session['csrf'] ?? '');
    if ($csrf !== '') return $csrf;

    $session['csrf'] = pmssPanelSessionToken();
    $session['created'] = $now;
    $session['seen'] = $now;
    pmssPanelSessionWrite($paths, $session);
    return (string) $session['csrf'];
}

function pmssPanelSessionCsrfValid(array $session, string $token): bool { $csrf = pmssPanelSessionHexOrEmpty($session['csrf'] ?? ''); return $csrf !== '' && hash_equals($csrf, $token); }

function pmssPanelSessionFailedIncrement(array $paths, ?int $now = null): int
{
    $now = $now ?? time();
    $session = pmssPanelSessionRead($paths);
    if (pmssPanelSessionHexOrEmpty($session['csrf'] ?? '') === '') {
        $session['csrf'] = pmssPanelSessionToken();
    }
    $session['created'] = pmssPanelSessionIntOrZero($session['created'] ?? $now) ?: $now;
    $session['seen'] = $now;
    $session['failed'] = min(1000000, pmssPanelSessionIntOrZero($session['failed'] ?? 0) + 1);
    pmssPanelSessionWrite($paths, $session);

    return (int) $session['failed'];
}
