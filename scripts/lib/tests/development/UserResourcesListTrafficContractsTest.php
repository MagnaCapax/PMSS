<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserResourcesListTrafficContractsTest extends TestCase
{
    public function testTrafficStatePathsRemainStable(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/resourcesList.php');

        $this->assertStringContainsString('"/home/{$user}/.trafficLimit"', $src);
        $this->assertStringContainsString('"/home/{$user}/.trafficData"', $src);
    }

    public function testTrafficStateReadingDelegatesToSharedHelpers(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/resourcesList.php');

        $this->assertStringContainsString("require_once __DIR__.'/traffic.php';", $src);
        $this->assertStringContainsString("require_once __DIR__.'/trafficLimit.php';", $src);
        $this->assertStringContainsString('pmssTrafficLimitReadGiBFile($trafficLimitPath)', $src);
        $this->assertStringContainsString('pmssReadUserTrafficMonth($trafficDataPath)', $src);
        $this->pmssAssertStringNotContainsString('unserialize(', $src);
        $this->assertStringContainsString('max($diskQuotaGiB * 500, 15000)', $src);
    }
}
