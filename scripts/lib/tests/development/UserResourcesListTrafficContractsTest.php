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

    public function testTrafficScriptsUseSharedSerializedPayloadReader(): void
    {
        $showTraffic = $this->pmssReadRepoFile('scripts/showTraffic.php');
        $showResources = $this->pmssReadRepoFile('scripts/lib/resources/show.php');
        $trafficLimits = $this->pmssReadRepoFile('scripts/cron/trafficLimits.php');
        $showTrafficReader = 'pmssShowTrafficRead'.'StatsPayload';
        $trafficLimitsReader = 'pmssRead'.'TrafficData';

        $this->assertStringContainsString('pmssTrafficStatsPath($thisUser, $statsDir)', $showTraffic);
        $this->assertStringContainsString('pmssReadSerializedArrayFile($statsPath)', $showTraffic);
        $this->assertStringContainsString('pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser)', $showTraffic);
        $this->pmssAssertStringNotContainsString('function '.$showTrafficReader, $showTraffic);
        $this->pmssAssertStringNotContainsString('unserialize(', $showTraffic);

        $this->assertStringContainsString('pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}")', $showResources);
        $this->pmssAssertStringNotContainsString('unserialize(', $showResources);

        $this->assertStringContainsString('pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser)', $trafficLimits);
        $this->pmssAssertStringNotContainsString('function '.$trafficLimitsReader, $trafficLimits);
        $this->pmssAssertStringNotContainsString('@unserialize', $trafficLimits);
    }
}
