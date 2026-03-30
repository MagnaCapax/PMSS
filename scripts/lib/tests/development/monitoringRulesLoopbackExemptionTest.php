<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MonitoringRulesLoopbackExemptionTest extends TestCase
{
    public function testMonitoringRulesExemptLoopbackBeforePerUserRules(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../util/makeMonitoringRules.php');
        $this->assertTrue($src !== '', 'Expected to read makeMonitoringRules.php');

        $loopbackRule = 'echo "/sbin/iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT\\n";';
        $userLoop = 'foreach ($users as $thisUser) {';

        $this->assertSame(1, substr_count($src, $loopbackRule), 'Loopback exemption should be declared exactly once');

        $loopbackPosition = strpos($src, $loopbackRule);
        $userLoopPosition = strpos($src, $userLoop);

        $this->assertTrue($loopbackPosition !== false, 'Loopback exemption rule should exist');
        $this->assertTrue($userLoopPosition !== false, 'Expected to find the per-user rule loop');
        $this->assertTrue($loopbackPosition < $userLoopPosition, 'Loopback exemption must run before per-user accounting rules');
    }
}
