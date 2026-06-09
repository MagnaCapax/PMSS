<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentStaleSocketContractTest extends TestCase
{
    public function testMissingProcessStartPathUsesSharedCleanupBeforeRestart(): void
    {
        $path = 'scripts/lib/rtorrent/watchdog.php';
        $this->pmssAssertRepoFileContainsString($path, 'function pmssCheckRtorrentCleanupStaleSocket(');
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkRtorrent.php',
            '/if \(!\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?rtorrentProcessStart\(\$user, \$logCallback, \$state\[\'startMarker\'\]\);/s',
            'Missing-process recovery should use the shared stale-socket cleanup before starting rTorrent'
        );
    }

    public function testExecutorMismatchPathUsesSharedCleanupBeforeGraceTracking(): void
    {
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkRtorrent.php',
            '/if \(\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?rtorrentProcessCheckStaleState\(\$state\[\'missing\'\], PMSS_RTORRENT_MISSING_GRACE\);/s',
            'Executor mismatch recovery should use the shared stale-socket cleanup before missing-process grace handling'
        );
    }

    public function testUnresponsiveScgiPathUsesSharedCleanupBeforeRestart(): void
    {
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkRtorrent.php',
            '/\$responsive = rtorrentScgiCall\(\$socketPath, \'system\.api_version\', \[\], 5\) !== false;.*?\$rtorrentPids = pmssUserWatchdogProcessPids\(\$user, \'\^rtorrent\'\);.*?if \(empty\(\$rtorrentPids\)\) \{.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?rTorrent missing after SCGI probe; starting.*?rtorrentProcessStart\(\$user, \$logCallback, \$state\[\'startMarker\'\]\);/s',
            'SCGI recovery should reuse the shared cleanup and restart helpers after re-checking process liveness'
        );
    }

    public function testStaleScgiPathRefreshesGraceForNonWedgedAliveRtorrent(): void
    {
        $path = 'scripts/lib/rtorrent/process.php';
        $this->pmssAssertRepoFileContainsString(
            $path,
            'SCGI unresponsive but rtorrent still alive (pids='
        );
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkRtorrent.php',
            '/rtorrentProcessScgiUnresponsiveDecision\(.*?\$state\[\'acceptQueueWedge\'\].*?if \(\$decision\[\'action\'\] === \'extend_grace\'\).*?pmssCheckRtorrentExtendUnresponsiveGrace\(\s*\$user,\s*\$decision\[\'message\'\],\s*\$state\[\'unresponsive\'\],\s*\$state\[\'acceptQueueWedge\'\],\s*\$debug\s*\);/s',
            'Stale SCGI recovery should route live-process grace decisions through the shared decision helper'
        );
    }

    public function testStaleScgiPathCanRestartConfirmedAcceptQueueWedge(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/checkRtorrent.php', [
            'PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES',
            'rtorrentProcessStatesForPids($rtorrentPids)',
            'rtorrentScgiSocketQueueSnapshot($socketPath)',
            "if (\$decision['action'] === 'observe_wedge')",
            'rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);',
        ]);
        $this->pmssAssertRepoFileContainsString(
            'scripts/lib/rtorrent/watchdog.php',
            'function pmssCheckRtorrentExtendUnresponsiveGrace('
        );
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/rtorrent/process.php', [
            'rtorrentScgiSocketQueueSaturated($queueSnapshot)',
            'rtorrentProcessCheckFailureCountState($wedgeStateFile, $wedgeCycles)',
            "'action' => 'restart'",
        ]);
    }
}
