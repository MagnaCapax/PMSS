<?php
/**
 * @domain user home maintenance — skeleton, files, permissions
 *
 * User home maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once dirname(__DIR__, 2).'/user/trafficLimit.php';
require_once dirname(__DIR__, 2).'/user/directories.php';
require_once dirname(__DIR__, 2).'/user/identity.php';

pmssRequireRelativeFiles(__DIR__, ['filesystemPaths.php', 'filesystemPatch.php', 'panelCompatibility.php', 'panelFiles.php']);

/** Converge managed files in their historical order, then apply tenant-copy repairs. */
function pmssUserApplySkeletonFiles(array $ctx): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);
    pmssTrafficLimitHomeArtifactReconcile(
        $user,
        dirname($home),
        null,
        static function (string $message) use ($user): void {
            logMessage("[user:{$user}] {$message}");
        }
    );
    pmssUserRefreshPanelIndexForFrameDataCompat($ctx);
    pmssUserEnsureWebRootSymlinks($ctx);
    pmssUserMigrateWebRootState($ctx);

    $legacyDownloadHeaderBlock = <<<'PHP'
    if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
        $fileName = preg_replace('/\./', '%2e', $fileName, substr_count($fileName, '.') - 1);
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    } else {
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    }
PHP;
    foreach (array_merge([
        '.rtorrentExecute.php',
        '.rtorrentRestart.php',
        '.rtorrentThrottleInit.php',
        '.bashrc',
        'install-media-stack.sh',
        'install-ai-tools.sh',
        'bin/docker-install-lsio',
        'bin/dockerInstallLsio.php',
        'bin/dockerInstallLsioTorrent.php',
        'bin/dockerLsioSeed.php',
        'bin/dockerLsioSeedHttp.php',
        'bin/docker-install-wireguard.sh',
        'bin/docker-reclaim-ownership',
        'bin/pmssOpenClawProvider.php',
        'bin/pmssOpenClawKokkoArchive.php',
        'bin/pmssOpenClawKokko.php',
        'bin/install-openclaw',
        'bin/install-hermes',
        'bin/pmssHermesCli.php',
        'bin/pmssAccountAgent.php',
        'bin/pmssHermesRuntime.php',
        'bin/pmssHermesRelay.php',
        'bin/pmssHermesInstall.php',
        'bin/pikkumiesHermesUi.php',
        'bin/pikkumiesHermesAuth.php',
        'bin/pikkumiesHermesRuntime.php',
        'bin/install-pikkumies.sh',
        'bin/linuxserverInstall.sh',
        'bin/support',
        'bin/createWebPublicCerts',
        'bin/userShowLimits',
        // The split panel helpers require the catalog as soon as they arrive.
        'www/toggleAppCatalog.php',
    ], pmssUserPanelHelperFiles('www/'), [
        'www/scriptsInc.php',
        '.scriptsInc.php',
        'radarr-sonarr.txt',
        'www/console.php',
        'www/pikkumiesOpen.php',
        'www/pikkumiesPanel.php',
        'www/apps.php',
        'www/configure.php',
        'www/configureFileWrite.php',
        'www/configureSettings.php',
        'www/configureAppOwnerSettings.php',
        'www/hermesAgent.php',
        'www/hermesLogin.php',
        'www/configureTorrentClientsProcess.php',
        'www/configureTorrentClientsStart.php',
        'www/configureTorrentClients.php',
        'www/configureTorrentClientsCard.php',
        'www/configureTorrentClientsOther.php',
        'www/configureTorrentClientsRc.php',
        'www/configureTorrentClientsSwitch.php',
        'www/configureTorrentClientsVersion.php',
        'www/appsLsioCatalog.php',
        'www/appsRuntime.php',
        'www/deluge.php',
        'www/error-503.html',
        'www/filemanager.php',
        'www/info.php',
        'www/index.php',
        'www/jquery.tabs.css',
        'www/mediaStack.php',
        'www/mediaStackRecoveryCommand.php',
        'www/openvpn-config.tgz',
        'www/panelSessionStore.php',
        'www/panelSessionAuth.php',
        'www/panelSessionPage.php',
        'www/panelSession.php',
        'www/panelSessionLogin.php',
        'www/panelSessionLogout.php',
        'www/pmssTabs.js',
        'www/qbittorrent.php',
        'www/qbittorrentPasswordRequired.php',
        'www/qbittorrentWebUi.php',
        'www/qbittorrentConfig.php',
        'www/qbittorrentRestore.php',
        'www/qbittorrentStoppedConfig.php',
        'www/qbittorrentAccountProcess.php',
        'www/qbittorrentLegacyKeys.php',
        'www/qbittorrentSocket.php',
        'www/qbittorrentBuild.php',
        'www/qbittorrentBuildProbe.php',
        'www/fileReadVerified.php',
        'www/qbittorrentThrottle.php',
        'www/qbittorrentThrottleStopped.php',
        'www/qbittorrentThrottleStart.php',
        'www/qbittorrentRefusal.php',
        'www/qbittorrentResolverLaunch.php',
        'www/qbittorrentStart.php',
        'www/qbittorrentPassword.php',
        'www/torrentClientsResolve.php',
        'www/transmission.php',
        'www/transmissionLaunch.php',
        'www/slskd.php',
        'www/copyparty.php',
        'www/toggleApp.php',
        'www/toggleAppRuntime.php',
        'www/rclone.php',
        'www/rtorrentRestart.php',
        'www/lighttpdRestart.php',
        'www/rutorrent/js/content.js',
        'www/screen.css',
        'www/stats.php',
        'www/statsState.php',
        'www/statsStatus.php',
        'www/statsTraffic.php',
        'www/statsResourceModel.php',
        'www/statsResourceRender.php',
        'www/statsCharts.php',
        'www/statsHelpers.php',
        'www/statsLive.php',
        'www/rutorrent/php/settings.php',
        'www/rutorrent/plugins/theme/conf.php',
        'www/welcome.php',
        'www/welcomeAnnouncements.php',
        'www/userTrafficLimit.php',
        'www/webCgroupMemorySource.php',
        'www/webCgroupMemoryPolicy.php',
        'www/webCgroupMemoryRead.php',
        'www/welcomeMemory.php',
        'www/webCgroupMemoryStatus.php',
        'www/storageHealthRaidDisplay.php',
        'www/storageHealthRaid.php',
        'www/storageHealthPressure.php',
        'www/storageHealthDisplay.php',
        'www/storageHealthNotice.php',
        'www/readLimitNotice.php',
        'www/welcomeMessage.php',
        'www/userMediaStackPanel.php',
        'www/userOpenClaw.php',
        'www/userPasswords.php',
    ]) as $file) {
        updateUserFile($file, $user);
    }

    pmssUserRemoveLegacyOpenClawInstaller($user, $home);

    // Remove the obsolete explorer and dead extsearch engines from tenant copies
    // until the frozen skeleton ruTorrent tree can be curated directly.
    foreach ([
        $home.'/www/phpXplorer',
        $home.'/www/rutorrent/plugins/extsearch/engines/RARbgTorrentAPI.php',
        $home.'/www/rutorrent/plugins/extsearch/engines/Demonoid.php',
        $home.'/www/rutorrent/plugins/extsearch/engines/KAT.php',
    ] as $path) {
        pmssUserDeletePathIfPresent($path);
    }

    // Patch tenant copies until the frozen skeleton filemanager source can be
    // updated upstream without touching the locked tree.
    pmssUserPatchWritableStrings(
        $home.'/www/filemanager.php',
        [
            ['legacy' => '        ob_flush();', 'patched' => '        @ob_flush();'],
            ['legacy' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.slim.min.js', 'patched' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.slim.min.js'],
            ['legacy' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js', 'patched' => 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js'],
            ['legacy' => 'https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js', 'patched' => 'https://cdn.datatables.net/2.0.8/js/dataTables.min.js'],
            ['legacy' => '        str_replace($range, "-", $range);', 'patched' => '        $range = str_replace("-", "", $range);'],
            ['legacy' => $legacyDownloadHeaderBlock, 'patched' => '    header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");'],
        ]
    );

    $skelBase = pmssSkeletonBase();
    foreach (glob($skelBase.'/www/rutorrent/plugins/hddquota/*') ?: [] as $file) {
        $relative = strpos($file, $skelBase.'/') === 0 ? substr($file, strlen($skelBase) + 1) : str_replace('/etc/skel/', '', $file);
        updateUserFile($relative, $user);
    }

    [$delivered, $failed] = pmssUpdateUserRootStageCounts($user);
    if ($delivered + $failed > 0) {
        logMessage("[user:{$user}] Root-stage skeleton delivery summary: {$delivered} delivered, {$failed} failed");
    }
}
