<?php
/**
 * Customer-side htpasswd-backed panel session authentication.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/panelSessionStore.php';

function pmssPanelSessionHtpasswdHashRead(array $paths, string $username): ?string
{
    $path = (string) ($paths['htpasswd'] ?? '');
    if ($path === '' || $username === '' || !is_file($path) || is_link($path)) {
        return null;
    }

    foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $parts = explode(':', (string) $line, 2);
        if (count($parts) === 2 && $parts[0] === $username) {
            return $parts[1];
        }
    }

    return null;
}

function pmssPanelSessionPasswordMatchesHash(string $password, string $hash): bool
{
    if ($hash === '' || strpbrk($hash, "\r\n\0") !== false) {
        return false;
    }

    $computed = crypt($password, $hash);
    return is_string($computed) && hash_equals($hash, $computed);
}

function pmssPanelSessionPasswordValid(array $paths, string $username, string $password): bool
{
    $hash = pmssPanelSessionHtpasswdHashRead($paths, $username);
    return $hash !== null && pmssPanelSessionPasswordMatchesHash($password, $hash);
}

function pmssPanelSessionReturnUrl(string $username, $value): string
{
    $default = '/user-'.$username.'/';
    $value = is_scalar($value) ? (string) $value : '';
    if ($value === '' || strpbrk($value, "\r\n\0\\") !== false || strpos($value, '//') === 0) {
        return $default;
    }
    if ($value === '/user-'.$username) {
        return $default;
    }

    return strpos($value, $default) === 0 ? $value : $default;
}

function pmssPanelSessionCookieHeader(string $username, string $sessionId, int $now): string
{
    return PMSS_PANEL_SESSION_COOKIE.'='.$sessionId
        .'; Max-Age='.PMSS_PANEL_SESSION_ABSOLUTE_LIFETIME
        .'; Expires='.gmdate('D, d M Y H:i:s \G\M\T', $now + PMSS_PANEL_SESSION_ABSOLUTE_LIFETIME)
        .'; Path=/user-'.$username.'/; Secure; HttpOnly; SameSite=Lax';
}

function pmssPanelSessionCookieDeleteHeader(string $username): string
{
    return PMSS_PANEL_SESSION_COOKIE.'=deleted; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
        .'; Path=/user-'.$username.'/; Secure; HttpOnly; SameSite=Lax';
}

function pmssPanelSessionLoginAttempt(array $request, array $paths, string $username, ?int $now = null): array
{
    $now = $now ?? time();
    $session = pmssPanelSessionRead($paths);
    $returnUrl = pmssPanelSessionReturnUrl($username, $request['return'] ?? '');
    $csrf = isset($request['csrf']) ? (string) $request['csrf'] : '';
    $password = isset($request['password']) ? (string) $request['password'] : '';

    if (!pmssPanelSessionCsrfValid($session, $csrf)) {
        $failed = pmssPanelSessionFailedIncrement($paths, $now);
        pmssPanelSessionLog($paths, 'login status=fail reason=csrf failed='.$failed);
        return ['ok' => false, 'status' => 403, 'headers' => [], 'message' => 'Invalid request token', 'failed' => $failed];
    }
    if (!pmssPanelSessionPasswordValid($paths, $username, $password)) {
        $failed = pmssPanelSessionFailedIncrement($paths, $now);
        pmssPanelSessionLog($paths, 'login status=fail reason=password failed='.$failed);
        return ['ok' => false, 'status' => 401, 'headers' => [], 'message' => 'Invalid password', 'failed' => $failed];
    }

    $sessionId = pmssPanelSessionToken();
    $newSession = [
        'id' => $sessionId,
        'csrf' => pmssPanelSessionToken(),
        'created' => $now,
        'seen' => $now,
        'failed' => 0,
    ];
    if (!pmssPanelSessionWrite($paths, $newSession)) {
        pmssPanelSessionLog($paths, 'login status=fail reason=session-write');
        return ['ok' => false, 'status' => 500, 'headers' => [], 'message' => 'Unable to create session', 'failed' => 0];
    }

    pmssPanelSessionLog($paths, 'login status=ok auth=cookie');
    return [
        'ok' => true,
        'status' => 302,
        'sessionId' => $sessionId,
        'oldSessionId' => (string) ($session['id'] ?? ''),
        'headers' => [
            'Set-Cookie' => pmssPanelSessionCookieHeader($username, $sessionId, $now),
            'Location' => $returnUrl,
        ],
        'message' => '',
        'failed' => 0,
    ];
}
