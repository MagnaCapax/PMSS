<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/traffic.php';

class TrafficStatisticsTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function testParseLineValid(): void
    {
        $ts = new \trafficStatistics();
        $line = date('Y-m-d H:i:s').': 1048576';
        $parsed = $ts->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(1.0, $parsed['data']);
    }

    public function testParseLineRejectsGiganticTransfer(): void
    {
        $ts = new \trafficStatistics();
        $line = date('Y-m-d H:i:s').': '.(150000 * 1024 * 1024 + 1);
        $parsed = $ts->parseLine($line);
        $this->assertTrue($parsed === false);
    }

    public function testParseLineRejectsMalformed(): void
    {
        $ts = new \trafficStatistics();
        $this->assertTrue($ts->parseLine('bad data') === false);
    }

    public function testParseLineRejectsExtraColonParts(): void
    {
        $ts = new \trafficStatistics();
        $line = date('Y-m-d H:i:s').': 1048576: 2097152';
        $this->assertTrue($ts->parseLine($line) === false);
    }

    public function testGetDataClampsNonPositivePeriodsToOneLine(): void
    {
        $paths = $this->makeTrafficPaths('egress');
        $this->pmssWriteFile($paths['traffic_dir'].'/alice', "first\nsecond\n");

        $stats = new \trafficStatistics($paths);
        $this->assertEquals('second', $stats->getData('alice', 0));
    }

    public function testSaveUserTrafficWritesHomeAndRuntimeFilesInEgressMode(): void
    {
        $paths = $this->makeTrafficPaths('egress');
        $this->pmssEnsureDir($paths['home_dir'].'/alice');

        $stats = new \trafficStatistics($paths);
        $payload = [
            'raw' => ['day' => 1.25],
            'display' => ['day' => '1.25MiB'],
            'daily' => ['2026/03/13' => 1.25],
        ];
        $stats->saveUserTraffic('alice', $payload);

        $homePath = $paths['home_dir'].'/alice/.trafficData';
        $runtimePath = $paths['runtime_dir'].'/trafficStats/alice';
        $this->assertTrue(is_file($homePath));
        $this->assertEquals($payload, unserialize((string) file_get_contents($homePath)));
        if (is_file($runtimePath)) {
            $this->assertEquals($payload, unserialize((string) file_get_contents($runtimePath)));
        }
    }

    public function testSaveUserTrafficUsesIngressLocalnetFilename(): void
    {
        $paths = $this->makeTrafficPaths('ingress');
        $this->pmssEnsureDir($paths['home_dir'].'/alice');

        $stats = new \trafficStatistics($paths);
        $payload = [
            'raw' => ['day' => 7.5],
            'display' => ['day' => '7.5MiB'],
            'daily' => ['2026/03/13' => 7.5],
        ];
        $stats->saveUserTraffic('alice-localnet', $payload);

        $homePath = $paths['home_dir'].'/alice/.trafficDataIngressLocal';
        $runtimePath = $paths['runtime_dir'].'/trafficStats/alice-localnet';
        $this->assertTrue(is_file($homePath));
        $this->assertEquals($payload, unserialize((string) file_get_contents($homePath)));
        if (is_file($runtimePath)) {
            $this->assertEquals($payload, unserialize((string) file_get_contents($runtimePath)));
        }
    }

    private function makeTrafficPaths(string $mode): array
    {
        $root = $this->pmssMakeTempDir('pmss-traffic-statistics-');

        return [
            'traffic_dir' => $root.'/traffic',
            'home_dir' => $root.'/home',
            'runtime_dir' => $root.'/run',
            'traffic_mode' => $mode,
        ];
    }
}
