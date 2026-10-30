<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentContractTest extends TestCase
{
    public function testSharedListUsersParserAndLaunchContractsRemain(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/checkRtorrent.php' => [
                'required' => [
                    "pmssListManagedUsersResult('/scripts/listUsers.php')",
                    "require_once __DIR__.'/../lib/rtorrent/watchdog.php';",
                    'pmssCheckRtorrentHandleMissingProcess(',
                ],
                'forbidden' => ["@exec('/scripts/listUsers.php'", '/^[a-z][a-z0-9]{0,7}$/'],
            ],
            'scripts/lib/rtorrent/watchdog.php' => [
                'required' => ['function pmssCheckRtorrentCleanupStaleSocket('],
            ],
            'scripts/lib/rtorrent/watchdogMissing.php' => [
                'required' => ["rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])"],
            ],
        ]);
    }

    public function testMissingConfigBranchRecoversInsteadOfSilentlySkipping(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/rtorrent/watchdog.php' => [
                'required' => ['function pmssCheckRtorrentRecoverMissingConfig('],
            ],
            'scripts/cron/checkRtorrent.php' => [
                'matches' => ['/if \(!is_file\(\$home\.\'\/\\.rtorrent\\.rc\'\)\) \{\s*if \(!pmssCheckRtorrentRecoverMissingConfig\(\$user, \$home, \$debug\)\) \{\s*continue;\s*\}\s*\}/s'],
            ],
        ]);
    }

    public function testRecoveryUsesCanonicalConfigInputsAndLogsOutcome(): void
    {
        $this->pmssAssertRepoFileContract('scripts/lib/rtorrent/watchdog.php', [
            'required' => [
                'new UserConfigStore()',
                "applyFallbacks(\$user, is_array(\$payload = \$userConfigStore->get(\$user)) ? \$payload : [])",
                "'/etc/seedbox/config/user.rtorrent.defaults.dht'",
                "'/etc/seedbox/config/user.rtorrent.defaults.pex'",
                'pmssReadTorrentThrottle($user)',
                "new rtorrentConfig(\$resources)",
                "pmssCheckRtorrentLogBoth(\$user, 'missing .rtorrent.rc recovered', \$debug);",
            ],
        ]);
    }

    public function testStaleSocketRecoveryUsesSharedCleanupContracts(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/rtorrent/watchdog.php' => [
                'required' => [
                    'function pmssCheckRtorrentCleanupStaleSocket(',
                ],
            ],
            'scripts/lib/rtorrent/watchdogMissing.php' => [
                'matches' => [
                    '/pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?if \(!\$executorPresent\).*?rtorrentProcessStart\(\$user, \$logCallback, \$state\[\'startMarker\'\]\);/s',
                ],
            ],
            'scripts/cron/checkRtorrent.php' => [
                'required' => [
                    'PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES',
                    "if (!pmssDirEnsureExists(\$stateDir, 0755))",
                    "ERROR: failed to create runtime state directory: ",
                    'pmssCheckRtorrentHandleMissingProcess(',
                    'pmssCheckRtorrentHandleAliveProcess(',
                ],
            ],
            'scripts/lib/rtorrent/watchdogScgi.php' => [
                'required' => [
                    'SCGI unresponsive but rtorrent still alive (pids=',
                    'rtorrentScgiSocketQueueSaturated($queueSnapshot)',
                    'rtorrentProcessCheckFailureCountState($wedgeStateFile, $wedgeCycles)',
                    "'action' => 'restart'",
                    'rtorrentProcessStatesForPids($rtorrentPids)',
                    'rtorrentScgiSocketQueueSnapshot($socketPath)',
                    "\$decision['action'] === 'extend_grace' || \$decision['action'] === 'observe_wedge'",
                    'rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);',
                ],
                'matches' => [
                    '/rtorrentScgiCall\(\$socketPath, \'system\.api_version\', \[\], 5\) !== false.*?pmssCheckRtorrentApplyThrottle\(\$user, \$socketPath, \$debug\);/s',
                    '/if \(empty\(\$rtorrentPids\)\) \{.*?pmssCheckRtorrentCleanupStaleSocket\(\$user, \$socketPath, \$state\[\'unresponsive\'\], \$debug\);.*?rtorrentProcessStart\(\$user, \$logCallback, \$state\[\'startMarker\'\]\);/s',
                    '/rtorrentProcessWriteStateFile\(\$state\[\'unresponsive\'\], \(string\) time\(\)\);\s*if \(\$decision\[\'action\'\] === \'extend_grace\'\) rtorrentProcessClearStaleState\(\$state\[\'acceptQueueWedge\'\]\);\s*pmssCheckRtorrentLogBoth\(\$user, \$decision\[\'message\'\], \$debug\);/s',
                ],
            ],
        ]);
    }

    public function testScgiThrottleCallRequiresConfiguredThrottleAndKeepsHealthyLogOutsideGuard(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/rtorrent/watchdog.php' => [
                'required' => [
                    '$userContent = pmssReadRegularFileContentsVerified($userScript, null, 1048576, $userMeta);',
                    'executor refresh skipped (checksum unavailable)',
                    'pmssReplaceAccountFile($user, $home, $userScript, $skelContent, (int) $userMeta[\'mode\'] & 0777, true)',
                    'executor refresh failed (copy error)',
                ],
                'forbidden' => [
                    '@md5_file($userScript)',
                    '@fileperms($userScript)',
                    'pmssWriteUserFile($userScript',
                    'copy($skelScript, $userScript);',
                    '@chown($userScript, $user);',
                    '$throttleValue = ($throttle !== null && $throttle > 0) ? $throttle : 0;',
                ],
                'ordered' => [[
                    'needles' => [
                        '$throttle = pmssReadTorrentThrottle($user);',
                        'if ($throttle === null) return;',
                        '$throttleValue = $throttle > 0 ? $throttle : 0;',
                        "rtorrentScgiCall(\$socketPath, 'throttle.global_up.max_rate.set_kb', ['', \$throttleValue], 5)",
                    ],
                    'missingPrefix' => 'checkRtorrent throttle guard missing: ',
                ]],
            ],
            'scripts/lib/rtorrent/watchdogScgi.php' => [
                'matches' => ['/pmssCheckRtorrentApplyThrottle\(\$user, \$socketPath, \$debug\);\s*pmssCheckRtorrentLog\("rTorrent healthy for \{\$user\}", false, \$debug\);/s'],
            ],
        ]);
    }

    public function testRtorrentDisableMarkerSkipsUserAndStaysStopped(): void
    {
        // GH#470 per-user opt-out: `.rtorrentDisable` marker => stop and stay stopped.
        // The disable check must sit AFTER the suspended-user block (a disabled user is not
        // suspended) and BEFORE the .rtorrent.rc / start logic, and it must `continue`.
        $this->pmssAssertRepoFileContract('scripts/cron/checkRtorrent.php', [
            'required' => [
                "if (is_link(\$home.'/.rtorrentDisable') || is_file(\$home.'/.rtorrentDisable')) {",
                'rtorrent disabled by user (.rtorrentDisable)',
            ],
            'ordered' => [[
                'needles' => [
                    'if (pmssUserServicesParked($user)) {',
                    "if (is_link(\$home.'/.rtorrentDisable') || is_file(\$home.'/.rtorrentDisable')) {",
                    "if (!is_file(\$home.'/.rtorrent.rc')) {",
                ],
                'missingPrefix' => 'checkRtorrent .rtorrentDisable skip misordered: ',
            ]],
        ]);
    }
}
