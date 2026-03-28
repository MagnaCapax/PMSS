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
            '/\$responsive = rtorrentScgiPing\(\$socketPath, 5\);.*?\$rtorrentPids = rtorrentProcessPgrepExact\(\$user, \'rtorrent\'\);.*?if \(empty\(\$rtorrentPids\)\) \{.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$unresponsiveState, \$debug\);.*?rTorrent missing after SCGI probe; starting.*?pmssCheckRtorrentStart\(\$user, \$startMarkerState, \$debug\);/s',
            'SCGI recovery should reuse the shared cleanup and restart helpers after re-checking process liveness'
        );
    }
}
