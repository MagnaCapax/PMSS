<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentThrottleGuardTest extends TestCase
{
    /**
     * Ensure the watchdog only applies SCGI throttle when a throttle file exists.
     */
    public function testScgiThrottleCallRequiresConfiguredThrottle(): void
    {
        $script = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertTrue(
            strpos(
                $script,
                '$throttleValue = ($throttle !== null && $throttle > 0) ? $throttle : 0;'
            ) === false,
            'Legacy unconditional throttle assignment should be removed'
        );
        $this->assertMatches(
            '/\$throttle = pmssReadTorrentThrottle\(\$user\);\s*if \(\$throttle !== null\) \{\s*\$throttleValue = \$throttle > 0 \? \$throttle : 0;\s*if \(!rtorrentScgiCallInt\(\$socketPath, \'throttle\.global_up\.max_rate\.set\', \$throttleValue, 5\)\)/s',
            $script,
            'checkRtorrent should guard the SCGI throttle call behind an existing throttle file'
        );
    }

    /**
     * Ensure the healthy path still logs after the optional throttle update.
     */
    public function testHealthyLogRemainsOutsideThrottleGuard(): void
    {
        $script = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertMatches(
            '/if \(\$throttle !== null\) \{.*?\}\s*pmssCheckRtorrentLog\("rTorrent healthy for \{\$user\}", false, \$debug\);/s',
            $script,
            'Healthy watchdog logging should still run when no throttle file exists'
        );
    }
}
