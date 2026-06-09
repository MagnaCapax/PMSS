<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentStaleSocketContractTest extends TestCase
{
    public function testStaleSocketRecoveryUsesSharedCleanupContracts(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/rtorrent/watchdog.php' => [
                'required' => [
                    'function pmssCheckRtorrentCleanupStaleSocket(',
                    'function pmssCheckRtorrentExtendUnresponsiveGrace(',
                ],
            ],
            'scripts/lib/rtorrent/process.php' => [
                'required' => [
                    'SCGI unresponsive but rtorrent still alive (pids=',
                    'rtorrentScgiSocketQueueSaturated($queueSnapshot)',
                    'rtorrentProcessCheckFailureCountState($wedgeStateFile, $wedgeCycles)',
                    "'action' => 'restart'",
                ],
            ],
            'scripts/cron/checkRtorrent.php' => [
                'required' => [
                    'PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES',
                    "if (!pmssDirEnsureExists(\$stateDir, 0755))",
                    "ERROR: failed to create runtime state directory: ",
                    'rtorrentProcessStatesForPids($rtorrentPids)',
                    'rtorrentScgiSocketQueueSnapshot($socketPath)',
                    "if (\$decision['action'] === 'observe_wedge')",
                    'rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);',
                ],
                'matches' => [
                    '/if \(!\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?rtorrentProcessStart\(\$user, \$logCallback, \$state\[\'startMarker\'\]\);/s',
                    '/if \(\$executorPresent && empty\(\$rtorrentPids\)\) \{.*?\$socketPath = rtorrentScgiSocketPath\(\$user\);.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?rtorrentProcessCheckStaleState\(\$state\[\'missing\'\], PMSS_RTORRENT_MISSING_GRACE\);/s',
                    '/\$responsive = rtorrentScgiCall\(\$socketPath, \'system\.api_version\', \[\], 5\) !== false;.*?\$rtorrentPids = pmssUserWatchdogProcessPids\(\$user, \'\^rtorrent\'\);.*?if \(empty\(\$rtorrentPids\)\) \{.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?rTorrent missing after SCGI probe; starting.*?rtorrentProcessStart\(\$user, \$logCallback, \$state\[\'startMarker\'\]\);/s',
                    '/rtorrentProcessScgiUnresponsiveDecision\(.*?\$state\[\'acceptQueueWedge\'\].*?if \(\$decision\[\'action\'\] === \'extend_grace\'\).*?pmssCheckRtorrentExtendUnresponsiveGrace\(\s*\$user,\s*\$decision\[\'message\'\],\s*\$state\[\'unresponsive\'\],\s*\$state\[\'acceptQueueWedge\'\],\s*\$debug\s*\);/s',
                ],
            ],
        ]);
    }
}
