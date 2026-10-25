<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/dev/appsLsioCatalogBuild.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/appsRuntime.php';

/** Freeze the delivered catalog's complete values, ordering, and public schema. */
class AppsCatalogCharacterizationTest extends TestCase
{
    public function testDeliveredSnapshotRetainsEveryFieldAndItsOrder(): void
    {
        $catalog = require dirname(__DIR__, 4).'/etc/skel/www/appsLsioCatalog.php';
        $this->assertSame(184, count($catalog));
        $this->assertSame('6c7aaefe6d7bbffa2327d30601040332e3fa26506ba364c5239ef9894a21fdf7',
            hash('sha256', serialize($catalog)));
    }

    public function testBuilderRetainsFilteringSortingAndExpandedRows(): void
    {
        $images = [];
        foreach (['zeta', 'alpha', 'jellyfin', 'oscam', 'old', 'unstable', '../bad'] as $name) {
            $images[] = ['name' => $name, 'description' => '[Title '.$name.'] First sentence. More text.',
                'category' => 'Art', 'stable' => $name !== 'unstable', 'deprecated' => $name === 'old'];
        }
        $expected = [];
        foreach (['alpha', 'zeta'] as $name) {
            $expected[] = ['name' => $name, 'title' => 'Title '.$name, 'description' => 'Title '.$name.' First sentence.',
                'category' => 'Art', 'image' => 'lscr.io/linuxserver/'.$name,
                'guide' => 'https://docs.linuxserver.io/images/docker-'.$name.'/'];
        }
        $this->assertSame($expected, \pmssLsioCatalogBuild($images));
        $this->assertSame([], \pmssLsioCatalogBuild([]));
    }

    public function testExportReproducesSnapshotAndLoadsWithoutPanelDependencies(): void
    {
        $catalog = require dirname(__DIR__, 4).'/etc/skel/www/appsLsioCatalog.php';
        $source = \pmssLsioCatalogExport($catalog, '2026-10-14');
        $this->assertSame($this->pmssReadRepoFile('etc/skel/www/appsLsioCatalog.php'), $source);
        $path = $this->pmssMakeTempDir('pmss-catalog-').'/snapshot.php';
        foreach ([$catalog, [], [$catalog[0]]] as $rows) {
            file_put_contents($path, \pmssLsioCatalogExport($rows, '2026-10-14'));
            $this->assertSame($rows, require $path);
        }
    }

    public function testWikiRowsRetainEscapingTagsOrderAndLegacyMarkup(): void
    {
        $catalog = ['Books & media' => [
            ['A < B', true, 'Read & listen', 'no login'],
            ['Second', false, 'Plain', ''],
        ]];
        $expected = '<div class="mrow" data-category="Books &amp; media"><span class="name">A &lt; B<span class="tag">no login</span></span><span class="desc">Read &amp; listen</span><a href="https://example.invalid/?a=1&amp;b=2" target="_blank" rel="noopener">Setup guide &#8599;</a></div>'
            .'<div class="mrow" data-category="Books &amp; media"><span class="name">Second</span><span class="desc">Plain</span><a href="https://example.invalid/?a=1&amp;b=2" target="_blank" rel="noopener">Setup guide &#8599;</a></div>';
        $this->assertSame($expected, \pmssAppsCatalogWikiHtmlBuild($catalog,
            ['Books & media' => 'https://example.invalid/?a=1&b=2']));
        $this->assertSame('', \pmssAppsCatalogWikiHtmlBuild([], []));
    }

    public function testLsioRowsRetainConsecutiveGroupsSearchNamesAndLegacyMarkup(): void
    {
        $rows = [
            ['name' => 'one', 'title' => 'One & two', 'description' => '<text>', 'category' => 'A,B', 'guide' => '/one'],
            ['name' => 'two', 'title' => 'Two', 'description' => 'text', 'category' => 'A,B', 'guide' => '/two'],
            ['name' => 'three', 'title' => 'Three', 'description' => 'text', 'category' => 'A', 'guide' => '/three'],
        ];
        $expected = '<div class="lsio-group"><div class="group">A, B</div><div class="list">'
            .'<div class="mrow" data-category="A, B" data-search="one"><span class="name">One &amp; two</span><span class="desc">&lt;text&gt;</span><a href="/one" target="_blank" rel="noopener">Setup guide &#8599;</a></div>'."\n"
            .'<div class="mrow" data-category="A, B" data-search="two"><span class="name">Two</span><span class="desc">text</span><a href="/two" target="_blank" rel="noopener">Setup guide &#8599;</a></div>'."\n"
            .'</div></div><div class="lsio-group"><div class="group">A</div><div class="list">'
            .'<div class="mrow" data-category="A" data-search="three"><span class="name">Three</span><span class="desc">text</span><a href="/three" target="_blank" rel="noopener">Setup guide &#8599;</a></div>'."\n"
            .'</div></div>';
        $this->assertSame($expected, \pmssAppsCatalogLsioHtmlBuild($rows));
        $this->assertSame('', \pmssAppsCatalogLsioHtmlBuild([]));
    }
}
