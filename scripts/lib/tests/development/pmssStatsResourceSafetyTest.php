<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/pmssStats.php';

/** Malformed stored memory must not interrupt the rest of the terminal report. */
class PmssStatsResourceSafetyTest extends TestCase
{
    private $root;

    public function setUp(): void
    {
        $this->root = $this->pmssMakeTempDir('pmss-stats-resource-');
        $this->pmssWriteFiles($this->root, [
            'home/.trafficData' => serialize(['raw' => ['month' => 12.5]]),
            'home/.trafficDataIngress' => serialize(['raw' => ['month' => 25.0]]),
            'config/users/fixtur01.json' => json_encode(['ramMiB' => 1, 'product' => 'fixture']),
            'version' => "fixture-version\n",
            'cgroup/memory.current' => "256\n",
        ]);
    }

    public function testMalformedContainersRetainCgroupFallbackAndOtherStats(): void
    {
        foreach ([new \stdClass(), (object) ['current' => 512], '', '512', 512, 1.5, false, true, null] as $memory) {
            $stats = $this->collect(['memory' => $memory]);
            $this->assertSame(256.0, $stats['memory']['current_bytes']);
            $this->assertSame(1048576.0, $stats['memory']['limit_bytes']);
            $this->assertSame('fixture', $stats['product']);
            $this->assertSame(12.5, $stats['traffic']['upload_month_mib']);
            $this->assertSame(25.0, $stats['traffic']['download_month_mib']);
            $this->assertTrue($stats['rtorrent']['ok']);
        }
    }

    public function testMalformedContainersRetainNullWhenFallbackIsUnavailable(): void
    {
        unlink($this->root.'/cgroup/memory.current');
        foreach ([new \stdClass(), (object) ['current' => 512], '', 512, false, null] as $memory) {
            $stats = $this->collect(['memory' => $memory]);
            $this->assertSame(null, $stats['memory']['current_bytes']);
            $this->assertSame(null, $stats['memory']['percent']);
            $this->assertSame(12.5, $stats['traffic']['upload_month_mib']);
        }
    }

    public function testNumericCurrentValuesKeepConversionAndPrecedence(): void
    {
        foreach ([0, '0', 512, '512', -1, '-1', 1.5, '1e3', ' 512', INF, -INF] as $current) {
            $stats = $this->collect(['memory' => ['current' => $current]]);
            $this->assertSame((float) $current, $stats['memory']['current_bytes']);
        }
    }

    public function testMissingAndNonnumericCurrentValuesKeepFallback(): void
    {
        $this->assertSame(256.0, $this->collect([])['memory']['current_bytes']);
        $this->assertSame(256.0, $this->collect(['memory' => []])['memory']['current_bytes']);
        foreach ([null, '', false, [], new \stdClass(), 'bad'] as $current) {
            $stats = $this->collect(['memory' => ['current' => $current]]);
            $this->assertSame(256.0, $stats['memory']['current_bytes']);
        }
    }

    /** Use fixture paths and a local RPC callback; persisted bytes must survive every read. */
    private function collect(array $resource): array
    {
        $serialized = serialize($resource + ['fixture' => 'preserved']);
        $path = $this->root.'/home/.resourceData';
        file_put_contents($path, $serialized);
        $stats = \pmssStatsCollect([
            'user' => 'fixtur01', 'home' => $this->root.'/home',
            'config_dir' => $this->root.'/config', 'cgroup_dir' => $this->root.'/cgroup',
            'version_file' => $this->root.'/version', 'socket_path' => $this->root.'/unused.socket',
        ], static function (string $socket, string $method) {
            return $method === 'd.multicall2' ? [] : 0;
        });
        $this->assertSame($serialized, file_get_contents($path));
        $this->assertSame($serialized, serialize($stats['resource']));
        return $stats;
    }
}
