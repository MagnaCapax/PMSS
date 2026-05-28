<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserResourcesListTrafficContractsTest extends TestCase
{
    public function testTrafficStatePathsRemainStable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/resourcesList.php', [
            '"/home/{$user}/.trafficLimit"',
            '"/home/{$user}/.trafficData"',
        ]);
    }

    public function testTrafficStateReadingDelegatesToSharedHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/resourcesList.php', [
            "require_once __DIR__.'/traffic.php';",
            "require_once __DIR__.'/trafficLimit.php';",
            'pmssTrafficLimitReadGiBFile($trafficLimitPath)',
            'pmssReadUserTrafficMonth($trafficDataPath)',
            'max($diskQuotaGiB * 500, 15000)',
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/user/resourcesList.php', 'unserialize(');
    }

    public function testTrafficScriptsUseSharedSerializedPayloadReader(): void
    {
        $showTrafficReader = 'pmssShowTrafficRead'.'StatsPayload';
        $trafficLimitsReader = 'pmssRead'.'TrafficData';

        $this->pmssAssertRepoFileContainsAllStrings('scripts/showTraffic.php', [
            'pmssTrafficStatsPath($thisUser, $statsDir)',
            'pmssReadSerializedArrayFile($statsPath)',
            'pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser)',
        ]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/showTraffic.php', ['function '.$showTrafficReader, 'unserialize(']);

        $this->pmssAssertRepoFileContainsString('scripts/lib/resources/show.php', 'pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}")');
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/resources/show.php', 'unserialize(');

        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLimits.php', 'pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser)');
        $this->pmssAssertRepoFileNotContainsStrings('scripts/cron/trafficLimits.php', ['function '.$trafficLimitsReader, '@unserialize']);
    }
}
