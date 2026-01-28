#!/usr/bin/env php
<?php
/**
 * Disable a user account and present a friendly suspended landing page.
 *
 * Locks the Unix account, sets an immediate expiry, and swaps the web root
 * to a suspended notice while preserving the original content under
 * /home/<user>/www-disabled. Also refreshes nginx user config so the UI
 * reflects the suspension immediately.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */
require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/homeMount.php';
require_once __DIR__.'/lib/user/userConfigStore.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Suspending
// a user when /home is unavailable would fail or act on stale paths.
pmssRequireHomeMounted('suspend.php');

$usage = 'suspend.php USERNAME';
$username = $argv[1] ?? '';
if ($username === '') {
    die($usage."\n");
}

$username = pmssNormalizeUsername($username);
// Validate inputs early so we never feed garbage to usermod or log files.
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

// Canonical suspended detection: only the presence of www-disabled matters.
if (is_dir($disabledRoot)) {
    (new UserConfigStore())->setSuspended($username, true);
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

// Best-effort archive of the original web root. We only create a placeholder
// landing page if the original content is safely moved aside.
if (is_dir($activeRoot)) {
    if (!@rename($activeRoot, $disabledRoot)) {
        echo "Warning: failed to archive {$activeRoot}, attempting to continue\n";
    }
}

// The canonical suspended marker is www-disabled. Ensure it exists even when the
// user did not have a www/ directory at suspend time (rare legacy state).
if (!is_dir($disabledRoot)) {
    if (@mkdir($disabledRoot, 0755, true)) {
        @chown($disabledRoot, $username);
        @chgrp($disabledRoot, $username);
    }
}

// Create placeholder landing only when we did not leave an existing www/ in place.
$landingCreated = false;
$landingMessage = '';
if (!is_dir($activeRoot)) {
    $landingCreated = pmssCreateSuspendedLanding($homeDir, $username);
    if (!$landingCreated) {
        $landingMessage = 'Suspended landing page could not be written';
    }
} else {
    $landingMessage = 'Existing www/ not replaced; please inspect manually';
}

// Best-effort: mirror the suspension state in the user config store.
(new UserConfigStore())->setSuspended($username, is_dir($disabledRoot));

pmssUserLifecycleStep(
    'suspend',
    $username,
    'refresh_nginx_config',
    'php /scripts/util/createNginxConfig.php --user '.escapeshellarg($username),
    false
);
// Prefer systemd when available but keep init.d fallback for older hosts.
$restartRc = pmssUserLifecycleStep('suspend', $username, 'restart_nginx_systemctl', 'systemctl restart nginx', false);
if ($restartRc !== 0) {
    pmssUserLifecycleStep('suspend', $username, 'restart_nginx_init', '/etc/init.d/nginx restart', false);
}

pmssUserWriteLogs(
    pmssUserBaseContext(
        'suspend',
        'end',
        $username,
        array(
            'status'         => $landingMessage === '' ? 'OK' : 'WARN',
            'home_dir'       => $homeDir,
            'landing_created'=> $landingCreated,
            'message'        => $landingMessage === '' ? 'User suspended' : $landingMessage,
        )
    )
);

/**
 * Generate the suspended landing page content.
 *
 * @return bool True when both index.html files were written successfully.
 */
function pmssCreateSuspendedLanding(string $homeDir, string $username): bool
{
    $suspendRoot = $homeDir.'/www';
    $publicDir = $suspendRoot.'/public';

    if (!is_dir($suspendRoot) && !@mkdir($suspendRoot, 0755, true)) {
        echo "Failed to create {$suspendRoot}\n";
        return false;
    }
    if (!is_dir($publicDir)) {
        if (!@mkdir($publicDir, 0755, true)) {
            echo "Failed to create {$publicDir}\n";
            return false;
        }
    }

    $html = pmssRenderSuspendedHtml($username);
    $rootResult = @file_put_contents($suspendRoot.'/index.html', $html);
    $publicResult = @file_put_contents($publicDir.'/index.html', $html);

    @chown($suspendRoot, $username);
    @chgrp($suspendRoot, $username);
    @chown($publicDir, $username);
    @chgrp($publicDir, $username);
    @chown($suspendRoot.'/index.html', $username);
    @chgrp($suspendRoot.'/index.html', $username);
    @chown($publicDir.'/index.html', $username);
    @chgrp($publicDir.'/index.html', $username);

    return $rootResult !== false && $publicResult !== false;
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
