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
                "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
                'rtorrentProcessUnresponsiveGraceState($restartMarker, PMSS_RTORRENT_UNRESPONSIVE_GRACE)',
                '$restartAge = $graceState[\'restartAge\'];',
                '$effectiveGrace = $graceState[\'grace\'];',
            ]
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/rtorrent/process.php',
            [
                'function rtorrentProcessStart(',
                "'/tmp/.pmss-rtorrent-restart-'.\$user",
                '$restartAge < 7200',
                '$restartAge < 14400',
                'max($baseGrace, 600)',
                'max($baseGrace, 1200)',
            ]
        );
    }

    public function testRestartGraceLogicUsesSharedHelperAndLaunchIsShared(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileContainsString('scripts/lib/rtorrent/process.php', 'function rtorrentProcessUnresponsiveGraceState(');
        $this->pmssAssertRepoFileNotContainsString($path, "@passthru('/scripts/startRtorrent ", 'checkRtorrent should delegate direct starts to rtorrentProcessStart()');
    }
}
