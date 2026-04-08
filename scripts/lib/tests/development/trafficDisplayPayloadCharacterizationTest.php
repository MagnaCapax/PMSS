<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic/processor.php';

final class TrafficDisplayPayloadCharacterizationStub extends \trafficStatistics
{
    use TrafficStatisticsStubTrait;
}

final class TrafficDisplayPayloadCharacterizationTest extends TrafficTestCase
{
    public function testTrafficProcessorPersistsRawAndDailyOnly(): void
    {
        $stats = new TrafficDisplayPayloadCharacterizationStub();
        $paths = $this->makeTrafficPaths('pmss-traffic-characterization-', true);
        $processor = new \TrafficStatsProcessor($stats, $paths);

        $user = 'alice';
        $this->createTrafficUser($paths, $user);
        $now = time();
        $stats->map[$user] = implode("\n", [
            date('Y-m-d H:i:s', $now - 120).': 1048576',
            date('Y-m-d H:i:s', $now - 60).': 2097152',
        ]);

        $processor->processUser($user, \pmssStatsCompareTimesBuild());

        $this->assertEquals(['raw', 'daily'], array_keys($stats->saved[$user]));
    }
}
