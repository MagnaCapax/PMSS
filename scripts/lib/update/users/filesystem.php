<?php
/**
 * @domain user home maintenance — skeleton, files, permissions
 *
 * User home maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Confirm that a user maintenance path resolves beneath the configured home root.
 */
function pmssUserPathWithinHomeRoot(string $path): bool
{
    $homeRoot = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/');
    $realHomeRoot = realpath($homeRoot);
    $realParent = realpath(dirname($path));
    if ($homeRoot === '' || $homeRoot === '/' || $realHomeRoot === false || $realParent === false) {
        return false;
    }

    $candidate = rtrim($realParent, '/').'/'.basename($path);
    $prefix = rtrim($realHomeRoot, '/').'/';
    return strpos($candidate, $prefix) === 0;
}

/**
 * Apply a text patch to a writable tenant file when it exists.
 *
 * @param string   $path    Writable file path in the tenant home.
 * @param callable $patcher Content transformer that returns updated text.
 */
function pmssUserPatchWritableFile(string $path, callable $patcher): void
{
    if (!pmssUserPathWithinHomeRoot($path)
        || !is_file($path)
        || is_link($path)
        || !is_string($content = @file_get_contents($path))
        || $content === '') {
        return;
    }

    $updated = $patcher($content);
    if (is_string($updated) && $updated !== $content) {
        @file_put_contents($path, $updated);
    }
}

/** @param array<int, array{legacy:string,patched:string}> $replacements */
function pmssUserPatchWritableStrings(string $path, array $replacements): void
{
    pmssUserPatchWritableFile($path, static function (string $updated) use ($replacements): string {
        foreach ($replacements as $replacement) {
            if (strpos($updated, $replacement['patched']) !== false
                && strpos($updated, $replacement['legacy']) === false) {
                continue;
            }
            $placeholder = '';
            if (strpos($replacement['patched'], $replacement['legacy']) !== false
                && strpos($updated, $replacement['patched']) !== false) {
                $placeholder = '__PMSS_PATCHED_'.sha1($replacement['patched']).'__';
                while (strpos($updated, $placeholder) !== false) {
                    $placeholder .= '_X';
                }
                $updated = str_replace($replacement['patched'], $placeholder, $updated);
            }
            $updated = str_replace($replacement['legacy'], $replacement['patched'], $updated, $count);
            if ($placeholder !== '') {
                $updated = str_replace($placeholder, $replacement['patched'], $updated);
            }
        }
        return $updated;
    });
}

/**
 * Remove a file or symlink if it still exists.
 */
function pmssUserDeletePathIfPresent(string $path): void
{
    if (pmssUserPathWithinHomeRoot($path) && (is_file($path) || is_link($path)) && file_exists($path)) {
        @unlink($path);
    }
}

/**
 * Detect legacy panel copies that fatal on PHP 8.2 because frameData is null.
 */
function pmssUserPanelIndexNeedsFrameDataCompatRefresh(string $content): bool
{
    return strpos($content, '$frameData = array();') === false
        && strpos($content, '$frames = array_merge($frames, $frameData);') !== false;
}

/**
 * Force-refresh only the known broken legacy panel index.php variant.
 *
 * This explicit migration runs before the broad updateUserFile() SHA1 sync so
 * the PHP 8 frameData repair stays visible as its own compatibility path.
 */
function pmssUserRefreshPanelIndexForFrameDataCompat(array $ctx): void
{
    $user = (string) ($ctx['user'] ?? '');
    $home = rtrim((string) ($ctx['home'] ?? ''), '/');
    if ($user === '' || $home === '') {
        return;
    }

    $targetFile = $home.'/www/index.php';
    if (!pmssUserPathWithinHomeRoot($targetFile) || !is_file($targetFile) || is_link($targetFile)) {
        return;
    }

    $sourceFile = pmssSkeletonBase().'/www/index.php';
    if (!is_file($sourceFile)) {
        return;
    }

    $sourceContent = @file_get_contents($sourceFile);
    $targetContent = @file_get_contents($targetFile);
    if (!is_string($sourceContent) || !is_string($targetContent)) {
        return;
    }

    if (!pmssUserPanelIndexNeedsFrameDataCompatRefresh($targetContent)
        || strpos($sourceContent, '$frameData = array();') === false
        || strpos($sourceContent, 'function pmssFrameOpensInNewWindow(array $frame)') === false) {
        return;
    }

    if (copyToUserSpace($sourceFile, $targetFile, $user)) {
        logMessage("[user:{$user}] Updated legacy panel index.php for frameData compatibility");
    }
}

function pmssUserApplySkeletonFiles(array $ctx): void
{
    $user = $ctx['user'];
    pmssUserRefreshPanelIndexForFrameDataCompat($ctx);

    $legacyDownloadHeaderBlock = <<<'PHP'
    if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
        $fileName = preg_replace('/\./', '%2e', $fileName, substr_count($fileName, '.') - 1);
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    } else {
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    }
PHP;
    $files = [
        '.rtorrentExecute.php',
        '.rtorrentRestart.php',
        '.bashrc',
        'install-media-stack.sh',
        'install-ai-tools.sh',
        'bin/docker-install-lsio',
        'bin/docker-install-wireguard.sh',
        'bin/linuxserverInstall.sh',
        'bin/support',
        '.scriptsInc.php',
        '.lighttpd/php.ini',
        'radarr-sonarr.txt',
        'www/deluge.php',
        'www/error-503.html',
        'www/filemanager.php',
        'www/index.php',
        'www/jquery.tabs.css',
        'www/mediaStack.php',
        'www/openvpn-config.tgz',
        'www/pmssTabs.js',
        'www/qbittorrent.php',
        'www/rutorrent/js/content.js',
        'www/screen.css',
        'www/rutorrent/php/settings.php',
        'www/rutorrent/plugins/theme/conf.php',
        'www/welcome.php',
        'www/welcomeAnnouncements.php',
        'www/userTrafficLimit.php',
        'www/webCgroupMemoryStatus.php',
        'www/storageHealthNotice.php',
        'www/welcomeMessage.php',
        'www/userMediaStackPanel.php',
        'www/userPasswords.php',
    ];
    foreach ($files as $file) {
        updateUserFile($file, $user);
    }

    pmssUserDeletePathIfPresent($ctx['home'].'/www/phpXplorer');

    // Remove dead extsearch engines from tenant copies until the frozen
    // skeleton ruTorrent tree can be curated directly.
    foreach ([
        $ctx['home'].'/www/rutorrent/plugins/extsearch/engines/RARbgTorrentAPI.php',
        $ctx['home'].'/www/rutorrent/plugins/extsearch/engines/Demonoid.php',
        $ctx['home'].'/www/rutorrent/plugins/extsearch/engines/KAT.php',
    ] as $path) {
        pmssUserDeletePathIfPresent($path);
    }

    // Patch tenant copies until the frozen skeleton filemanager source can be
    // updated upstream without touching the locked tree.
    pmssUserPatchWritableStrings(
        $ctx['home'].'/www/filemanager.php',
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
}
