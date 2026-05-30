<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentStaleSocketContractTest extends TestCase
{
    public function testMissingProcessStartPathUsesSharedCleanupBeforeRestart(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileContainsString($path, 'function pmssCheckRtorrentCleanupStaleSocket(');
        $this->pmssAssertRepoFileMatches(
            $path,
            '/if \(!\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$unresponsiveState, \$debug\);.*?pmssCheckRtorrentStart\(\$user, \$startMarkerState, \$debug\);/s',
            'Missing-process recovery should use the shared stale-socket cleanup before starting rTorrent'
        );
    }

    public function testExecutorMismatchPathUsesSharedCleanupBeforeGraceTracking(): void
    {
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkRtorrent.php',
            '/if \(\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$unresponsiveState, \$debug\);.*?rtorrentProcessCheckStaleState\(\$missingState, PMSS_RTORRENT_MISSING_GRACE\);/s',
            'Executor mismatch recovery should use the shared stale-socket cleanup before missing-process grace handling'
        );
    }

    public function testUnresponsiveScgiPathUsesSharedCleanupBeforeRestart(): void
    {
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkRtorrent.php',
            '/\$responsive = rtorrentScgiCall\(\$socketPath, \'system\.api_version\', \[\], 5\) !== false;.*?\$rtorrentPids = rtorrentProcessPgrepExact\(\$user, \'rtorrent\'\);.*?if \(empty\(\$rtorrentPids\)\) \{.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$unresponsiveState, \$debug\);.*?rTorrent missing after SCGI probe; starting.*?pmssCheckRtorrentStart\(\$user, \$startMarkerState, \$debug\);/s',
            'SCGI recovery should reuse the shared cleanup and restart helpers after re-checking process liveness'
        );
    }

    public function testStaleScgiPathRefreshesGraceForNonWedgedAliveRtorrent(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileContainsString(
            $path,
            'SCGI unresponsive but rtorrent still alive (pids='
        );
        $this->pmssAssertRepoFileMatches(
            $path,
            '/\$state = rtorrentProcessCheckStaleState\(\$unresponsiveState, \$effectiveGrace\);.*?\$rtorrentPids = rtorrentProcessPgrepExact\(\$user, \'rtorrent\'\);.*?if \(!empty\(\$rtorrentPids\)\) \{.*?\$queueSnapshot = rtorrentScgiSocketQueueSnapshot\(\$socketPath\);.*?if \(\$queueSnapshot === null \|\| !rtorrentScgiSocketQueueSaturated\(\$queueSnapshot\)\) \{.*?pmssCheckRtorrentExtendUnresponsiveGrace\(\s*\$user,.*?\$unresponsiveState,\s*\$acceptQueueWedgeState,\s*\$debug\s*\);.*?continue;\s*\}/s',
            'Stale SCGI recovery should still extend grace for live rtorrent processes without a saturated accept queue'
        );
    }

    public function testStaleScgiPathCanRestartConfirmedAcceptQueueWedge(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileContainsAllStrings($path, [
            'PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES',
            '$acceptQueueWedgeState',
            'rtorrentProcessStatesForPids($rtorrentPids)',
            'rtorrentProcessStatesHaveUninterruptibleIo($processStates)',
            'rtorrentScgiSocketQueueSnapshot($socketPath)',
            'rtorrentScgiSocketQueueSaturated($queueSnapshot)',
            'rtorrentProcessCheckFailureCountState(',
            'rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);',
        ]);
    }
}
