<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** The panel serves its own jQuery 1.12.4 and reaches the CDN only as a fallback (GH #674, ADR 0339). */
class PanelJqueryLocalAssetTest extends TestCase
{
    private const CDN = 'https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js';
    private const PAGES = ['welcome', 'configure', 'apps', 'index'];

    /** Like-for-like self-host: the shipped file is jQuery 1.12.4, not a newer library. */
    public function testBundledLibraryIsJquery1124(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/jquery.min.js');
        $this->assertSame(0, strpos($source, '/*! jQuery v1.12.4 |'), 'jquery.min.js must be jQuery v1.12.4');
        $this->assertTrue(strlen($source) > 90000, 'a truncated library must not ship');
    }

    /** The asset must reach existing accounts: it is a per-user manifest entry. */
    public function testLibraryIsDeliveredByTheUserManifest(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'www/jquery.min.js',");
    }

    /** Each page loads the local copy first and names the CDN only inside one load-failure fallback. */
    public function testPagesLoadTheLocalCopyAndKeepTheCdnOnlyAsFallback(): void
    {
        $fallback = "<script>window.jQuery || document.write('<script src=\"".self::CDN."\"><\\/script>')</script>";
        $local = '<script src="jquery.min.js"></script>';
        foreach (self::PAGES as $page) {
            $source = str_replace(' type="text/javascript"', '', $this->pmssReadRepoFile('etc/skel/www/'.$page.'.php'));
            $this->assertSame(1, substr_count($source, $local), $page.' must load the local jQuery once');
            $this->assertSame(1, substr_count($source, $fallback), $page.' must keep exactly one load-failure fallback');
            $this->assertTrue(strpos($source, $local) < strpos($source, $fallback), $page.' must try the local file before the fallback');
            $this->assertSame(1, substr_count($source, 'ajax.googleapis.com/ajax/libs/jquery/1.12.4/'), $page.' must not name the CDN anywhere but its fallback');
        }
    }

    /** With the fallback removed no page may still load the CDN with a plain script tag. */
    public function testNoPageLoadsTheCdnUnconditionally(): void
    {
        $fallback = "<script>window.jQuery || document.write('<script src=\"".self::CDN."\"><\\/script>')</script>";
        foreach (self::PAGES as $page) {
            $source = str_replace($fallback, '', $this->pmssReadRepoFile('etc/skel/www/'.$page.'.php'));
            $this->assertSame(0, preg_match('/<script[^>]*\ssrc="https:\/\/ajax\.googleapis\.com\/ajax\/libs\/jquery\/1\.12\.4\//', $source),
                $page.' must not load jQuery from the CDN with a plain script tag');
        }
    }
}
