<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/welcomeAnnouncements.php';

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
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss><channel><title>News</title></channel></rss>';
        $this->assertEquals('', \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss));
    }

    public function testSingleItemFeedRendersOneListItem(): void
    {
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss><channel>'
            . '<item><title>Only</title><link>https://example.test/only</link><pubDate>Tue, 17 Mar 2026 10:00:00 +0000</pubDate></item>'
            . '</channel></rss>';

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(1, substr_count($html, '<li>'));
        $this->assertStringContainsString('Only', $html);
    }

    public function testValidFeedRendersOnlyFirstFourItems(): void
    {
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss><channel>'
            . '<item><title>One</title><link>https://example.test/1</link><pubDate>Tue, 17 Mar 2026 10:00:00 +0000</pubDate></item>'
            . '<item><title>Two</title><link>https://example.test/2</link><pubDate>Tue, 17 Mar 2026 11:00:00 +0000</pubDate></item>'
            . '<item><title>Three</title><link>https://example.test/3</link><pubDate>Tue, 17 Mar 2026 12:00:00 +0000</pubDate></item>'
            . '<item><title>Four</title><link>https://example.test/4</link><pubDate>Tue, 17 Mar 2026 13:00:00 +0000</pubDate></item>'
            . '<item><title>Five</title><link>https://example.test/5</link><pubDate>Tue, 17 Mar 2026 14:00:00 +0000</pubDate></item>'
            . '</channel></rss>';

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(4, substr_count($html, '<li>'));
        $this->assertStringContainsString('One', $html);
        $this->assertTrue(strpos($html, 'Five') === false, 'Fifth item must be omitted');
    }

    public function testItemsMissingRequiredFieldsAreSkipped(): void
    {
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss><channel>'
            . '<item><title>Missing Link</title><pubDate>Tue, 17 Mar 2026 10:00:00 +0000</pubDate></item>'
            . '<item><title>Ready</title><link>https://example.test/ok</link><pubDate>Tue, 17 Mar 2026 11:00:00 +0000</pubDate></item>'
            . '</channel></rss>';

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertEquals(1, substr_count($html, '<li>'));
        $this->assertStringContainsString('Ready', $html);
        $this->assertTrue(strpos($html, 'Missing Link') === false, 'Incomplete items must be skipped');
    }

    public function testTitlesAreEscapedBeforeRendering(): void
    {
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss><channel>'
            . '<item><title>&lt;b&gt;bold&lt;/b&gt;</title><link>https://example.test/x</link><pubDate>Tue, 17 Mar 2026 10:00:00 +0000</pubDate></item>'
            . '</channel></rss>';

        $html = \pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rss);

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertTrue(strpos($html, '<b>bold</b>') === false, 'Rendered title must stay escaped');
    }
}
