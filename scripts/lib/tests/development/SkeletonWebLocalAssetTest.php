<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SkeletonWebLocalAssetTest extends TestCase
{
    public function testWelcomeAndInfoUseBundledScreenStylesheet(): void
    {
        foreach (['etc/skel/www/welcome.php', 'etc/skel/www/info.php'] as $path) {
            $source = $this->pmssReadRepoFile($path);

            $this->assertStringContainsString('href="screen.css"', $source, $path.' should load the bundled stylesheet.');
            $this->pmssAssertStringNotContainsString('static.pulsedmedia.com/wc/css/screen.css', $source, $path.' should not depend on the retired static stylesheet host.');
        }
    }

    public function testIndexLocalFallbackUsesBundledTabsAssets(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');

        $this->assertStringContainsAllStrings(
            [
                '<script src="pmssTabs.js"></script>',
                '<link rel="stylesheet" href="jquery.tabs.css"',
            ],
            $source,
            'Missing local tabs asset reference: '
        );

        $this->pmssAssertStringNotContainsString('static.pulsedmedia.com/jquery.tabs.pack.js', $source, 'index.php should not depend on the remote tabs script host.');
        $this->pmssAssertStringNotContainsString('static.pulsedmedia.com/jquery.tabs.css', $source, 'index.php should not depend on the remote tabs stylesheet host.');
    }

    public function testUserFilesystemSyncIncludesBundledGuiAssets(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');

        $this->assertStringContainsAllStrings(
            [
                "'www/jquery.tabs.css',",
                "'www/pmssTabs.js',",
                "'www/screen.css',",
            ],
            $source,
            'Missing skeleton sync asset entry: '
        );
    }
}
