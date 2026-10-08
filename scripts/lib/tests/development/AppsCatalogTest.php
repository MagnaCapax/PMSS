<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
ob_start();
require_once dirname(__DIR__, 4).'/etc/skel/www/apps.php';
ob_end_clean();

/** Customer Apps tab contract: live controls and a closed wiki catalog. */
class AppsCatalogTest extends TestCase
{
    public function testSelfHostedCatalogHasExpectedRowsAndAnchors(): void
    {
        $catalog = \pmssAppsCatalogRead();
        $expected = array('Media_servers_and_live_TV', 'Comics,_books_and_audiobooks',
            'Downloads_and_requests', 'Library_automation', 'Library_maintenance_and_transcoding',
            'Photos,_files_and_passwords', 'Dashboards,_chat_and_desktop');
        $urls = \pmssAppsAllowedUrlsRead();
        $names = array();
        $anchors = array();
        foreach ($catalog as $category => $apps) {
            $anchors[] = substr($urls[$category], strpos($urls[$category], '#') + 1);
            foreach ($apps as $app) {
                $names[] = $app[0];
                $this->assertTrue(is_bool($app[1]));
                $this->assertTrue(is_string($app[2]) && $app[2] !== '');
                $this->assertTrue(is_string($app[3]));
            }
        }
        $this->assertSame($expected, $anchors);
        $this->assertSame(39, count($names));
        $this->assertSame(count($names), count(array_unique($names)));
    }

    public function testFreshHomeHasInstallBannerAndOnlyAllowedWikiLinks(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('apps.php', [], ['minBytes' => 4000]);
        $this->assertStringContainsString('Media Stack is not installed', $html);
        $this->assertStringContainsString('Install Media Stack', $html);
        $this->assertStringContainsString('src="pmssActions.js"', $html);
        preg_match_all('/href="([^"]+)"[^>]*>Setup guide &#8599;<\/a>/', $html, $matches);
        $this->assertSame(39, count($matches[1]));
        $allowed = array_values(\pmssAppsAllowedUrlsRead());
        foreach ($matches[1] as $href) {
            $this->assertTrue(in_array(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $allowed, true), $href);
        }
    }

    public function testInstalledStackRendersRuntimeRowsAndSecurityState(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssEnsureDir($home.'/.config/jellyfin', 0700);
            $this->pmssEnsureDir($home.'/.config/radarr', 0700);
            $this->pmssWriteFile($home.'/.media-stack-status.json', json_encode(array(
                'state' => 'degraded', 'apps' => array(
                    'jellyfin' => array('state' => 'running'),
                    'radarr' => array('state' => 'stopped'),
                    'sonarr' => array('state' => 'failed'),
                ),
            )));
            $html = $render('apps.php', ['minBytes' => 4000])['stdout'];
            $this->assertStringNotContainsString('Media Stack is not installed', $html);
            $this->assertStringContainsString('Stream your library to TV, phone and browser', $html);
            $this->assertStringContainsString('>Running</span>', $html);
            $this->assertStringContainsString('>Stopped</span>', $html);
            $this->assertStringContainsString('>Failed</span>', $html);
            $this->assertStringContainsString('No login set', $html);
            $this->assertStringContainsString('target="_blank" rel="noopener">Open</a>', $html);
        });
    }

    public function testActionsUseExistingCustomerEndpointsOnly(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/apps.php');
        $shared = $this->pmssReadRepoFile('etc/skel/www/pmssActions.js');
        $this->assertStringContainsString("require_once __DIR__.'/scriptsInc.php'", $source);
        $this->assertStringContainsString("require_once __DIR__.'/userMediaStackPanel.php'", $source);
        $this->assertStringNotContainsString('/scripts/', $source);
        $this->assertStringNotContainsString('<form', $source);
        $this->assertStringContainsString("'rtorrentRestart.php'", $source);
        foreach (array('pmssMediaStackStart', 'pmssMediaStackStartStopped', 'pmssMediaStackSecureApp', 'pmssRunAction') as $function) {
            $this->assertStringContainsString($function, $source);
            $this->assertStringContainsString('function '.$function.'(', $shared);
        }
        foreach (array('mediaStack.php?action=status', 'mediaStack.php?action=', 'mediaStack.php') as $route) {
            $this->assertStringContainsString($route, $shared);
        }
        $this->assertStringContainsString("'endpoint' => 'qbittorrent.php'", $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php'));
        $this->assertStringContainsString("'www/pmssActions.js'", $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
        $this->assertStringContainsString('src="pmssActions.js"', $this->pmssReadRepoFile('etc/skel/www/welcome.php'));
    }
}
