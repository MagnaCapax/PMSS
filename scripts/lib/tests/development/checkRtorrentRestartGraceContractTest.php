<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentRestartGraceContractTest extends TestCase
{
    public function testRestartGraceKeepsStableMarkerAndThresholds(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/checkRtorrent.php',
            [
                "'/tmp/.pmss-rtorrent-restart-'.\$user",
                '$restartAge < 7200',
                '$restartAge < 14400',
                'max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 600)',
                'max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 1200)',
            ]
        );
    }

    public function testRestartGraceLogicStaysInlineInWatchdog(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileNotContainsString($path, 'rtorrentProcessLastRestart'.'Age(', 'Restart age helper should stay inlined in checkRtorrent');
        $this->pmssAssertRepoFileNotContainsString($path, 'rtorrentProcessExtended'.'Grace(', 'Extended grace helper should stay inlined in checkRtorrent');
    }
}
