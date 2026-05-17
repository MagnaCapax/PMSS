<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
// Helper relocated from scripts/lib/ to etc/skel/www/ — customer PHP cannot
// traverse /scripts/. See AGENTS.md "INVIOLABLE Customer-Facing PHP Tree
// Separation" rule.
require_once dirname(__DIR__, 4).'/etc/skel/www/welcomeAnnouncements.php';

class WelcomeAnnouncementsTest extends TestCase
{
    public function testEmptyFeedReturnsEmptyHtml(): void
    {
        $this->assertEquals('', $this->welcomeAnnouncementItemsHtmlBuildFromRaw(''));
    }

    public function testMalformedUtf8ReturnsEmptyHtml(): void
    {
        $rss = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss><channel><item><title>bad\x97text</title></item></channel></rss>";
        $this->assertEquals('', $this->welcomeAnnouncementItemsHtmlBuildFromRaw($rss));
    }

    public function testMalformedFeedRestoresLibxmlInternalErrorsSetting(): void
    {
        if (!function_exists('libxml_use_internal_errors')) {
            $this->assertTrue(true);
            return;
        }

        $previous = libxml_use_internal_errors(false);
        try {
            $this->welcomeAnnouncementItemsHtmlBuildFromRaw('<rss><channel><item>');
            $this->assertTrue(libxml_use_internal_errors() === false, 'libxml internal error mode should be restored');
        } finally {
            if (function_exists('libxml_clear_errors')) {
                libxml_clear_errors();
            }

            libxml_use_internal_errors($previous);
        }
    }

    public function testFeedWithoutItemsReturnsEmptyHtml(): void
    {
        $rss = $this->rssFeed([], '<title>News</title>');
        $this->assertEquals('', $this->welcomeAnnouncementItemsHtmlBuildFromRaw($rss));
    }

    public function testSingleItemFeedRendersOneListItem(): void
    {
        $rss = $this->rssFeed([
            $this->rssItem('Only', 'https://example.test/only', 'Tue, 17 Mar 2026 10:00:00 +0000'),
        ]);

        $html = $this->welcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(1, substr_count($html, '<li>'));
        $this->assertStringContainsString('Only', $html);
    }

    public function testValidFeedRendersOnlyFirstFourItems(): void
    {
        $rss = $this->rssFeed([
            $this->rssItem('One', 'https://example.test/1', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('Two', 'https://example.test/2', 'Tue, 17 Mar 2026 11:00:00 +0000'),
            $this->rssItem('Three', 'https://example.test/3', 'Tue, 17 Mar 2026 12:00:00 +0000'),
            $this->rssItem('Four', 'https://example.test/4', 'Tue, 17 Mar 2026 13:00:00 +0000'),
            $this->rssItem('Five', 'https://example.test/5', 'Tue, 17 Mar 2026 14:00:00 +0000'),
        ]);

        $html = $this->welcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(4, substr_count($html, '<li>'));
        $this->assertStringContainsString('One', $html);
        $this->assertTrue(strpos($html, 'Five') === false, 'Fifth item must be omitted');
    }

    public function testItemsMissingRequiredFieldsAreSkipped(): void
    {
        $rss = $this->rssFeed([
            '<item><title>Missing Link</title><pubDate>Tue, 17 Mar 2026 10:00:00 +0000</pubDate></item>',
            $this->rssItem('Ready', 'https://example.test/ok', 'Tue, 17 Mar 2026 11:00:00 +0000'),
        ]);

        $html = $this->welcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(1, substr_count($html, '<li>'));
        $this->assertStringContainsString('Ready', $html);
        $this->assertTrue(strpos($html, 'Missing Link') === false, 'Incomplete items must be skipped');
    }

    public function testTitlesAreEscapedBeforeRendering(): void
    {
        $rss = $this->rssFeed([
            $this->rssItem('&lt;b&gt;bold&lt;/b&gt;', 'https://example.test/x', 'Tue, 17 Mar 2026 10:00:00 +0000'),
        ]);

        $html = $this->welcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertTrue(strpos($html, '<b>bold</b>') === false, 'Rendered title must stay escaped');
    }

    private function rssFeed(array $items, string $channelPrefix = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss><channel>'.$channelPrefix.implode('', $items).'</channel></rss>';
    }

    private function welcomeAnnouncementItemsHtmlBuildFromRaw(string $rssRaw): string
    {
        /** @var callable(string): string $builder */
        $builder = 'pmssWelcomeAnnouncementItemsHtmlBuildFromRaw';
        return $builder($rssRaw);
    }

    private function rssItem(string $title, string $link, string $pubDate): string
    {
        return '<item><title>'.$title.'</title><link>'.$link.'</link><pubDate>'.$pubDate.'</pubDate></item>';
    }
}
