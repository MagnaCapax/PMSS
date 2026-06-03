<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserResourcesListTrafficContractsTest extends TestCase
{
    public function testTrafficStateReadingDelegatesToSharedHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/resourcesList.php', [
            '"/home/{$user}/.trafficLimit"',
            '"/home/{$user}/.trafficData"',
            "require_once __DIR__.'/traffic.php';",
            "require_once __DIR__.'/trafficLimit.php';",
            'pmssTrafficLimitReadGiBFile($trafficLimitPath)',
            'pmssReadUserTrafficMonth($trafficDataPath)',
            'max($diskQuotaGiB * 500, 15000)',
        ], ['unserialize(']);
    }

    public function testTrafficScriptsUseSharedSerializedPayloadReader(): void
    {
        $showTrafficReader = 'pmssShowTrafficRead'.'StatsPayload';
        $trafficLimitsReader = 'pmssRead'.'TrafficData';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/traffic/report.php', [
            'pmssTrafficStatsPath($thisUser, $statsDir)',
            'pmssReadSerializedArrayFile($statsPath)',
            'pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser)',
        ], ['function '.$showTrafficReader, 'unserialize(']);

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/resources/show.php', ['pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}")'], ['unserialize(']);

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/trafficLimits.php', ['pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser)'], ['function '.$trafficLimitsReader, '@unserialize']);
    }
}
