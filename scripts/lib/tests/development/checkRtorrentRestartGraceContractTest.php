<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentRestartGraceContractTest extends TestCase
{
    public function testRestartGraceKeepsSharedHelperLaunchAndMarkers(): void
    {
        $watchdog = 'scripts/cron/checkRtorrent.php';
        $process = 'scripts/lib/rtorrent/process.php';
        $watchdogState = 'scripts/lib/rtorrent/watchdogState.php';

        $this->pmssAssertRepoFileContainsAllStrings(
            $watchdog,
            [
                "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
                'rtorrentProcessUnresponsiveGraceState($restartMarker, PMSS_RTORRENT_UNRESPONSIVE_GRACE)',
                '$restartAge = $graceState[\'restartAge\'];',
                '$effectiveGrace = $graceState[\'grace\'];',
            ]
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            $process,
            [
                "require_once __DIR__.'/watchdogState.php';",
                'function rtorrentProcessStart(',
                "'/tmp/.pmss-rtorrent-restart-'.\$user",
            ]
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            $watchdogState,
            [
                'function rtorrentProcessUnresponsiveGraceState(',
                '$restartAge < 7200',
                '$restartAge < 14400',
                'max($baseGrace, 600)',
                'max($baseGrace, 1200)',
            ]
        );
        $this->pmssAssertRepoFileNotContainsString($watchdog, "@passthru('/scripts/startRtorrent ", 'checkRtorrent should delegate direct starts to rtorrentProcessStart()');
    }
}
