<?php
/**
 * ruTorrent maintenance tasks.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/context.php';

/**
 * Patch ruTorrent schedule interval arithmetic for PHP 8.2 strict typing.
 *
 * Upstream vendor files under `etc/skel/www/rutorrent` are currently frozen,
 * so updates patch tenant copies until the bundled file can be updated.
 */
function pmssUserRutorrentScheduleIntervalPatchApply(string $filePath): bool
{
    if (!is_file($filePath)
        || is_link($filePath)
        || !is_string($content = @file_get_contents($filePath))
        || $content === '') {
        return false;
    }

    $patchedExpression = '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),';
    if (strpos($content, $patchedExpression) !== false) {
        return true;
    }

    $legacyExpression = '((integer)($tm["minutes"]/$interval))*$interval+$interval,';
    $updated = str_replace($legacyExpression, $patchedExpression, $content, $replacements);
    if ($replacements < 1 || $updated === $content) {
        return false;
    }

    return @file_put_contents($filePath, $updated) !== false;
}

/**
 * Suppress bare ob_flush notices in ruTorrent RSS plugin responses.
 *
 * The bundled ruTorrent tree is frozen for now, so we patch tenant copies
 * until the upstream files are replaced by a version bump.
 */
function pmssUserRutorrentRssObFlushPatchApply(string $filePath): bool
{
    if (!is_file($filePath)
        || is_link($filePath)
        || !is_string($content = @file_get_contents($filePath))
        || $content === '') {
        return false;
    }

    if (strpos($content, '@ob_flush();') !== false) {
        return true;
    }

    $updated = str_replace('ob_flush();', '@ob_flush();', $content, $replacements);
    if ($replacements < 1 || $updated === $content) {
        return false;
    }

    return @file_put_contents($filePath, $updated) !== false;
}

/**
 * Apply compatibility patches for legacy ruTorrent PHP files.
 */
function pmssUserMaintainRutorrentPhpCompatibility(array $ctx): void
{
    pmssUserRutorrentScheduleIntervalPatchApply($ctx['home'].'/www/rutorrent/php/settings.php');
    pmssUserRutorrentRssObFlushPatchApply($ctx['home'].'/www/rutorrent/plugins/rss/action.php');
}

function pmssUserUpdateThemes(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    $themesPath = "{$home}/www/rutorrent/plugins/theme/themes/";
    $themes     = ['Agent34','Agent46','OblivionBlue','FlatUI_Dark','FlatUI_Light','FlatUI_Material','MaterialDesign','club-QuickBox'];
    foreach ($themes as $theme) {
        if (file_exists($themesPath.$theme)) {
            continue;
        }

        $source = pmssUserSkelPath("www/rutorrent/plugins/theme/themes/{$theme}");
        runUserStep(
            $user,
            "Installing ruTorrent theme {$theme}",
            sprintf('cp -r %s %s',
                escapeshellarg($source),
                escapeshellarg($themesPath)
            )
        );
        runUserStep(
            $user,
            "Adjusting theme {$theme} ownership",
            sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($themesPath.$theme))
        );
    }
}

function pmssUserUpgradeRutorrent(array $ctx): void
{
    $user        = $ctx['user'];
    $home        = $ctx['home'];
    $userEsc     = $ctx['user_esc'];
    $expectedSha = $ctx['rutorrent_index_sha'];
    $rutorrentPath = "{$home}/www/rutorrent";
    $legacyPath = "{$home}/www/oldRutorrent-3";
    $currentIndex = $rutorrentPath.'/index.html';

    if ($expectedSha === ''
        || !file_exists($currentIndex)
        || file_exists($legacyPath)
        || $expectedSha === sha1(file_get_contents($currentIndex))) {
        return;
    }

    echo "****** Updating ruTorrent\n";
    echo "******* Backing up old as 'oldRutorrent-3'\n";
    runUserStep(
        $user,
        'Backing up existing ruTorrent',
        sprintf('mv %s %s', escapeshellarg($rutorrentPath), escapeshellarg($legacyPath))
    );
    echo "******* Copying new ruTorrent from skel\n";
    runUserStep(
        $user,
        'Copying new ruTorrent from skel',
        sprintf('cp -Rp %s %s',
            escapeshellarg(pmssUserSkelPath('www/rutorrent')),
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
