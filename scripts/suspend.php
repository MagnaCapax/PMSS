#!/usr/bin/php
<?php
/**
 * Disable a user account and present a friendly suspended landing page.
 *
 * Locks the Unix account, sets an immediate expiry, and swaps the web root
 * to a suspended notice while preserving the original content under
 * /home/<user>/www-disabled.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */
require_once __DIR__.'/lib/userLifecycle.php';

$usage = 'suspend.php USERNAME';
$username = $argv[1] ?? '';
if ($username === '') {
    die($usage."\n");
}

if (!pmssValidateUsername($username)) {
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'suspend',
            'validate',
            $username,
            array(
                'status'  => 'ERR',
                'message' => 'Rejected username due to validation failure',
            )
        )
    );
    die("Invalid username: {$username}\n");
}

$homeDir = "/home/{$username}";
$activeRoot = "$homeDir/www";
$disabledRoot = "$homeDir/www-disabled";

if (!is_dir($homeDir)) {
    die("User home {$homeDir} missing\n");
}

if (is_dir($disabledRoot)) {
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'suspend',
            'already_suspended',
            $username,
            array(
                'status'  => 'SKIP',
                'message' => 'User already suspended',
            )
        )
    );
    die("User already suspended\n");
}

pmssUserWriteLogs(
    pmssUserBaseContext(
        'suspend',
        'start',
        $username,
        array(
            'status'   => 'INFO',
            'home_dir' => $homeDir,
        )
    )
);

pmssUserLifecycleStep('suspend', $username, 'lock_account', 'usermod -L '.escapeshellarg($username), false);
pmssUserLifecycleStep('suspend', $username, 'set_expiry', 'usermod --expiredate 1 '.escapeshellarg($username), false);
pmssUserLifecycleStep('suspend', $username, 'list_processes', 'ps aux|grep '.escapeshellarg($username), false);
pmssUserLifecycleStep('suspend', $username, 'kill_processes', 'killall -9 -u '.escapeshellarg($username), false);

if (is_dir($activeRoot)) {
    if (!@rename($activeRoot, $disabledRoot)) {
        echo "Warning: failed to archive {$activeRoot}, attempting to continue\n";
    }
}

pmssCreateSuspendedLanding($homeDir, $username);

/**
 * Generate the suspended landing page and marker files.
 */
function pmssCreateSuspendedLanding(string $homeDir, string $username): void
{
    $suspendRoot = $homeDir.'/www';
    $publicDir = $suspendRoot.'/public';
    $marker = $suspendRoot.'/.pmss-suspended';

    if (!is_dir($suspendRoot) && !@mkdir($suspendRoot, 0755, true)) {
        echo "Failed to create {$suspendRoot}\n";
        return;
    }
    if (!is_dir($publicDir)) {
        @mkdir($publicDir, 0755, true);
    }

    $html = pmssRenderSuspendedHtml($username);
    @file_put_contents($suspendRoot.'/index.html', $html);
    @file_put_contents($publicDir.'/index.html', $html);
    @file_put_contents($marker, (string)time());

    @chown($suspendRoot, $username);
    @chgrp($suspendRoot, $username);
    @chown($publicDir, $username);
    @chgrp($publicDir, $username);
    @chown($suspendRoot.'/index.html', $username);
    @chgrp($suspendRoot.'/index.html', $username);
    @chown($publicDir.'/index.html', $username);
    @chgrp($publicDir.'/index.html', $username);
    @chown($marker, $username);
    @chgrp($marker, $username);
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'suspend',
            'end',
            $username,
            array(
                'status'   => 'OK',
                'home_dir' => $homeDir,
            )
        )
    );
}

/**
 * Build landing HTML using template or fallback markup.
 */
function pmssRenderSuspendedHtml(string $username): string
{
    $templatePath = '/etc/seedbox/config/template.suspended.notice.html';
    $template = @file_get_contents($templatePath);
    if ($template === false || trim($template) === '') {
        return pmssSuspendedFallbackHtml($username);
    }

    $replacements = [
        '##USERNAME##' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        '##SUPPORT_URL##' => 'https://pulsedmedia.com/contact/',
    ];
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * Minimal inline fallback when template is unavailable.
 */
function pmssSuspendedFallbackHtml(string $username): string
{
    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Account Suspended</title>
  <style>
    body { font-family: sans-serif; background: #111; color: #f4f4f4; text-align: center; padding: 3rem; }
    .card { max-width: 540px; margin: 0 auto; background: #1e1e1e; padding: 2.5rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.4); }
    h1 { margin-bottom: 1rem; font-size: 2rem; }
    p { line-height: 1.6; }
    a { color: #8cc8ff; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Account Suspended</h1>
    <p>The account <strong>{$safeUser}</strong> is temporarily unavailable.</p>
    <p>Please contact support if you believe this is a mistake.</p>
    <p><a href="https://pulsedmedia.com/contact/">pulsedmedia.com/contact/</a></p>
  </div>
</body>
</html>
HTML;
}
