<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Renders the customer panel pages as a request would and checks what the browser is told to load for jQuery
 * (GH #674, ADR 0339): the account's own copy first, the CDN only as a load-failure fallback.
 */
class PanelJqueryRenderTest extends TestCase
{
    private const CDN = 'https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js';

    /** The browser's view of the page: every script tag that names jQuery, in document order. */
    private function jquerySources(string $html): array
    {
        // The inline fallback holds its own script tag inside a string; only real tags count.
        $html = str_replace("window.jQuery || document.write('<script src=\"".self::CDN."\"><\\/script>')", '', $html);
        preg_match_all('/<script[^>]*\ssrc="([^"]*jquery[^"]*)"/i', $html, $tags);
        return $tags[1];
    }

    private function fallbackCount(string $html): int
    {
        return substr_count($html, "window.jQuery || document.write('<script src=\"".self::CDN."\"><\\/script>')");
    }

    /** Renders one of the harness pages ($pages: welcome.php, configure.php, apps.php) with or without the delivered asset. */
    private function renderPage(string $page, bool $assetDelivered): string
    {
        return $this->pmssWithCustomerPanelRender(function (string $home, callable $render) use ($page, $assetDelivered): string {
            if ($page === 'apps.php') {
                // Once Configure is delivered apps.php redirects page visits; the legacy page is what an account without it sees.
                foreach (['configure.php', 'configureSettings.php'] as $delivered) {
                    $this->assertTrue(!is_file($home.'/www/'.$delivered) || unlink($home.'/www/'.$delivered));
                }
            }
            $asset = $home.'/www/jquery.min.js';
            if ($assetDelivered) {
                $this->assertTrue(is_file($asset), 'the harness copies the skeleton asset into the account www');
            } else {
                $this->assertTrue(!is_file($asset) || unlink($asset), 'simulate an account the update has not reached yet');
            }
            return $render($page, ['minBytes' => 4000, 'query' => ''])['stdout'];
        });
    }

    /** Welcome, the page every customer lands on, asks the account's own host for jQuery first. */
    public function testWelcomeRequestsTheLocalCopyFirst(): void
    {
        $html = $this->renderPage('welcome.php', true);
        $this->assertSame(['jquery.min.js'], $this->jquerySources($html));
        $this->assertSame(1, $this->fallbackCount($html));
        $this->assertTrue(strpos($html, 'src="jquery.min.js"') < strpos($html, 'document.write'), 'local file is requested before the fallback runs');
    }

    /** Configure, which now holds the app controls, does the same. */
    public function testConfigureRequestsTheLocalCopyFirst(): void
    {
        $html = $this->renderPage('configure.php', true);
        $this->assertSame(['jquery.min.js'], $this->jquerySources($html));
        $this->assertSame(1, $this->fallbackCount($html));
    }

    /** The legacy Apps page, still reachable for the status feed, does the same. */
    public function testAppsRequestsTheLocalCopyFirst(): void
    {
        $html = $this->renderPage('apps.php', true);
        $this->assertSame(['jquery.min.js'], $this->jquerySources($html));
        $this->assertSame(1, $this->fallbackCount($html));
    }

    /** The tab bar page (local frames, the failover when the remote head is unreachable) loads it before pmssTabs.js. */
    public function testLocalFramesIndexLoadsTheLocalCopyBeforeTheTabScript(): void
    {
        $html = $this->pmssRenderCopiedUserPanelIndex([], [], ['jquery.min.js'], ['PMSS_DISABLE_REMOTE_FRAMES' => '1']);
        $this->assertSame(['jquery.min.js'], $this->jquerySources($html));
        $this->assertSame(1, $this->fallbackCount($html));
        $this->assertTrue(strpos($html, 'src="jquery.min.js"') < strpos($html, 'src="pmssTabs.js"'), 'jQuery must exist before the tab plugin runs');
    }

    /** An account whose panel was healed before update.php delivered the asset still gets a working page and keeps the fallback. */
    public function testPageHealedAheadOfItsAssetStillRendersWithTheFallback(): void
    {
        foreach (['welcome.php', 'configure.php', 'apps.php'] as $page) {
            $html = $this->renderPage($page, false);
            $this->assertSame(1, $this->fallbackCount($html), $page.' keeps the CDN fallback when the asset is absent');
            $this->assertSame(['jquery.min.js'], $this->jquerySources($html), $page.' still requests the local path first');
        }
    }

    /** Nothing but the one inline fallback ever names the CDN: no page loads Google with a script tag. */
    public function testRenderedPagesNeverLoadTheCdnWithAScriptTag(): void
    {
        $pages = ['welcome' => $this->renderPage('welcome.php', true), 'configure' => $this->renderPage('configure.php', true),
            'apps' => $this->renderPage('apps.php', true),
            'index' => $this->pmssRenderCopiedUserPanelIndex([], [], ['jquery.min.js'], ['PMSS_DISABLE_REMOTE_FRAMES' => '1'])];
        foreach ($pages as $name => $html) {
            $rest = str_replace("window.jQuery || document.write('<script src=\"".self::CDN."\"><\\/script>')", '', $html);
            $this->assertSame(0, preg_match('/<script[^>]*\ssrc="https:\/\/ajax\.googleapis\.com/i', $rest), $name.' loads the CDN with a script tag');
            $this->assertSame(1, substr_count($html, 'ajax.googleapis.com'), $name.' names the CDN once, in the fallback');
        }
    }

    /** Account boundary: the reference is relative to the account's own www, never an absolute or another account's path. */
    public function testReferenceStaysInsideTheAccountsOwnWww(): void
    {
        foreach (['welcome.php', 'configure.php', 'apps.php'] as $page) {
            foreach ($this->jquerySources($this->renderPage($page, true)) as $src) {
                $this->assertSame(0, preg_match('#^(/|[a-z]+:|\.\./)|/user-#i', $src), $page.' references '.$src);
            }
        }
    }

    /** The served file is what the pages promise: jQuery 1.12.4, byte for byte the copy ruTorrent already ships. */
    public function testServedFileIsTheBundledJquery1124(): void
    {
        $served = $this->pmssReadRepoFile('etc/skel/www/jquery.min.js');
        $this->assertSame($this->pmssReadRepoFile('etc/skel/www/rutorrent/js/jquery.js'), $served);
        $this->assertSame(0, strpos($served, '/*! jQuery v1.12.4 |'));
        $this->assertSame(97163, strlen($served));
    }

    /** The page's own scripts only rely on jQuery 1.x features: the pinned version is unchanged, no migration needed. */
    public function testPagesStillDependOnTheSameJqueryVersion(): void
    {
        foreach (['welcome', 'configure', 'apps', 'index'] as $page) {
            $source = $this->pmssReadRepoFile('etc/skel/www/'.$page.'.php');
            $this->assertSame(0, preg_match('#jquery/(?!1\.12\.4/)[0-9]#', $source), $page.' must not name any other jQuery version');
        }
    }

    /** Out of scope stays out: the Chart.js script and the File Manager libraries are untouched by this change. */
    public function testOutOfScopeCdnAssetsAreUnchanged(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/skel/www/info.php', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js');
        $this->pmssAssertRepoFileContainsString('etc/skel/www/filemanager.php', 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js');
    }
}
