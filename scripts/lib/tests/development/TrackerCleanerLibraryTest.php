<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/trackerCleaner.php';

class TrackerCleanerFakeTorrent
{
    public $announceList, $announce, $comment = '';

    public function __construct(array $announceList, string $announce = '')
    {
        $this->announceList = $announceList;
        $this->announce = $announce;
    }
    public function getAnnounceList(): array { return $this->announceList; }
    public function setAnnounceList(array $announceList): void { $this->announceList = $announceList; }
    public function getAnnounce(): string { return $this->announce; }
    public function setAnnounce(string $announce): void { $this->announce = $announce; }
    public function getComment(): string { return $this->comment; }
    public function setComment(string $comment): void { $this->comment = $comment; }
}

class TrackerCleanerLibraryTest extends TestCase
{
    public function testAnnounceListPruningPreservesGoodTiers(): void
    {
        $rules = pmssTrackerCleanerBlockRules();
        $result = pmssTrackerCleanerPruneAnnounceList([
            ['https://good.test/announce', 'udp://tracker.openbittorrent.com:80/announce'],
            ['https://also-good.test/announce'],
        ], $rules);
        $this->assertTrue($result['changed']);
        $this->assertSame([['https://good.test/announce'], ['https://also-good.test/announce']], $result['announce_list']);
        $this->assertSame(['udp://tracker.openbittorrent.com:80/announce'], $result['removed_trackers']);
        $this->assertTrue(pmssTrackerCleanerShouldScrubTracker('https://tracker.coppersurfer.tk/announce', $rules));
        $this->assertFalse(pmssTrackerCleanerShouldScrubTracker('https://good.test/announce', $rules));
    }

    public function testScrubTorrentReplacesBlockedPrimaryAnnounce(): void
    {
        $torrent = new TrackerCleanerFakeTorrent([['udp://tracker.publicbt.com', 'https://good.test/announce']], 'udp://tracker.publicbt.com');
        $result = pmssTrackerCleanerScrubTorrent($torrent, pmssTrackerCleanerBlockRules());
        $this->assertTrue($result['changed']);
        $this->assertFalse($result['would_trackerless']);
        $this->assertSame('https://good.test/announce', $torrent->getAnnounce());
        $this->assertStringContainsString('announce_replaced', implode("\n", $result['events']));
    }

    public function testScrubTorrentReportsTrackerlessResultWithoutReplacement(): void
    {
        $torrent = new TrackerCleanerFakeTorrent([['udp://tracker.publicbt.com']], 'udp://tracker.publicbt.com');
        $result = pmssTrackerCleanerScrubTorrent($torrent, pmssTrackerCleanerBlockRules());
        $this->assertTrue($result['changed']);
        $this->assertTrue($result['would_trackerless']);
        $this->assertSame('udp://tracker.publicbt.com', $torrent->getAnnounce());
        $marked = pmssTrackerCleanerCommentWithMarker('existing');
        $this->assertSame($marked, pmssTrackerCleanerCommentWithMarker($marked));
    }
}
