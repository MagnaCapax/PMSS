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

    public function testRunnerLogHelpersKeepCronOutputStable(): void
    {
        $changes = ['abc123' => 'Public Torrent', 'def456' => 'Second Torrent'];
        $this->assertSame("[2025-01-01 00:00:00] Changed Public Torrent (abc123)\n[2025-01-01 00:00:00] Changed Second Torrent (def456)\n", pmssTrackerCleanerChangeLog($changes, '[2025-01-01 00:00:00]'));
        $this->assertSame('tracker cleaner: processed=4 private=1 changed=2', pmssTrackerCleanerUserSummary(4, 1, 2));
        $this->assertSame('tracker cleaner: processed=4 private=1 changed=2 stop_reason=runtime_limit', pmssTrackerCleanerUserSummary(4, 1, 2, 'runtime_limit'));
        foreach ([['runtime_limit', true, true, 'WARN: runtime limit reached; stopping early.'], ['backup_failed', true, false, 'ERR: backup verification failed; stopping early.'], ['modify_limit', true, true, 'WARN: modification limit reached; stopping early.'], ['', false, false, 'SKIP: no eligible torrents processed this run.'], ['', true, false, 'OK: run complete; no tracker changes needed.'], ['', true, true, 'OK: run complete; tracker changes applied.']] as [$stopReason, $processedAny, $changedAny, $expected]) {
            $this->assertSame($expected, pmssTrackerCleanerRunOutcomeLogLine($stopReason, $processedAny, $changedAny));
        }
    }

    public function testCleanedTorrentWriteReplacesSessionFileAndPreservesMode(): void
    {
        $sessionDir = $this->pmssMakeTempDir('pmss-tracker-cleaner-session-');
        $torrentPath = $sessionDir.'/sample.torrent';
        file_put_contents($torrentPath, 'old');
        chmod($torrentPath, 0640);

        $this->assertSame(7, pmssTrackerCleanerWriteCleanedTorrent($torrentPath, 'cleaned', $sessionDir));
        $this->assertSame('cleaned', (string) file_get_contents($torrentPath));
        $this->assertSame(0640, fileperms($torrentPath) & 0777);
    }

    public function testCleanedTorrentWriteRejectsUnsafeTargets(): void
    {
        $sessionDir = $this->pmssMakeTempDir('pmss-tracker-cleaner-session-');
        $outsidePath = $this->pmssMakeTempFile('pmss-tracker-cleaner-outside-');
        file_put_contents($outsidePath, 'outside');
        $linkPath = $sessionDir.'/sample.torrent';
        symlink($outsidePath, $linkPath);

        foreach ([$linkPath, $outsidePath] as $targetPath) {
            $this->assertFalse(pmssTrackerCleanerWriteCleanedTorrent($targetPath, 'cleaned', $sessionDir));
            $this->assertSame('outside', (string) file_get_contents($outsidePath));
        }
    }

    public function testBackupTorrentRejectsUnsafeInputsBeforeShelling(): void
    {
        $root = $this->pmssMakeTempDir('pmss-tracker-cleaner-backups-');
        $backupDir = $root.'/'.date('Y-m-d_Hi');
        $torrentPath = $this->pmssMakeTempDir('pmss-tracker-cleaner-session-').'/sample.torrent';
        file_put_contents($torrentPath, 'torrent payload');

        foreach ([
            'bad_user' => ['bad-user', $torrentPath, $backupDir, $root, 'invalid_username'],
            'bad_source' => ['validusr', $this->pmssMakeTempFile('pmss-tracker-cleaner-source-'), $backupDir, $root, 'torrent_path_unsafe'],
            'bad_destination' => ['validusr', $torrentPath, $this->pmssMakeTempDir('pmss-tracker-cleaner-outside-').'/backup', $root, 'backup_path_unsafe'],
        ] as $label => [$user, $sourcePath, $backupPath, $rootPath, $expectedReason]) {
            [$result, $output] = $this->pmssCaptureStdout(static function () use ($user, $sourcePath, $backupPath, $rootPath): array {
                return pmssTrackerCleanerBackupTorrent($user, $sourcePath, $backupPath, $rootPath, 'tracker');
            });

            $this->assertFalse($result['ok'], $label);
            $this->assertSame('backup_failed', $result['stop_reason'], $label);
            $this->assertStringContainsString('reason='.$expectedReason, $result['verbose_log'], $label);
            $this->assertStringContainsString('ERR:', $output, $label);
        }
    }

    public function testBackupDestinationGuardRejectsTraversalAndPrefixSiblings(): void
    {
        $root = $this->pmssMakeTempDir('pmss-tracker-cleaner-backups-');

        $this->assertTrue(pmssTrackerCleanerBackupDestinationIsSafe($root.'/2026-06-09', $root));
        $this->assertFalse(pmssTrackerCleanerBackupDestinationIsSafe($root.'-sibling/2026-06-09', $root));
        $this->assertFalse(pmssTrackerCleanerBackupDestinationIsSafe($root.'/../escape', $root));
    }
}
