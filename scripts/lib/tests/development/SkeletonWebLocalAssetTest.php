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
                'font-size: 11px;',
                'line-height: 1.25;',
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

        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/index.php', 'static.pulsedmedia.com/jquery.tabs.pack.js', 'index.php should not depend on the remote tabs script host.');
        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/index.php', 'static.pulsedmedia.com/jquery.tabs.css', 'index.php should not depend on the remote tabs stylesheet host.');
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

    public function testLighttpdTemplateDisablesRemoteFrames(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/template.lighttpd',
            '"PMSS_DISABLE_REMOTE_FRAMES" => "1"',
            'lighttpd template must set PMSS_DISABLE_REMOTE_FRAMES to prevent remote self-updater from reverting deployed GUI files.'
        );
    }

    public function testUserFilesystemSyncIncludesBundledGuiAssets(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/update/users/filesystem.php',
            [
                "'www/jquery.tabs.css',",
                "'www/pmssTabs.js',",
                "'www/screen.css',",
            ],
            'Missing skeleton sync asset entry: '
        );
    }
}
