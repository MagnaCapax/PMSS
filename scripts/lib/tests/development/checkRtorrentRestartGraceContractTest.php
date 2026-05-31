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
                'rtorrentProcessStart($user, $logCallback, $startMarkerState)',
                '$restartAge < 7200',
                '$restartAge < 14400',
                'max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 600)',
                'max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 1200)',
            ]
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/rtorrent/process.php',
            ['function rtorrentProcessStart(', "'/tmp/.pmss-rtorrent-restart-'.\$user"]
        );
    }

    public function testRestartGraceLogicStaysInlineAndLaunchIsShared(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileNotContainsString($path, 'rtorrentProcessLastRestart'.'Age(', 'Restart age helper should stay inlined in checkRtorrent');
        $this->pmssAssertRepoFileNotContainsString($path, 'rtorrentProcessExtended'.'Grace(', 'Extended grace helper should stay inlined in checkRtorrent');
        $this->pmssAssertRepoFileNotContainsString($path, "@passthru('/scripts/startRtorrent ", 'checkRtorrent should delegate direct starts to rtorrentProcessStart()');
    }
}
