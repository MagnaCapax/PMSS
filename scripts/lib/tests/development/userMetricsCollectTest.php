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
        // Freeze values, integer types, zero retention, and JSON field order together.
        $this->assertSame([
            'cpu_usage_nsec' => 900, 'cpu_user_ticks' => 12, 'cpu_system_ticks' => 8,
            'cpu_nr_periods' => 100, 'cpu_nr_throttled' => 7, 'cpu_throttled_nsec' => 4200,
            'mem_current' => 5000, 'mem_peak' => 9000, 'mem_failcnt' => 3, 'mem_oom_kill' => 2,
            'mem_rss' => 3333, 'mem_cache' => 4444, 'mem_swap' => 0, 'mem_pgmajfault' => 11,
            'pids_current' => 21, 'pids_events_max' => 5,
            'io_bytes_read' => 1000, 'io_bytes_write' => 2000, 'io_ops_read' => 10, 'io_ops_write' => 20,
        ], $m);
    }

    public function testOmitsAbsentSourcesAndReturnsEmptyWhenNothingReadable(): void
    {
        $this->assertEquals([], \pmssUserMetricsCollect(1000, $this->tree([])));

        // Invalid counters stay absent; a valid zero must survive omission filtering.
        $slice = 'user.slice/user-1000.slice/';
        $root2 = $this->tree([
            'cpuacct/'.$slice.'cpuacct.usage' => "-1\n", 'cpu/'.$slice.'cpu.stat' => "nr_periods nope\n",
            'memory/'.$slice.'memory.limit_in_bytes' => "18446744073709551615\n",
            'pids/'.$slice.'pids.current' => "0\n",
        ]);
        $m = \pmssUserMetricsCollect(1000, $root2);
        $this->assertSame(['pids_current' => 0], $m);
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
