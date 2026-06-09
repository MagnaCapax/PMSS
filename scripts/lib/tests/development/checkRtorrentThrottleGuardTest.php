<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentThrottleGuardTest extends TestCase
{
    public function testScgiThrottleCallRequiresConfiguredThrottleAndKeepsHealthyLogOutsideGuard(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/rtorrent/watchdog.php' => [
                'required' => [
                    '$skelHash = @md5_file($skelScript);',
                    '$userHash = @md5_file($userScript);',
                    'executor refresh skipped (checksum unavailable)',
                    "if (!@copy(\$skelScript, \$userScript))",
                    'executor refresh failed (copy error)',
                    "if (!@chown(\$userScript, \$user))",
                    'refreshed stale executor from skel (ownership update failed)',
                ],
                'forbidden' => [
                    'copy($skelScript, $userScript);',
                    '@chown($userScript, $user);',
                    '$throttleValue = ($throttle !== null && $throttle > 0) ? $throttle : 0;',
                ],
                'ordered' => [[
                    'needles' => [
                        '$throttle = pmssReadTorrentThrottle($user);',
                        'if ($throttle === null) return;',
                        '$throttleValue = $throttle > 0 ? $throttle : 0;',
                        "rtorrentScgiCall(\$socketPath, 'throttle.global_up.max_rate.set', [\$throttleValue], 5)",
                    ],
                    'missingPrefix' => 'checkRtorrent throttle guard missing: ',
                ]],
            ],
            'scripts/cron/checkRtorrent.php' => [
                'required' => ["rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])"],
                'matches' => ['/pmssCheckRtorrentApplyThrottle\(\$user, \$socketPath, \$debug\);\s*pmssCheckRtorrentLog\("rTorrent healthy for \{\$user\}", false, \$debug\);/s'],
            ],
        ]);
    }
}
