<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentMissingConfigRecoveryContractTest extends TestCase
{
    public function testMissingConfigBranchRecoversInsteadOfSilentlySkipping(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileContainsString($path, 'function pmssCheckRtorrentRecoverMissingConfig(');
        $this->pmssAssertRepoFileMatches(
            $path,
            '/if \(!is_file\(\$home\.\'\/\\.rtorrent\\.rc\'\)\) \{\s*if \(!pmssCheckRtorrentRecoverMissingConfig\(\$user, \$home, \$debug\)\) \{\s*continue;\s*\}\s*\}/s',
            'checkRtorrent should attempt recovery before skipping users with missing configs'
        );
    }

    public function testRecoveryUsesCanonicalConfigInputsAndLogsOutcome(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/checkRtorrent.php',
            [
                'new UserConfigStore()',
                "applyFallbacks(\$user, is_array(\$payload) ? \$payload : [])",
                "'/etc/seedbox/config/user.rtorrent.defaults.dht'",
                "'/etc/seedbox/config/user.rtorrent.defaults.pex'",
                'pmssReadTorrentThrottle($user)',
                "new rtorrentConfig(\$resources)",
                "pmssCheckRtorrentLogBoth(\$user, 'missing .rtorrent.rc recovered', \$debug);",
            ]
        );
    }
}
