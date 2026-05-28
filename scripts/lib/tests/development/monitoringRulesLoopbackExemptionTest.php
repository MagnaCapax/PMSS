<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MonitoringRulesLoopbackExemptionTest extends TestCase
{
    public function testMonitoringRulesExemptLoopbackBeforePerUserRules(): void
    {
        $loopbackRule = 'echo "/sbin/iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT\\n";';
        $userLoop = 'foreach ($userUids as $thisUid) {';

        $this->pmssAssertRepoFileSubstringCount('scripts/util/makeMonitoringRules.php', $loopbackRule, 1, 'Loopback exemption should be declared exactly once');
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/util/makeMonitoringRules.php',
            [$loopbackRule, $userLoop],
            'Expected monitoring rule fragment: ',
            'Loopback exemption must run before per-user accounting rules: '
        );
    }
}
