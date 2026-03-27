<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentStaleSocketContractTest extends TestCase
{
    public function testMissingProcessStartPathClearsStaleSocketBeforeRestart(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertMatches(
            '/if \(!\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?rtorrentProcessClearStaleState\(\$unresponsiveState\);.*?stale socket detected, process not running, cleaning up.*?@unlink\(\$socketPath\);.*?\/scripts\/startRtorrent/s',
            $src,
            'Missing-process recovery should clear stale SCGI socket state before requesting startRtorrent'
        );
    }

    public function testExecutorMismatchPathCleansSocketBeforeGraceTracking(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertMatches(
            '/if \(\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?rtorrentProcessClearStaleState\(\$unresponsiveState\);.*?stale socket detected, process not running, cleaning up.*?@unlink\(\$socketPath\);.*?rtorrentProcessCheckStaleState\(\$missingState, PMSS_RTORRENT_MISSING_GRACE\);/s',
            $src,
            'Executor mismatch recovery should clear stale SCGI socket state before missing-process grace handling'
        );
    }

    public function testUnresponsiveScgiPathRechecksProcessBeforeObserving(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertMatches(
            '/\$responsive = rtorrentScgiPing\(\$socketPath, 5\);.*?\$rtorrentPids = rtorrentProcessPgrepExact\(\$user, \'rtorrent\'\);.*?if \(empty\(\$rtorrentPids\)\) \{.*?rtorrentProcessClearStaleState\(\$unresponsiveState\);.*?stale socket detected, process not running, cleaning up.*?@unlink\(\$socketPath\);.*?rTorrent missing after SCGI probe; starting.*?\/scripts\/startRtorrent/s',
            $src,
            'SCGI recovery should re-check rtorrent liveness and restart instead of entering the unresponsive grace loop'
        );
    }
}
