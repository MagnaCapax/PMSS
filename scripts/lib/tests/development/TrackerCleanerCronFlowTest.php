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
            'scripts/lib/trackerCleaner.php' => [
                'required' => ['function pmssTrackerCleanerRunUser(', '\\Devristo\\Torrent\\Torrent::fromFile($torrentPath)', 'torrent_skip reason=parse_error', 'run_end user={$username} processed={$processed} private={$private} changed={$changed}{$runSuffix}'],
            ],
        ]);
    }
}
