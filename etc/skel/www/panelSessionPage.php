<?php
/**
 * Customer-side panel session login/logout page handlers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/panelSessionAuth.php';

function pmssPanelSessionHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pmssPanelSessionRenderLoginForm(string $username, string $csrf, string $returnUrl, string $message = ''): string
{
    $action = '/user-'.$username.'/panelSessionLogin.php';
    $messageHtml = $message === '' ? '' : '<p class="error">'.pmssPanelSessionHtml($message).'</p>';

    return '<!doctype html><html><head><script>var u = new URL(location.href); if (u.username || u.password) { u.username = \'\'; u.password = \'\'; location.replace(u.href); }</script><meta charset="utf-8"><title>Panel login</title>'
        .'<meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<style>body{font:16px/1.4 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;background:#f6f7f8;color:#111827}'
        .'form{width:min(24rem,calc(100vw - 2rem));background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:1.25rem}'
        .'label,input,button{display:block;width:100%;box-sizing:border-box}label{font-weight:600;margin-bottom:.35rem}'
        .'input{padding:.65rem;border:1px solid #9ca3af;border-radius:4px}button{margin-top:1rem;padding:.7rem;border:0;border-radius:4px;background:#14532d;color:#fff;font-weight:700}'
        .'.error{color:#991b1b;margin:.75rem 0 0}</style></head><body><form method="post" action="'.pmssPanelSessionHtml($action).'">'
        .'<h1>'.pmssPanelSessionHtml($username).' panel</h1>'.$messageHtml
        .'<input type="hidden" name="csrf" value="'.pmssPanelSessionHtml($csrf).'">'
        .'<input type="hidden" name="return" value="'.pmssPanelSessionHtml($returnUrl).'">'
        .'<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" autofocus>'
        .'<button type="submit">Sign in</button></form></body></html>';
}

function pmssPanelSessionEmitHeaders(array $headers, int $status): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    foreach ($headers as $name => $value) {
        header($name.': '.$value, false);
    }
}

function pmssPanelSessionLoginMain(): int
{
    $paths = pmssPanelSessionPaths();
    $username = pmssPanelSessionUsername($paths['home']);
    if ($username === '') {
        http_response_code(500);
        echo 'Invalid panel user';
        return 1;
    }

    $now = time();
    $returnUrl = pmssPanelSessionReturnUrl($username, $_REQUEST['return'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $result = pmssPanelSessionLoginAttempt($_POST, $paths, $username, $now);
        pmssPanelSessionEmitHeaders((array) $result['headers'], (int) $result['status']);
        if (!empty($result['ok'])) return 0;

        $csrf = pmssPanelSessionCsrfEnsure($paths, $now);
        echo pmssPanelSessionRenderLoginForm($username, $csrf, $returnUrl, (string) $result['message']);
        return 1;
    }

    $csrf = pmssPanelSessionCsrfEnsure($paths, $now);
    pmssPanelSessionEmitHeaders([], 200);
    echo pmssPanelSessionRenderLoginForm($username, $csrf, $returnUrl);
    return 0;
}

function pmssPanelSessionRenderLogoutForm(string $username, string $csrf): string
{
    $action = '/user-'.$username.'/panelSessionLogout.php';

    return '<!doctype html><html><head><meta charset="utf-8"><title>Sign out</title>'
        .'<meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<style>body{font:16px/1.4 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;background:#f6f7f8;color:#111827}'
        .'form{width:min(24rem,calc(100vw - 2rem));background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:1.25rem}'
        .'button{display:block;width:100%;margin-top:1rem;padding:.7rem;border:0;border-radius:4px;background:#14532d;color:#fff;font-weight:700}</style>'
        .'</head><body><form method="post" action="'.pmssPanelSessionHtml($action).'">'
        .'<h1>'.pmssPanelSessionHtml($username).' panel</h1>'
        .'<input type="hidden" name="csrf" value="'.pmssPanelSessionHtml($csrf).'">'
        .'<button type="submit">Sign out</button></form></body></html>';
}

/** Stop the account's agent web UIs and rotate their sessions; runs only after a verified sign-out. */
function pmssPanelSessionLogoutStopAgents(array $paths): void
{
    $hermes = $paths['home'].'/bin/install-hermes';
    if (is_file($hermes) && !is_link($hermes) && is_executable($hermes))
        exec(escapeshellarg($hermes).' rotate-session >/dev/null 2>&1');
    $pikkumiesHermes = $paths['home'].'/bin/pikkumiesHermesUi.php';
    if (is_file($pikkumiesHermes) && !is_link($pikkumiesHermes)) {
        exec('HOME='.escapeshellarg($paths['home']).' /usr/bin/php '.escapeshellarg($pikkumiesHermes).' stop-simplified >/dev/null 2>&1');
        exec('HOME='.escapeshellarg($paths['home']).' /usr/bin/php '.escapeshellarg($pikkumiesHermes).' rotate-session >/dev/null 2>&1');
    }
}

function pmssPanelSessionLogoutMain(): int
{
    $paths = pmssPanelSessionPaths();
    $username = pmssPanelSessionUsername($paths['home']);
    if ($username === '') {
        http_response_code(500);
        echo 'Invalid panel user';
        return 1;
    }

    // Logout acts only for the session owner (live cookie) on a POST that carries the session's CSRF token.
    $result = pmssPanelSessionLogoutAttempt((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_COOKIE, $_POST, $paths, $username, time());
    pmssPanelSessionEmitHeaders((array) $result['headers'], (int) $result['status']);
    if (!empty($result['done'])) {
        pmssPanelSessionLogoutStopAgents($paths);
        return 0;
    }
    if ((int) $result['status'] === 200) {
        echo pmssPanelSessionRenderLogoutForm($username, (string) $result['csrf']);
    } elseif ((int) $result['status'] === 403) {
        echo 'Invalid request token';
        return 1;
    }
    return 0;
}
