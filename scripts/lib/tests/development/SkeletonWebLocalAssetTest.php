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
