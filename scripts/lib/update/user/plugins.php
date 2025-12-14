<?php
/**
 * ruTorrent plugin maintenance helpers.
 */

require_once __DIR__.'/utils.php';

function pmssUserEnsurePlugins(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    if (file_exists("{$home}/www/rutorrent/plugins/cpuload")) {
        runUserStep($user, 'Removing deprecated cpuload plugin', sprintf('rm -rf %s', escapeshellarg("{$home}/www/rutorrent/plugins/cpuload")));
    }

    if (!file_exists("{$home}/www/rutorrent/plugins/unpack")) {
        $source = pmssUserSkelPath('www/rutorrent/plugins/unpack');
        runUserStep($user, 'Installing unpack plugin', sprintf('cp -Rp %s %s',
            escapeshellarg($source),
            escapeshellarg("{$home}/www/rutorrent/plugins/unpack")
        ));
        runUserStep($user, 'Adjusting unpack plugin ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg("{$home}/www/rutorrent/plugins/unpack")));
        runUserStep($user, 'Setting unpack plugin permissions', sprintf('chmod -R 755 %s', escapeshellarg("{$home}/www/rutorrent/plugins/unpack")));
    }
}

function pmssUserMaintainRetracker(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    $retrackerConfigPath = "{$home}/www/rutorrent/share/users/{$user}/settings";
    if (file_exists("{$retrackerConfigPath}/retrackers.dat")) {
        $retrackCurrent = trim((string)file_get_contents("{$retrackerConfigPath}/retrackers.dat"));
        $hash = sha1($retrackCurrent);
        if ($hash === '9958caa274c2df67ea6702772821856365bc1201' ||
            $hash === 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a') {
            unlink("{$retrackerConfigPath}/retrackers.dat");
        }
    }

    if (!file_exists("{$home}/www/rutorrent/share/users/{$user}/torrents") &&
        file_exists("{$home}/www/rutorrent/share/users/{$user}")) {
        runUserStep($user, 'Creating ruTorrent torrents directory', sprintf('mkdir -p %s', escapeshellarg("{$home}/www/rutorrent/share/users/{$user}/torrents")));
        runUserStep($user, 'Adjusting retracker ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($retrackerConfigPath)));
    }

    $rssDir = "{$home}/www/rutorrent/share/settings/rss";
    if (!file_exists($rssDir)) {
        runUserStep($user, 'Creating ruTorrent RSS settings directory', sprintf('mkdir -p %s', escapeshellarg($rssDir)));
        runUserStep($user, 'Adjusting RSS settings ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($rssDir)));
        echo "\t*** Created RSS Settings folder\n";
    }
}
