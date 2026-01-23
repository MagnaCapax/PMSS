<?php
/**
 * ruTorrent plugin maintenance helpers.
 */

require_once __DIR__.'/context.php';

function pmssUserEnsurePlugins(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    if (file_exists("{$home}/www/rutorrent/plugins/cpuload")) {
        runUserStep($user, 'Removing deprecated cpuload plugin', sprintf('rm -rf %s', escapeshellarg("{$home}/www/rutorrent/plugins/cpuload")));
    }

    if (file_exists("{$home}/www/rutorrent/plugins/diskspace")) {
        runUserStep($user, 'Removing deprecated diskspace plugin', sprintf('rm -rf %s', escapeshellarg("{$home}/www/rutorrent/plugins/diskspace")));
    }

    $unpackPath = "{$home}/www/rutorrent/plugins/unpack";
    if (!file_exists($unpackPath)) {
        $source = pmssUserSkelPath('www/rutorrent/plugins/unpack');
        $unpackArg = escapeshellarg($unpackPath);
        runUserStep($user, 'Installing unpack plugin', sprintf('cp -Rp %s %s', escapeshellarg($source), $unpackArg));
        runUserStep($user, 'Adjusting unpack plugin ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, $unpackArg));
        runUserStep($user, 'Setting unpack plugin permissions', sprintf('chmod -R 755 %s', $unpackArg));
    }

    $hddQuotaPath = "{$home}/www/rutorrent/plugins/hddquota";
    if (!file_exists($hddQuotaPath)) {
        $source = pmssUserSkelPath('www/rutorrent/plugins/hddquota');
        $quotaArg = escapeshellarg($hddQuotaPath);
        runUserStep($user, 'Installing hddquota plugin', sprintf('cp -Rp %s %s', escapeshellarg($source), $quotaArg));
        runUserStep($user, 'Adjusting hddquota plugin ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, $quotaArg));
        // Use 755 permissions (standard) instead of the 777 used by the legacy script
        runUserStep($user, 'Setting hddquota plugin permissions', sprintf('chmod -R 755 %s', $quotaArg));
    }
}

function pmssUserMaintainRetracker(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    $userShareDir = "{$home}/www/rutorrent/share/users/{$user}";
    $retrackerConfigPath = "{$userShareDir}/settings";
    if (file_exists("{$retrackerConfigPath}/retrackers.dat")) {
        $retrackCurrent = trim((string)file_get_contents("{$retrackerConfigPath}/retrackers.dat"));
        $hash = sha1($retrackCurrent);
        if (in_array($hash, ['9958caa274c2df67ea6702772821856365bc1201', 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a'], true)) {
            unlink("{$retrackerConfigPath}/retrackers.dat");
        }
    }

    if (!file_exists($userShareDir.'/torrents') && file_exists($userShareDir)) {
        runUserStep($user, 'Creating ruTorrent torrents directory', sprintf('mkdir -p %s', escapeshellarg($userShareDir.'/torrents')));
        runUserStep($user, 'Adjusting retracker ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($retrackerConfigPath)));
    }

    $rssDir = "{$home}/www/rutorrent/share/settings/rss";
    if (!file_exists($rssDir)) {
        runUserStep($user, 'Creating ruTorrent RSS settings directory', sprintf('mkdir -p %s', escapeshellarg($rssDir)));
        runUserStep($user, 'Adjusting RSS settings ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($rssDir)));
        echo "\t*** Created RSS Settings folder\n";
    }
}
