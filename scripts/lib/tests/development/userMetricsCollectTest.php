<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/metrics.php';

class UserMetricsCollectTest extends TestCase
{
    private function tree(array $files): string
    {
        $root = $this->pmssMakeTempDir('pmss-metrics-', 0700);
        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $contents);
        }
        return $root;
    }

    public function testCollectsFullV1MetricSet(): void
    {
        $slice = 'user.slice/user-1000.slice';
        $root = $this->tree([
            'cpuacct/'.$slice.'/cpuacct.usage' => "900\n",
            'cpuacct/'.$slice.'/cpuacct.stat' => "user 12\nsystem 8\n",
            'cpu/'.$slice.'/cpu.stat' => "nr_periods 100\nnr_throttled 7\nthrottled_time 4200\n",
            'memory/'.$slice.'/memory.usage_in_bytes' => "5000\n",
            'memory/'.$slice.'/memory.max_usage_in_bytes' => "9000\n",
            'memory/'.$slice.'/memory.failcnt' => "3\n",
            'memory/'.$slice.'/memory.oom_control' => "oom_kill_disable 0\nunder_oom 0\noom_kill 2\n",
            'memory/'.$slice.'/memory.stat' => "cache 4444\nrss 3333\npgmajfault 11\nswap 0\n",
            'pids/'.$slice.'/pids.current' => "21\n",
            'pids/'.$slice.'/pids.events' => "max 5\n",
            'blkio/'.$slice.'/blkio.throttle.io_service_bytes' => "8:0 Read 1000\n8:0 Write 2000\nTotal 3000\n",
            'blkio/'.$slice.'/blkio.throttle.io_serviced' => "8:0 Read 10\n8:0 Write 20\nTotal 30\n",
        ]);

        $m = \pmssUserMetricsCollect(1000, $root);
        $this->assertEquals(900, $m['cpu_usage_nsec']);
        $this->assertEquals(12, $m['cpu_user_ticks']);
        $this->assertEquals(7, $m['cpu_nr_throttled']);
        $this->assertEquals(4200, $m['cpu_throttled_nsec']);
        $this->assertEquals(5000, $m['mem_current']);
        $this->assertEquals(9000, $m['mem_peak']);
        $this->assertEquals(2, $m['mem_oom_kill']);
        $this->assertEquals(3333, $m['mem_rss']);
        $this->assertEquals(4444, $m['mem_cache']);
        $this->assertEquals(11, $m['mem_pgmajfault']);
        $this->assertEquals(21, $m['pids_current']);
        $this->assertEquals(5, $m['pids_events_max']);
        $this->assertEquals(1000, $m['io_bytes_read']);
        $this->assertEquals(2000, $m['io_bytes_write']);
        $this->assertEquals(10, $m['io_ops_read']);
        $this->assertEquals(20, $m['io_ops_write']);
    }

    public function testOmitsAbsentSourcesAndReturnsEmptyWhenNothingReadable(): void
    {
        $this->assertEquals([], \pmssUserMetricsCollect(1000, $this->tree([])));

        // Partial tree: only pids present -> only pids key emitted, no garbage.
        $root2 = $this->tree(['pids/user.slice/user-1000.slice/pids.current' => "4\n"]);
        $m = \pmssUserMetricsCollect(1000, $root2);
        $this->assertEquals(['pids_current' => 4], $m);
        $this->assertTrue(!array_key_exists('mem_current', $m));
        $this->assertTrue(!array_key_exists('io_bytes_read', $m));
    }

    public function testBlkioSumsAcrossDevicesAndIgnoresTotalRows(): void
    {
        $slice = 'user.slice/user-1000.slice';
        $root = $this->tree([
            'blkio/'.$slice.'/blkio.throttle.io_service_bytes' =>
                "8:0 Read 1000\n8:0 Write 2000\n8:16 Read 500\n8:16 Write 250\nTotal 3750\n",
        ]);
        $m = \pmssUserMetricsCollect(1000, $root);
        $this->assertEquals(1500, $m['io_bytes_read']);
        $this->assertEquals(2250, $m['io_bytes_write']);
    }
}
