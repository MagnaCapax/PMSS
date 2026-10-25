<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/network/iptables.php';

class MonitoringRulesLoopbackExemptionTest extends TestCase
{
    public function testMonitoringRulesExemptLoopbackBeforePerUserRules(): void
    {
        $loopbackRule = '$rules = "/sbin/iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT\\n";';
        $userLoop = 'foreach ($userUids as $thisUid) {';

        $this->pmssAssertRepoFileSubstringCount('scripts/lib/network/rules.php', $loopbackRule, 1, 'Loopback exemption should be declared exactly once');
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/network/rules.php',
            [$loopbackRule, $userLoop],
            'Expected monitoring rule fragment: ',
            'Loopback exemption must run before per-user accounting rules: '
        );
    }

    public function testMonitoringRulesUseAccountUidsAsMarks(): void
    {
        $rules = \networkMonitoringRulesRender([1001, 2002, 4004], ['10.0.0.0/8']);
        foreach ([1001, 2002, 4004] as $uid) {
            $this->assertStringContainsString("--uid-owner {$uid} -j MARK --set-mark {$uid}", $rules);
        }
        $this->assertStringNotContainsString('--set-mark 1\n', $rules);
    }
}
