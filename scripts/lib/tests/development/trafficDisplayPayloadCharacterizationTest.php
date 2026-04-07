<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic/processor.php';

final class TrafficDisplayPayloadCharacterizationStub extends \trafficStatistics
{
    /** @var array<string, string> */
    public $map = [];
    /** @var array<string, array<string, mixed>> */
    public $saved = [];

    public function getData($user, $timePeriod = 5050)
    {
        return $this->map[$user] ?? '';
    }

    public function saveUserTraffic($user, $data)
    {
        $this->saved[$user] = $data;
    }
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
