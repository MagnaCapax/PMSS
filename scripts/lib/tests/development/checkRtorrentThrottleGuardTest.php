<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentThrottleGuardTest extends TestCase
{
    public function testScgiThrottleCallRequiresConfiguredThrottleAndKeepsHealthyLogOutsideGuard(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileNotContainsString(
            $path,
            '$throttleValue = ($throttle !== null && $throttle > 0) ? $throttle : 0;',
            'Legacy unconditional throttle assignment should be removed'
        );
        $this->pmssAssertRepoFileMatches(
            $path,
            '/\$throttle = pmssReadTorrentThrottle\(\$user\);\s*if \(\$throttle !== null\) \{\s*\$throttleValue = \$throttle > 0 \? \$throttle : 0;\s*if \(rtorrentScgiCall\(\$socketPath, \'throttle\.global_up\.max_rate\.set\', \[\$throttleValue\], 5\) === false\)/s',
            'checkRtorrent should guard the SCGI throttle call behind an existing throttle file'
        );
        $this->pmssAssertRepoFileContainsString($path, "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])");
        $this->pmssAssertRepoFileMatches(
            $path,
            '/if \(\$throttle !== null\) \{.*?\}\s*pmssCheckRtorrentLog\("rTorrent healthy for \{\$user\}", false, \$debug\);/s',
            'Healthy watchdog logging should still run when no throttle file exists'
        );
    }
}
