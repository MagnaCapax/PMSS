<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentRestartGraceContractTest extends TestCase
{
    public function testRestartGraceKeepsSharedHelperLaunchAndMarkers(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/checkRtorrent.php' => [
                'required' => [
                    "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
                    'rtorrentProcessUnresponsiveGraceState($restartMarker, PMSS_RTORRENT_UNRESPONSIVE_GRACE)',
                    '$restartAge = $graceState[\'restartAge\'];',
                    '$effectiveGrace = $graceState[\'grace\'];',
                ],
                'forbidden' => ["@passthru('/scripts/startRtorrent "],
            ],
            'scripts/lib/rtorrent/process.php' => ['required' => [
                "require_once __DIR__.'/watchdogState.php';",
                'function rtorrentProcessStart(',
                "'/tmp/.pmss-rtorrent-restart-'.\$user",
            ]],
            'scripts/lib/rtorrent/watchdogState.php' => ['required' => [
                'function rtorrentProcessUnresponsiveGraceState(',
                '$restartAge < 7200',
                '$restartAge < 14400',
                'max($baseGrace, 600)',
                'max($baseGrace, 1200)',
            ]],
        ]);
    }
}
