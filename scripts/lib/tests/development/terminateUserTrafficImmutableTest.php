<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserTrafficImmutableTest extends TestCase
{
    public function testTerminateUserClearsImmutableTrafficBeforeHomeRemoval(): void
    {
        $src = (string) file_get_contents('scripts/terminateUser.php');
        $posClear = strpos($src, "'clear_immutable_traffic'");
        $posRemove = strpos($src, "'remove_home_initial'");

        $this->assertTrue($posClear !== false, 'terminateUser.php should define a clear_immutable_traffic step');
        $this->assertTrue($posRemove !== false, 'terminateUser.php should define a remove_home_initial step');
        $this->assertTrue($posClear < $posRemove, 'terminateUser.php should clear immutable traffic files before removing the home directory');
        $this->assertStringContainsString('command -v chattr', $src, 'terminateUser.php should guard immutable clearing with a chattr presence check');
        $this->assertStringContainsString('.trafficDataIngressLocal', $src, 'terminateUser.php should include the ingress local traffic data file');
    }
}
