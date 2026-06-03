<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SkeletonWebLocalAssetTest extends TestCase
{
    public function testWelcomeAndInfoUseBundledScreenStylesheet(): void
    {
        foreach (['etc/skel/www/welcome.php', 'etc/skel/www/info.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'href="screen.css"', $path.' should load the bundled stylesheet.');
            $this->pmssAssertRepoFileNotContainsString($path, 'static.pulsedmedia.com/wc/css/screen.css', $path.' should not depend on the retired static stylesheet host.');
        }
    }

    public function testBundledScreenStylesheetKeepsWelcomePanelCompact(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/screen.css',
            [
                'font-size: 13px;',
                'line-height: 1.3;',
                'padding: 6px;',
                'gap: 8px;',
                'font-size: 1.15rem;',
                'font-size: 0.8rem;',
                'height: 12px;',
            ],
            'Missing compact welcome stylesheet rule: '
        );

        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/skel/www/screen.css',
            [
                'padding: 24px 16px;',
                'gap: 24px;',
                'font-size: 2rem;',
                'font-size: 1.05rem;',
                'padding: 9px 14px;',
            ],
            'Large welcome stylesheet rule should not return: '
        );
    }

    public function testIndexLocalFallbackUsesBundledTabsAssets(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/index.php',
            [
                '<script src="pmssTabs.js"></script>',
                '<link rel="stylesheet" href="jquery.tabs.css"',
            ],
            'Missing local tabs asset reference: '
        );

        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/index.php', [
            'static.pulsedmedia.com/jquery.tabs.pack.js',
            'static.pulsedmedia.com/jquery.tabs.css',
        ], 'index.php should not depend on remote tabs assets: ');
    }

    public function testLocalTabsHelperAddsNavigationClass(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/skel/www/index.php',
            '<ul class="tabs-nav">',
            'index.php must emit styled tab navigation even when JavaScript fails to load.'
        );

        $this->pmssAssertRepoFileContainsString(
            'etc/skel/www/pmssTabs.js',
            "container.find('> ul').addClass('tabs-nav');",
            'pmssTabs.js must add the tabs-nav class required by jquery.tabs.css.'
        );
    }

    public function testIndexTabsOverrideLegacySpriteGeometry(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/index.php',
            [
                'top: 34px;',
                'font-size: 14px;',
                'padding: 6px 14px;',
                'width: auto;',
                'height: auto;',
                'min-width: 0;',
                'min-height: 0;',
                'background-image: none;',
                'var offsetHeight = -34;',
            ],
            'Missing tab geometry override: '
        );

        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/skel/www/jquery.tabs.css',
            [
                'background: url(tab.png) no-repeat;',
                'background-position:',
                'width: 64px;',
                'min-width: 64px;',
                'height: 18px;',
                'min-height: 18px;',
                'padding-top: 6px;',
                'padding-right: 0;',
            ],
            'Legacy sprite tab geometry should not constrain flex tabs: '
        );
    }

    public function testLighttpdTemplateEnablesRemoteFrames(): void
    {
        // Reverted #601 per operator directive 2026-06-03: remote guiFrames is the
        // PRIMARY on-load GUI auto-update path, local frames are the FAILOVER. The
        // template must NOT set PMSS_DISABLE_REMOTE_FRAMES, so the per-user php-cgi
        // environment leaves remote frames enabled. (Safe re-enable precondition:
        // web4 serves current files via the daily guiv sync cron — see
        // /home/billing/scripts/sync-guiv-from-pmss.sh.)
        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/seedbox/config/template.lighttpd',
            ['PMSS_DISABLE_REMOTE_FRAMES'],
            'lighttpd template must NOT disable remote frames (remote is primary, local is failover): '
        );
    }

    public function testUserFilesystemSyncIncludesBundledGuiAssets(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/update/users/filesystem.php',
            [
                "'www/deluge.php',",
                "'www/error-503.html',",
                "'www/filemanager.php',",
                "'www/info.php',",
                "'www/index.php',",
                "'www/jquery.tabs.css',",
                "'www/mediaStack.php',",
                "'www/pmssTabs.js',",
                "'www/qbittorrent.php',",
                "'www/rclone.php',",
                "'www/screen.css',",
                "'www/stats.php',",
                "'www/statsHelpers.php',",
                "'www/welcome.php',",
            ],
            'Missing skeleton sync asset entry: '
        );
    }
}
