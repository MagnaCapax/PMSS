<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TrafficTestCase.php';
require_once dirname(__DIR__, 2).'/traffic.php';

class TrafficStatisticsTest extends TrafficTestCase
{
    public function testParseLineValid(): void
    {
        $ts = new \trafficStatistics();
        $line = date('Y-m-d H:i:s').': 1048576';
        $parsed = $ts->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(1.0, $parsed['data']);
    }

    public function testParseLineRejectsInvalidSamples(): void
    {
        $ts = new \trafficStatistics();
        foreach ([
            date('Y-m-d H:i:s').': '.(150000 * 1024 * 1024 + 1),
            'bad data',
            date('Y-m-d H:i:s').': 1048576: 2097152',
            'not-a-time: 1048576',
        ] as $line) {
            $this->assertTrue($ts->parseLine($line) === false);
        }
    }

    public function testGetDataClampsNonPositivePeriodsToOneLine(): void
    {
        $paths = $this->makeTrafficPaths('pmss-traffic-statistics-', false, ['traffic_mode' => 'egress']);
        $this->pmssWriteFile($paths['traffic_dir'].'/alice', "first\nsecond\n");

        $stats = new \trafficStatistics($paths);
        $this->assertEquals('second', $stats->getData('alice', 0));
    }

    public function testGetDataRejectsTraversalUserKeys(): void
    {
        $paths = $this->makeTrafficPaths('pmss-traffic-statistics-', false, ['traffic_mode' => 'egress']);
        $this->pmssWriteFile($paths['root'].'/outside', "should-not-read\n");

        $stats = new \trafficStatistics($paths);

        $this->assertSame('', $stats->getData('../outside', 1));
    }

    public function testSaveUserTrafficPersistsExpectedFilesAcrossModes(): void
    {
        foreach ([
            ['mode' => 'egress', 'user' => 'alice', 'payload' => $this->makeTrafficPayload(['day' => 1.25], ['day' => '1.25MiB'], ['2026/03/13' => 1.25])],
            ['mode' => 'ingress', 'user' => 'alice-localnet', 'payload' => $this->makeTrafficPayload(['day' => 7.5], ['day' => '7.5MiB'], ['2026/03/13' => 7.5])],
        ] as $case) {
            $paths = $this->makeTrafficPaths('pmss-traffic-statistics-', false, ['traffic_mode' => $case['mode']]);
            $this->createTrafficUser($paths, 'alice', false);
            $user = $case['mode'] === 'ingress' ? $this->markTrafficUserLocalnet($paths, 'alice') : $case['user'];

            $stats = new \trafficStatistics($paths);
            $stats->saveUserTraffic($user, $case['payload']);
            $this->assertTrafficPayloadPersistence($paths, $user, $case['payload'], $case['mode']);
        }
    }
}
