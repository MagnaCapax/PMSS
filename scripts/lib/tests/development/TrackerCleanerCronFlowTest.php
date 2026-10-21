<?php
namespace PMSS\Tests;
require_once __DIR__.'/../common/TestCase.php';

final class TrackerCleanerCronFlowTest extends TestCase
{
    public function testCronDelegatesBoundedProcessingToLibrary(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/userTrackerCleaner.php' => [
                'required' => ['pmssTrackerCleanerStopReason($runDeadline, $modifiedCount, $maxModifiedTorrents)', 'pmssTrackerCleanerRunUser(', 'pmssTrackerCleanerRunOutcomeLogLine($stopReason, $anyWork, $anyChanges)'],
                'forbidden' => ['Torrent::fromFile', '$userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_check public=1'],
            ],
            'scripts/lib/trackerCleaner/run.php' => [
                'required' => ['function pmssTrackerCleanerRunUser(', 'pmssReadRegularFileContentsVerified($torrentPath, $accountUid, PMSS_TRACKER_CLEANER_MAX_TORRENT_BYTES)', '\\Devristo\\Torrent\\Torrent::fromString($torrentBytes)', 'torrent_skip reason=unreadable', 'torrent_skip reason=parse_error', 'run_end user={$username} processed={$processed} private={$private} changed={$changed}{$runSuffix}'],
                'forbidden' => ['Torrent::fromFile'],
            ],
            'scripts/lib/trackerCleaner/backup.php' => [
                'required' => ["'timeout 60 cp -p '.escapeshellarg(\$torrentPath)"],
            ],
            'scripts/lib/trackerCleaner.php' => [
                'required' => ["'trackerCleaner/policy.php'", "'trackerCleaner/log.php'", "'trackerCleaner/backup.php'", "'trackerCleaner/run.php'"],
                'forbidden' => ['function pmssTrackerCleanerRunUser('],
            ],
        ]);
    }
}
