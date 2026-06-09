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

        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/traffic/report.php' => [
                'required' => ['pmssTrafficStatsPath($thisUser, $statsDir)', 'pmssReadSerializedArrayFile($statsPath)', 'pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser)'],
                'forbidden' => ['function '.$showTrafficReader, 'unserialize('],
            ],
            'scripts/lib/resources/show.php' => ['required' => ['pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}")'], 'forbidden' => ['unserialize(']],
            'scripts/cron/trafficLimits.php' => ['required' => ['pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser)'], 'forbidden' => ['function '.$trafficLimitsReader, '@unserialize']],
        ]);
    }
}
