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
        $this->assertEquals('', \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw(''));
    }

    public function testMalformedUtf8ReturnsEmptyHtml(): void
    {
        $rss = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss><channel><item><title>bad\x97text</title></item></channel></rss>";
        $this->assertEquals('', \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss));
    }

    public function testMalformedFeedRestoresLibxmlInternalErrorsSetting(): void
    {
        if (!function_exists('libxml_use_internal_errors')) {
            $this->assertTrue(true);
            return;
        }

        $previous = libxml_use_internal_errors(false);
        try {
            \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw('<rss><channel><item>');
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
        $this->assertEquals('', \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss));
    }

    public function testSingleItemFeedRendersOneListItem(): void
    {
        $rss = $this->rssFeed([
            $this->rssItem('Only', 'https://example.test/only', 'Tue, 17 Mar 2026 10:00:00 +0000'),
        ]);

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(1, substr_count($html, '<li'));
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

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(4, substr_count($html, '<li'));
        $this->assertStringContainsString('One', $html);
        $this->assertTrue(strpos($html, 'Five') === false, 'Fifth item must be omitted');
    }

    public function testItemsMissingRequiredFieldsAreSkipped(): void
    {
        $rss = $this->rssFeed([
            '<item><title>Missing Link</title><pubDate>Tue, 17 Mar 2026 10:00:00 +0000</pubDate></item>',
            $this->rssItem('Ready', 'https://example.test/ok', 'Tue, 17 Mar 2026 11:00:00 +0000'),
        ]);

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(1, substr_count($html, '<li'));
        $this->assertStringContainsString('Ready', $html);
        $this->assertTrue(strpos($html, 'Missing Link') === false, 'Incomplete items must be skipped');
    }

    public function testTitlesAreEscapedBeforeRendering(): void
    {
        $rss = $this->rssFeed([
            $this->rssItem('&lt;b&gt;bold&lt;/b&gt;', 'https://example.test/x', 'Tue, 17 Mar 2026 10:00:00 +0000'),
        ]);

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertTrue(strpos($html, '<b>bold</b>') === false, 'Rendered title must stay escaped');
    }

    public function testFeedItemsReadReturnsStructuredRows(): void
    {
        $rss = $this->rssFeed([
            $this->rssCategoryItem('Maintenance window', 'https://example.test/m', 'Tue, 17 Mar 2026 10:00:00 +0000', 'Announcement'),
            $this->rssCategoryItem('Bad date', 'https://example.test/d', 'not a date', 'announcement'),
        ]);

        $this->assertSame(
            [
                ['title' => 'Maintenance window', 'link' => 'https://example.test/m', 'time' => strtotime('Tue, 17 Mar 2026 10:00:00 +0000'), 'category' => 'announcement'],
                ['title' => 'Bad date', 'link' => 'https://example.test/d', 'time' => 0, 'category' => 'announcement'],
            ],
            \pmssWelcomeFeedItemsRead($rss)
        );
    }

    public function testFeedItemsReadFiltersByCategory(): void
    {
        $rss = $this->rssFeed([
            $this->rssCategoryItem('News one', 'https://example.test/n', 'Tue, 17 Mar 2026 10:00:00 +0000', 'announcement'),
            $this->rssCategoryItem('Blog one', 'https://example.test/b', 'Tue, 17 Mar 2026 11:00:00 +0000', ' Article '),
            $this->rssCategoryItem('Wiki one', 'https://example.test/w', 'Tue, 17 Mar 2026 12:00:00 +0000', 'wiki'),
            $this->rssItem('Uncategorised', 'https://example.test/u', 'Tue, 17 Mar 2026 13:00:00 +0000'),
        ]);

        $this->assertSame(['Blog one'], array_column(\pmssWelcomeFeedItemsRead($rss, 'article'), 'title'));
        $this->assertSame(['Wiki one'], array_column(\pmssWelcomeFeedItemsRead($rss, 'wiki'), 'title'));
        $this->assertSame([], \pmssWelcomeFeedItemsRead($rss, 'missing'));
        $this->assertSame(['News one', 'Blog one', 'Wiki one', 'Uncategorised'], array_column(\pmssWelcomeFeedItemsRead($rss), 'title'));
    }

    public function testFeedItemsReadKeepsOnlyHttpLinks(): void
    {
        $rss = $this->rssFeed([
            $this->rssItem('Script', 'javascript:alert(1)', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('Data', 'data:text/html,hi', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('Ftp', 'ftp://example.test/f', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('Relative', '/clients/announcements.php', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('Secure', 'https://example.test/s', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('Plain', 'HTTP://example.test/p', 'Tue, 17 Mar 2026 10:00:00 +0000'),
        ]);

        $this->assertSame(['https://example.test/s', 'HTTP://example.test/p'], array_column(\pmssWelcomeFeedItemsRead($rss), 'link'));
    }

    public function testFeedItemsReadStripsLeadingBrandPrefix(): void
    {
        $rss = $this->rssFeed([
            $this->rssItem('Pulsed Media Seedbox price update', 'https://example.test/1', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('pulsed media  Storage boxes', 'https://example.test/2', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            $this->rssItem('About Pulsed Media', 'https://example.test/3', 'Tue, 17 Mar 2026 10:00:00 +0000'),
            // Never strip a title down to nothing.
            $this->rssItem('Pulsed Media ', 'https://example.test/4', 'Tue, 17 Mar 2026 10:00:00 +0000'),
        ]);

        $this->assertSame(
            ['Seedbox price update', 'Storage boxes', 'About Pulsed Media', 'Pulsed Media '],
            array_column(\pmssWelcomeFeedItemsRead($rss), 'title')
        );
    }

    public function testFeedItemsReadAppliesLimit(): void
    {
        $items = [];
        for ($i = 1; $i <= 25; $i++) {
            $items[] = $this->rssItem('Item '.$i, 'https://example.test/'.$i, 'Tue, 17 Mar 2026 10:00:00 +0000');
        }
        $rss = $this->rssFeed($items);

        $this->assertSame(['Item 1', 'Item 2', 'Item 3'], array_column(\pmssWelcomeFeedItemsRead($rss, '', 3), 'title'));
        $this->assertSame(25, count(\pmssWelcomeFeedItemsRead($rss, '', 40)));
        // Out-of-range limits fall back to the default of 20.
        $this->assertSame(20, count(\pmssWelcomeFeedItemsRead($rss, '', 0)));
        $this->assertSame(20, count(\pmssWelcomeFeedItemsRead($rss, '', 41)));
    }

    public function testFeedItemsReadFailsSoftOnBadInput(): void
    {
        $this->assertSame([], \pmssWelcomeFeedItemsRead(''));
        $this->assertSame([], \pmssWelcomeFeedItemsRead('<rss><channel><item>'));
        $this->assertSame([], \pmssWelcomeFeedItemsRead('not xml at all'));
        $this->assertSame([], \pmssWelcomeFeedItemsRead($this->rssFeed([], '<title>News</title>')));
        $this->assertSame([], \pmssWelcomeFeedItemsRead($this->rssFeed([
            '<item><title>Missing Link</title><pubDate>Tue, 17 Mar 2026 10:00:00 +0000</pubDate></item>',
        ])));
    }

    private function rssCategoryItem(string $title, string $link, string $pubDate, string $category): string
    {
        return '<item><title>'.$title.'</title><link>'.$link.'</link><pubDate>'.$pubDate.'</pubDate><category>'.$category.'</category></item>';
    }

    private function rssFeed(array $items, string $channelPrefix = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss><channel>'.$channelPrefix.implode('', $items).'</channel></rss>';
    }

    private function rssItem(string $title, string $link, string $pubDate): string
    {
        return '<item><title>'.$title.'</title><link>'.$link.'</link><pubDate>'.$pubDate.'</pubDate></item>';
    }
}
