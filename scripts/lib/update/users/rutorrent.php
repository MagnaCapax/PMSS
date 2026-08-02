<?php
/**
 * @domain rutorrent maintenance — themes, plugins, refresh
 *
 * ruTorrent maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Keep ruTorrent plugin maintenance in the main user update module.
 */
function pmssUserEnsurePlugins(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    if (file_exists("{$home}/www/rutorrent/plugins/cpuload")) {
        runUserStep($user, 'Removing deprecated cpuload plugin', sprintf('rm -rf %s', escapeshellarg("{$home}/www/rutorrent/plugins/cpuload")));
    }

    $unpackPath = "{$home}/www/rutorrent/plugins/unpack";
    if (!file_exists($unpackPath)) {
        $source = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent/plugins/unpack';
        $unpackArg = escapeshellarg($unpackPath);
        runUserStep($user, 'Installing unpack plugin', sprintf('cp -Rp %s %s', escapeshellarg($source), $unpackArg));
        runUserStep($user, 'Adjusting unpack plugin ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, $unpackArg));
        runUserStep($user, 'Setting unpack plugin permissions', sprintf('chmod -R 755 %s', $unpackArg));
    }

    $userShareDir = "{$home}/www/rutorrent/share/users/{$user}";
    $retrackerConfigPath = "{$userShareDir}/settings";
    $retrackerFile = "{$retrackerConfigPath}/retrackers.dat";
    if (file_exists($retrackerFile)
        && in_array(sha1(trim((string) file_get_contents($retrackerFile))), ['9958caa274c2df67ea6702772821856365bc1201', 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a'], true)) {
        unlink($retrackerFile);
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

/**
 * Apply compatibility patches for legacy ruTorrent PHP files.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserMaintainRutorrentPhpCompatibility(array $ctx): void
{
    $settingsIntervalGuardLegacy = <<<'PHP'
		$tm = getdate();
		$startAt = mktime($tm["hours"],
PHP;
    $settingsIntervalGuardPatched = <<<'PHP'
		$tm = getdate();
		$interval = (int)$interval;
		if($interval<1)
			$interval = 30;
		$startAt = mktime($tm["hours"],
PHP;

    // Keep these compatibility shims in one literal patch table.
    foreach ([
        [
            'path' => $ctx['home'].'/www/rutorrent/php/settings.php',
            'legacy' => $settingsIntervalGuardLegacy,
            'patched' => $settingsIntervalGuardPatched,
        ],
        [
            'path' => $ctx['home'].'/www/rutorrent/php/settings.php',
            'legacy' => '((integer)($tm["minutes"]/$interval))*$interval+$interval,',
            'patched' => '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),',
        ],
        [
            'path' => $ctx['home'].'/www/rutorrent/plugins/rss/action.php',
            'legacy' => 'ob_flush();',
            'patched' => '@ob_flush();',
        ],
        [
            'path' => $ctx['home'].'/www/rutorrent/plugins/hddquota/action.php',
            'legacy' => 'return $field;',
            'patched' => 'return (int) $field;',
        ],
        [
            'path' => $ctx['home'].'/www/rutorrent/plugins/throttle/throttle.php',
            'legacy' => 'new rXMLRPCCommand( "set_upload_rate", MAX_SPEED )',
            'patched' => 'new rXMLRPCCommand( "set_upload_rate", 0 )',
        ],
        [
            'path' => $ctx['home'].'/www/rutorrent/plugins/throttle/throttle.php',
            'legacy' => 'new rXMLRPCCommand( "set_download_rate", MAX_SPEED )',
            'patched' => 'new rXMLRPCCommand( "set_download_rate", 0 )',
        ],
    ] as $patch) {
        pmssUserPatchWritableStrings($patch['path'], [$patch]);
    }
}

/**
 * Refresh bundled ruTorrent theme assets from the skeleton tree.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserUpdateThemes(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    $themesPath = "{$home}/www/rutorrent/plugins/theme/themes/";
    $skelThemesPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent/plugins/theme/themes/';
    foreach (['Agent34','Agent46','OblivionBlue','FlatUI_Dark','FlatUI_Light','FlatUI_Material','MaterialDesign','club-QuickBox'] as $theme) {
        if (file_exists($themesPath.$theme)) {
            continue;
        }

        runUserStep(
            $user,
            "Installing ruTorrent theme {$theme}",
            sprintf('cp -r %s %s',
                escapeshellarg($skelThemesPath.$theme),
                escapeshellarg($themesPath)
            )
        );
        runUserStep($user, "Adjusting theme {$theme} ownership", sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($themesPath.$theme)));
    }
}

/**
 * Replace stale per-user ruTorrent trees with the current skeleton copy.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserUpgradeRutorrent(array $ctx): void
{
    $user          = $ctx['user'];
    $home          = $ctx['home'];
    $userEsc       = $ctx['user_esc'];
    $expectedSha   = $ctx['rutorrent_index_sha'];
    $rutorrentPath = "{$home}/www/rutorrent";
    $legacyPath    = "{$home}/www/oldRutorrent-3";

    if ($expectedSha === ''
        || !file_exists($rutorrentPath.'/index.html')
        || file_exists($legacyPath)
        || $expectedSha === sha1(file_get_contents($rutorrentPath.'/index.html'))) {
        return;
    }

    echo "****** Updating ruTorrent\n";
    echo "******* Backing up old as 'oldRutorrent-3'\n";
    runUserStep($user, 'Backing up existing ruTorrent', sprintf('mv %s %s', escapeshellarg($rutorrentPath), escapeshellarg($legacyPath)));
    echo "******* Copying new ruTorrent from skel\n";
    runUserStep(
        $user,
        'Copying new ruTorrent from skel',
        sprintf('cp -Rp %s %s',
            escapeshellarg(pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent'),
            escapeshellarg("{$home}/www/")
        )
    );
    echo "******* Configuring\n";
    runUserStep(
        $user,
        'Restoring ruTorrent config.php',
        sprintf('cp -p %s %s', escapeshellarg($legacyPath.'/conf/config.php'), escapeshellarg($rutorrentPath.'/conf/'))
    );
    runUserStep(
        $user,
        'Restoring ruTorrent share directory',
        sprintf('bash -lc %s',
            escapeshellarg("cp -rp {$legacyPath}/share/* {$rutorrentPath}/share/")
        )
    );
    updateRutorrentConfig($user, 1);
    runUserStep($user, 'Setting ruTorrent ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($rutorrentPath)));
    runUserStep($user, 'Setting ruTorrent permissions', sprintf('chmod -R 751 %s', escapeshellarg($rutorrentPath)));
}
