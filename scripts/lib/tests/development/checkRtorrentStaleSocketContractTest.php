<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentStaleSocketContractTest extends TestCase
{
    public function testMissingProcessStartPathUsesSharedCleanupBeforeRestart(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertStringContainsString('function pmssCheckRtorrentCleanupStaleSocket(', $src);
        $this->assertMatches(
            '/if \(!\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$unresponsiveState, \$debug\);.*?pmssCheckRtorrentStart\(\$user, \$startMarkerState, \$debug\);/s',
            $src,
            'Missing-process recovery should use the shared stale-socket cleanup before starting rTorrent'
        );
    }

    public function testExecutorMismatchPathUsesSharedCleanupBeforeGraceTracking(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertMatches(
            '/if \(\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$unresponsiveState, \$debug\);.*?rtorrentProcessCheckStaleState\(\$missingState, PMSS_RTORRENT_MISSING_GRACE\);/s',
            $src,
            'Executor mismatch recovery should use the shared stale-socket cleanup before missing-process grace handling'
        );
    }

    public function testUnresponsiveScgiPathUsesSharedCleanupBeforeRestart(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertMatches(
            '/\$responsive = rtorrentScgiPing\(\$socketPath, 5\);.*?\$rtorrentPids = rtorrentProcessPgrepExact\(\$user, \'rtorrent\'\);.*?if \(empty\(\$rtorrentPids\)\) \{.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$unresponsiveState, \$debug\);.*?rTorrent missing after SCGI probe; starting.*?pmssCheckRtorrentStart\(\$user, \$startMarkerState, \$debug\);/s',
            $src,
            'SCGI recovery should reuse the shared cleanup and restart helpers after re-checking process liveness'
        );
    }
}
