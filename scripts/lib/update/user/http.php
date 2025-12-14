<?php
/**
 * HTTP-related maintenance for user environments.
 */

require_once __DIR__.'/utils.php';

/**
 * Configure per-user HTTP stack pieces (lighttpd vhost, ruTorrent temp paths).
 *
 * Suspension handling: callers must only invoke this when the user context is
 * non-null (pmssBuildUserContext filters out suspended users via `www-disabled`).
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserConfigureHttp(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    runUserStep($user, 'Configuring lighttpd vhost', sprintf('/scripts/util/userConfigLighttpd.php %s', $userEsc));

    $phpIniPath = "{$home}/.lighttpd/php.ini";
    if (file_exists($phpIniPath)) {
        $phpIni = parse_ini_file($phpIniPath);
        if ($phpIni !== false && !isset($phpIni['error_log'])) {
            $phpIni['error_log'] = "{$home}/.lighttpd/error.log";
            $newContent = '';
            foreach ($phpIni as $key => $value) {
                $newContent .= sprintf('%s = "%s"\n', $key, $value);
            }
            file_put_contents($phpIniPath, $newContent);
            echo "Updated php.ini for user {$user}\n";
        }
    }

    $tmpDir = "{$home}/.tmp";
    if (!is_dir($tmpDir)) {
        runUserStep($user, 'Creating ruTorrent temp directory', sprintf('mkdir -p %s', escapeshellarg($tmpDir)));
        runUserStep($user, 'Adjusting ownership for ruTorrent temp directory', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($tmpDir)));
    }

    $irssiDir = "{$home}/.irssi";
    if (!is_dir($irssiDir)) {
        runUserStep($user, 'Creating irssi configuration directory', sprintf('mkdir -p %s', escapeshellarg($irssiDir)));
        $skelConfigArg = pmssUserSkelCommandArg('.irssi/config');
        runUserStep($user, 'Copying irssi skeleton config', sprintf('cp %s %s/', $skelConfigArg, escapeshellarg($irssiDir)));
        runUserStep($user, 'Adjusting irssi configuration ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($irssiDir)));
    }

    $recycleDir = "{$home}/www/recycle";
    if (!is_dir($recycleDir)) {
        runUserStep($user, 'Creating recycle directory', sprintf('mkdir -p %s', escapeshellarg($recycleDir)));
        runUserStep($user, 'Adjusting recycle ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($recycleDir)));
        runUserStep($user, 'Setting recycle permissions', sprintf('chmod 771 %s', escapeshellarg($recycleDir)));
    }
}
