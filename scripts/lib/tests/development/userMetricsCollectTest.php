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

    public function testV1CollectsFullMetricSet(): void
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

        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v1'], function () use ($root): void {
            $m = \pmssUserMetricsCollect(1000, null, $root);
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
        });
    }

    public function testV1OmitsAbsentSourcesAndReturnsEmptyWhenNothingReadable(): void
    {
        $root = $this->tree([]); // nothing
        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v1'], function () use ($root): void {
            $this->assertEquals([], \pmssUserMetricsCollect(1000, null, $root));
        });

        // Partial tree: only pids present -> only pids key emitted, no garbage.
        $root2 = $this->tree(['pids/user.slice/user-1000.slice/pids.current' => "4\n"]);
        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v1'], function () use ($root2): void {
            $m = \pmssUserMetricsCollect(1000, null, $root2);
            $this->assertEquals(['pids_current' => 4], $m);
            $this->assertTrue(!array_key_exists('mem_current', $m));
            $this->assertTrue(!array_key_exists('io_bytes_read', $m));
        });
    }

    public function testV2CollectsUnifiedMetricsIncludingPsiIoStatAndEvents(): void
    {
        $base = 'user.slice/user-1000.slice';
        $root = $this->tree([
            $base.'/cpu.stat' => "usage_usec 5000\nuser_usec 3000\nsystem_usec 2000\nnr_throttled 4\nthrottled_usec 900\n",
            $base.'/memory.current' => "7000\n",
            $base.'/memory.peak' => "8000\n",
            $base.'/memory.swap.current' => "0\n",
            $base.'/memory.events' => "low 0\nhigh 5\nmax 1\noom 0\noom_kill 0\n",
            $base.'/memory.stat' => "anon 100\nfile 200\npgmajfault 9\nworkingset_refault 3\n",
            $base.'/pids.current' => "12\n",
            $base.'/io.stat' => "8:0 rbytes=1000 wbytes=2000 rios=10 wios=20 dbytes=5 dios=1\n8:16 rbytes=500 wbytes=0 rios=5 wios=0 dbytes=0 dios=0\n",
            $base.'/io.pressure' => "some avg10=4.23 avg60=2.00 avg300=1.00 total=123456\nfull avg10=1.00 avg60=0.50 avg300=0.25 total=654321\n",
            $base.'/cpu.pressure' => "some avg10=0.00 avg60=0.00 avg300=0.00 total=10\n",
            $base.'/memory.pressure' => "some avg10=0.00 avg60=0.00 avg300=0.00 total=20\n",
        ]);

        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v2'], function () use ($root): void {
            $m = \pmssUserMetricsCollect(1000, null, $root);
            $this->assertEquals(5000, $m['cpu_usage_usec']);
            $this->assertEquals(4, $m['cpu_nr_throttled']);
            $this->assertEquals(7000, $m['mem_current']);
            $this->assertEquals(5, $m['mem_events_high']);
            $this->assertEquals(100, $m['mem_anon']);
            $this->assertEquals(9, $m['mem_pgmajfault']);
            $this->assertEquals(3, $m['mem_workingset_refault']);
            // io.stat summed across devices, including discard.
            $this->assertEquals(1500, $m['io_rbytes']);
            $this->assertEquals(2000, $m['io_wbytes']);
            $this->assertEquals(15, $m['io_rios']);
            $this->assertEquals(5, $m['io_dbytes']);
            // PSI percentages stored as ints ×100.
            $this->assertEquals(423, $m['psi_io_some_avg10']);
            $this->assertEquals(123456, $m['psi_io_some_total']);
            $this->assertEquals(25, $m['psi_io_full_avg300']);
        });
    }
}
