<?php
namespace PMSS\Tests;
require_once __DIR__.'/../common/TestCase.php';

final class TrackerCleanerCronFlowTest extends TestCase
{
    public function testCronDelegatesBoundedProcessingToLibrary(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/userTrackerCleaner.php',
            ['pmssTrackerCleanerStopReason($runDeadline, $modifiedCount, $maxModifiedTorrents)', 'pmssTrackerCleanerRunUser(', 'pmssTrackerCleanerRunOutcomeLogLine($stopReason, $anyWork, $anyChanges)'],
            ['Torrent::fromFile', '$userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_check public=1']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/trackerCleaner/run.php',
            ['function pmssTrackerCleanerRunUser(', 'pmssReadRegularFileContentsVerified($torrentPath, $accountUid, PMSS_TRACKER_CLEANER_MAX_TORRENT_BYTES)', '\\Devristo\\Torrent\\Torrent::fromString($torrentBytes)', 'torrent_skip reason=unreadable', 'torrent_skip reason=parse_error', 'run_end user={$username} processed={$processed} private={$private} changed={$changed}{$runSuffix}'],
            ['Torrent::fromFile']);
        // rTorrent opt-out marker skips the account, link check first (GH#470, same predicate as checkRtorrent.php).
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/trackerCleaner/run.php',
            ['is_link("/home/{$username}/.rtorrentDisable") || is_file("/home/{$username}/.rtorrentDisable")', "['rtorrent_disabled', '', '']"]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/trackerCleaner/log.php',
            ["'/rTorrent_trackerCleanerJob.log'", "'/.trackerCleaner.log'"],
            ["\$userLogsDir.'/trackerCleaner.log'"]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/trackerCleaner/backup.php',
            ["'timeout --kill-after=5s 60 cp -p '.escapeshellarg(\$torrentPath)"]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/trackerCleaner.php',
            ["'trackerCleaner/policy.php'", "'trackerCleaner/log.php'", "'trackerCleaner/backup.php'", "'trackerCleaner/run.php'"],
            ['function pmssTrackerCleanerRunUser(']);
    }
}
