<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/traffic.php';

class TrafficStatisticsTest extends TestCase
{
    /** @var string[] */
    private $tempRoots = [];

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

    public function testSaveUserTrafficWritesHomeAndRuntimeFilesInEgressMode(): void
    {
        $paths = $this->makeTrafficPaths('egress');
        @mkdir($paths['home_dir'].'/alice', 0755, true);

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
        @mkdir($paths['home_dir'].'/alice', 0755, true);

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

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->removeTree($root);
        }
        $this->tempRoots = [];
    }

    private function makeTrafficPaths(string $mode): array
    {
        $root = sys_get_temp_dir().'/pmss-traffic-statistics-'.bin2hex(random_bytes(4));
        $this->tempRoots[] = $root;

        return [
            'traffic_dir' => $root.'/traffic',
            'home_dir' => $root.'/home',
            'runtime_dir' => $root.'/run',
            'traffic_mode' => $mode,
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
                continue;
            }
            @unlink($child);
        }

        @rmdir($path);
    }
}
