<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
ob_start();
require_once dirname(__DIR__, 4).'/etc/skel/www/apps.php';
ob_end_clean();

/** Contract for the customer-facing, read-only Apps catalog. */
class AppsCatalogTest extends TestCase
{
    /** Lock the closed wiki catalog and its heading-derived anchor set. */
    public function testSelfHostedCatalogHasExpectedRowsAndAnchors(): void
    {
        $catalog = \pmssAppsCatalogRead();
        $expected = array(
            'Media_servers_and_live_TV', 'Comics,_books_and_audiobooks',
            'Downloads_and_requests', 'Library_automation',
            'Library_maintenance_and_transcoding', 'Photos,_files_and_passwords',
            'Dashboards,_chat_and_desktop',
        );
        $urls = \pmssAppsAllowedUrlsRead();
        $this->assertSame(7, count($catalog));
        $names = array();
        $anchors = array();
        foreach ($catalog as $category => $apps) {
            $anchor = substr($urls[$category], strpos($urls[$category], '#') + 1);
            $anchors[] = $anchor;
            foreach ($apps as $app) {
                $names[] = $app[0];
                $this->assertTrue(is_bool($app[1]));
            }
        }
        $this->assertSame($expected, $anchors);
        $this->assertSame(39, count($names));
        $this->assertSame(count($names), count(array_unique($names)));
    }

    /** Installed rows derive labels from the two existing customer catalogs. */
    public function testInstalledRowsUseExistingLabels(): void
    {
        $rows = \pmssAppsInstalledRowsBuild();
        $actual = array_column($rows, 0);
        $expected = array_column(\pmssMediaStackPanelAppDefinitionsRead(), 'label');
        $expected[] = 'Cloudplow';
        foreach (array_keys(\pmssCustomerManagedAppDefinitions()) as $name) {
            $expected[] = $name;
        }
        $expected[] = 'rTorrent and ruTorrent';
        $this->assertSame($expected, $actual);
        $this->assertSame('always on', $rows[count($rows) - 1][1]);
    }

    /** Check rendered links and native-only wording against the closed map. */
    public function testRenderedLinksAndDockerOnlyRows(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('apps.php', [], ['minBytes' => 4000]);
        preg_match_all('/\bhref="([^"]+)"/', $html, $matches);
        $allowed = array_values(\pmssAppsAllowedUrlsRead());
        foreach ($matches[1] as $href) {
            $this->assertTrue(in_array(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $allowed, true), 'Unexpected href: '.$href);
        }
        $this->assertStringContainsString('href="index.php" target="_top">Welcome tab</a>', $html);
        $this->assertStringNotContainsString('href="index.php" target="_blank"', $html);
        $this->assertSame(11, substr_count($html, 'Docker only'));
        $this->assertSame(39, substr_count($html, '>With Docker</a>'));
        $this->assertSame(28, substr_count($html, '>Without Docker</a>'));
    }

    /** The catalog has no active browser or filesystem behavior. */
    public function testAppsSourceHasNoActionsOrOperatorIncludes(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/apps.php');
        foreach (array('<script', '<form', 'exec(', 'shell_exec(', 'file_put_contents(', 'fopen(', '/scripts/') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString("require_once __DIR__.'/scriptsInc.php'", $source);
        $this->assertStringContainsString("require_once __DIR__.'/userMediaStackPanel.php'", $source);
    }
}
