<?php
/**
 * @domain rutorrent maintenance — themes, plugins, refresh
 *
 * ruTorrent maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Keep account file work in the user-step profiler under that account. */
function pmssRutorrentAccountStep(string $user, string $home, string $label, string $command): int
{
    $account = pmssAccountPathIdentity($user, $home);
    if (is_array($account) && @posix_geteuid() === $account['uid']) {
        return pmssAccountPathRun($user, $home, [$home.'/.pmss-rutorrent-check'], $command) ? 0 : 1;
    }
    return runUserStep($user, $label, pmssBuildUserShellCommand($user, $command, '/bin/sh'));
}

/** Make root-only skeleton content readable for one account-side extraction. */
function pmssRutorrentInstallSkeletonTree(string $user, string $home, string $source, string $destination, string $label): bool
{
    if (!pmssPathTargetIsSafe($source, true) || !is_dir($destination)
        || !pmssPathWithinResolvedRoot($destination.'/.pmss-check', $home)) return false;
    $account = pmssAccountPathIdentity($user, $home);
    if (!is_array($account)) return false;
    if (@posix_geteuid() === $account['uid']) {
        return pmssAccountPathRun($user, $home, [$destination.'/'.basename($source)],
            'cp -R -- '.escapeshellarg($source).' '.escapeshellarg($destination));
    }
    $context = pmssRootStageContext($destination.'/'.basename($source));
    if ($context === null || $context[1] !== $home || $context[2] !== $user) return false;
    $stage = $context[0].'/.pmss-skel-source-'.bin2hex(random_bytes(12));
    if (!@mkdir($stage, 0700)) return false;
    $archive = $stage.'/source.tar';
    try {
        $sourceCommand = 'umask 077; tar -C '.escapeshellarg(dirname($source))
            .' -cf '.escapeshellarg($archive).' -- '.escapeshellarg(basename($source));
        if (runStep("[user:{$user}] Preparing {$label}", $sourceCommand) !== 0
            || !is_file($archive) || !@chgrp($archive, $account['gid']) || !@chmod($archive, 0640)
            || !@chgrp($stage, $account['gid']) || !@chmod($stage, 0750)) return false;
        $extract = 'tar --no-same-owner --no-same-permissions -xf '.escapeshellarg($archive)
            .' -C '.escapeshellarg($destination);
        return pmssRutorrentAccountStep($user, $home, $label, $extract) === 0;
    } finally {
        @unlink($archive);
        @rmdir($stage);
    }
}

/**
 * Keep ruTorrent plugin maintenance in the main user update module.
 */
function pmssUserEnsurePlugins(array $ctx): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);

    if (file_exists("{$home}/www/rutorrent/plugins/cpuload")) {
        pmssRutorrentAccountStep($user, $home, 'Removing deprecated cpuload plugin', 'rm -rf -- '.escapeshellarg("{$home}/www/rutorrent/plugins/cpuload"));
    }

    $unpackPath = "{$home}/www/rutorrent/plugins/unpack";
    if (!file_exists($unpackPath)) {
        $source = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent/plugins/unpack';
        if (pmssRutorrentInstallSkeletonTree($user, $home, $source, dirname($unpackPath), 'Installing unpack plugin')) {
            pmssRutorrentAccountStep($user, $home, 'Setting unpack plugin permissions', pmssUserEntryChmodCommand($unpackPath, 0755, true));
        }
    }

    $userShareDir = "{$home}/www/rutorrent/share/users/{$user}";
    $retrackerConfigPath = "{$userShareDir}/settings";
    $retrackerFile = "{$retrackerConfigPath}/retrackers.dat";
    $retrackerContent = pmssReadRegularFileContentsVerified($retrackerFile, null, 1048576);
    if (is_string($retrackerContent)
        && in_array(sha1(trim($retrackerContent)), ['9958caa274c2df67ea6702772821856365bc1201', 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a'], true)) {
        pmssRutorrentAccountStep($user, $home, 'Removing legacy retrackers', 'rm -f -- '.escapeshellarg($retrackerFile));
    }

    if (!file_exists($userShareDir.'/torrents') && file_exists($userShareDir)) {
        pmssRutorrentAccountStep($user, $home, 'Creating ruTorrent torrents directory', sprintf('mkdir -p %s', escapeshellarg($userShareDir.'/torrents')));
    }

    $rssDir = "{$home}/www/rutorrent/share/settings/rss";
    if (!file_exists($rssDir)) {
        pmssRutorrentAccountStep($user, $home, 'Creating ruTorrent RSS settings directory', sprintf('mkdir -p %s', escapeshellarg($rssDir)));
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
    $snoopyAcceptLegacy = "\tvar \$accept\t\t\t=\t\"image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, */*\";";
    $snoopyAcceptPatched = "\tvar \$accept\t\t\t=\t\"text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8\";";

    // Apply each legacy replacement in order, preserving per-patch writes.
    foreach (array_merge([
        ['php/settings.php', $settingsIntervalGuardLegacy, $settingsIntervalGuardPatched],
        ['php/settings.php', '((integer)($tm["minutes"]/$interval))*$interval+$interval,', '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),'],
        ['php/Snoopy.class.inc', $snoopyAcceptLegacy, $snoopyAcceptPatched],
        ['plugins/rss/action.php', 'ob_flush();', '@ob_flush();'],
        ['plugins/hddquota/action.php', 'return $field;', 'return (int) $field;'],
        ['plugins/throttle/throttle.php', 'new rXMLRPCCommand( "set_upload_rate", MAX_SPEED )', 'new rXMLRPCCommand( "set_upload_rate", 0 )'],
        ['plugins/throttle/throttle.php', 'new rXMLRPCCommand( "set_download_rate", MAX_SPEED )', 'new rXMLRPCCommand( "set_download_rate", 0 )'],
    ], pmssUserRutorrentRpcCompatibilityPatches()) as [$path, $legacy, $patched]) {
        pmssUserPatchWritableStrings($ctx['home'].'/www/rutorrent/'.$path, [['legacy' => $legacy, 'patched' => $patched]]);
    }
}

/**
 * Refresh bundled ruTorrent theme assets from the skeleton tree.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserUpdateThemes(array $ctx): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);

    $themesPath = "{$home}/www/rutorrent/plugins/theme/themes/";
    $skelThemesPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent/plugins/theme/themes/';
    foreach (['Agent34','Agent46','OblivionBlue','FlatUI_Dark','FlatUI_Light','FlatUI_Material','MaterialDesign','club-QuickBox'] as $theme) {
        if (file_exists($themesPath.$theme)) {
            continue;
        }

        pmssRutorrentInstallSkeletonTree($user, $home, $skelThemesPath.$theme,
            rtrim($themesPath, '/'), "Installing ruTorrent theme {$theme}");
    }
}

/**
 * Replace stale per-user ruTorrent trees with the current skeleton copy.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserUpgradeRutorrent(array $ctx): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);
    $expectedSha   = $ctx['rutorrent_index_sha'];
    $rutorrentPath = "{$home}/www/rutorrent";
    $legacyPath    = "{$home}/www/oldRutorrent-3";
    $sharePath     = $rutorrentPath.'/share';
    $durableShare  = "{$home}/.local/share/pmss/rutorrent/share";
    $currentIndex = pmssReadRegularFileContentsVerified($rutorrentPath.'/index.html', null, 1048576);

    if ($expectedSha === ''
        || $currentIndex === null
        || file_exists($legacyPath)
        || $expectedSha === sha1($currentIndex)) {
        return;
    }

    $shareLink = pmssUserWebRootMigrationRelativeTarget($home, $sharePath, $durableShare);
    $usesDurableShare = is_link($sharePath)
        && is_string($shareLink)
        && @readlink($sharePath) === $shareLink
        && is_dir($durableShare)
        && !is_link($durableShare)
        && pmssPathTargetIsSafe($durableShare, true);
    if (is_link($sharePath) && !$usesDurableShare) {
        throw new RuntimeException('Refusing ruTorrent upgrade with an unexpected share symlink');
    }

    echo "****** Updating ruTorrent\n";
    echo "******* Backing up old as 'oldRutorrent-3'\n";
    if (pmssRutorrentAccountStep($user, $home, 'Backing up existing ruTorrent', sprintf('mv %s %s', escapeshellarg($rutorrentPath), escapeshellarg($legacyPath))) !== 0) {
        throw new RuntimeException('Unable to back up ruTorrent before upgrade');
    }
    echo "******* Copying new ruTorrent from skel\n";
    if (!pmssRutorrentInstallSkeletonTree($user, $home,
        pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent', "{$home}/www", 'Copying new ruTorrent from skel')) {
        throw new RuntimeException('Unable to copy ruTorrent skeleton during upgrade');
    }
    echo "******* Configuring\n";
    if (pmssRutorrentAccountStep(
        $user,
        $home,
        'Restoring ruTorrent config.php',
        sprintf('cp %s %s', escapeshellarg($legacyPath.'/conf/config.php'), escapeshellarg($rutorrentPath.'/conf/'))
    ) !== 0) {
        throw new RuntimeException('Unable to restore ruTorrent configuration');
    }

    $shareDescription = 'Restoring ruTorrent share directory';
    $shareCommand = sprintf('cp -r %s %s', escapeshellarg($legacyPath.'/share/.'), escapeshellarg($sharePath.'/'));
    if ($usesDurableShare) {
        // ADR 0041 makes the durable store authoritative; discard only the fresh skeleton placeholder.
        $shareDescription = 'Restoring durable ruTorrent share link';
        $shareCommand = sprintf(
            'rm -rf -- %1$s && ln -s -- %2$s %1$s',
            escapeshellarg($sharePath),
            escapeshellarg($shareLink)
        );
    }
    if (pmssRutorrentAccountStep($user, $home, $shareDescription, $shareCommand) !== 0
        || ($usesDurableShare && (!is_link($sharePath) || @readlink($sharePath) !== $shareLink))) {
        throw new RuntimeException('Unable to restore ruTorrent share state');
    }

    updateRutorrentConfig($user, 1);
    pmssRutorrentAccountStep($user, $home, 'Setting ruTorrent permissions', pmssUserEntryChmodCommand($rutorrentPath, 0751, true));
}
