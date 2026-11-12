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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/trackerCleaner/backup.php',
            ["'timeout --kill-after=5s 60 cp -p '.escapeshellarg(\$torrentPath)"]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/trackerCleaner.php',
            ["'trackerCleaner/policy.php'", "'trackerCleaner/log.php'", "'trackerCleaner/backup.php'", "'trackerCleaner/run.php'"],
            ['function pmssTrackerCleanerRunUser(']);
    }
}
