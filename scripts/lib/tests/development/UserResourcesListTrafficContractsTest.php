<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserResourcesListTrafficContractsTest extends TestCase
{
    private function loadSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../util/userResourcesList.php');
    }

    public function testTrafficStatePathsRemainStable(): void
    {
        $src = $this->loadSource();

        $this->assertStringContainsString('"/home/{$user}/.trafficLimit"', $src);
        $this->assertStringContainsString('"/home/{$user}/.trafficData"', $src);
    }

    public function testTrafficStateReadingDelegatesToSharedHelpers(): void
    {
        $src = $this->loadSource();

        $this->assertStringContainsString("require_once __DIR__.'/../lib/user/traffic.php';", $src);
        $this->assertStringContainsString("require_once __DIR__.'/../lib/user/trafficLimit.php';", $src);
        $this->assertStringContainsString('pmssTrafficLimitReadGiBFile($trafficLimitPath)', $src);
        $this->assertStringContainsString('pmssReadUserTrafficMonth($trafficDataPath)', $src);
        $this->pmssAssertStringNotContainsString('unserialize(', $src);
        $this->assertStringContainsString('max($diskQuotaGiB * 500, 15000)', $src);
    }
}
