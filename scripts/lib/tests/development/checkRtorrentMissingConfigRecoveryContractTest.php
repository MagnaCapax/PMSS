<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentMissingConfigRecoveryContractTest extends TestCase
{
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
}
