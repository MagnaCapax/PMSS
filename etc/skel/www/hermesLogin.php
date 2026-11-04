<?php
/** Panel-authenticated handoff; never resolve URLs against a document URL containing Basic user info. */
$account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
$name = is_array($account) ? (string) ($account['name'] ?? '') : '';
if (!preg_match('/^[a-z][a-z0-9]{0,7}$/D', $name)) { http_response_code(403); return; }
$base = '/user-'.$name.'/hermes/';
$script = <<<'JS'
(function () {
    var base = document.body.dataset.base;
    var dashboard = new URL(base, location.origin);
    var login = new URL(base + 'login', location.origin);
    var exchange = new URL(base + '__pmss-session', location.origin);
    var match = /^#token=([0-9a-f]{64})$/.exec(location.hash);
    history.replaceState(null, '', location.pathname);
    if (!match) { location.replace(login.href); return; }
    fetch(exchange.href, {method:'POST', credentials:'same-origin', cache:'no-store', body:match[1]})
        .then(function (response) { location.replace(response.status === 204 ? dashboard.href : login.href); })
        .catch(function () { location.replace(login.href); });
}());
JS;
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'sha256-".base64_encode(hash('sha256', $script, true))
    ."'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Opening Hermes</title><body data-base="'
    .htmlspecialchars($base, ENT_QUOTES, 'UTF-8').'"><p>Opening Hermes…</p><script>'.$script.'</script></body></html>';
