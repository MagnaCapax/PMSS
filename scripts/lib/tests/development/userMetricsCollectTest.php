<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/metrics.php';
require_once dirname(__DIR__, 2).'/resources/oomStatus.php';

class UserMetricsCollectTest extends TestCase
{
    public function testOomProjectionSeedsThenDatesIncreasesAndResets(): void
    {
        $seed = \pmssOomStatusStateNext([], 4, 100);
        $this->assertSame(['count' => 4, 'last_increase' => null, 'sampled_at' => 100], $seed);
        $same = \pmssOomStatusStateNext($seed, 4, 200);
        $this->assertSame(null, $same['last_increase']);
        $increase = \pmssOomStatusStateNext($same, 5, 300);
        $this->assertSame(300, $increase['last_increase']);
        $this->assertSame(300, \pmssOomStatusStateNext($increase, 5, 400)['last_increase']);
        $this->assertSame(null, \pmssOomStatusStateNext($increase, 0, 500)['last_increase']);
        $this->assertSame(600, \pmssOomStatusStateNext($increase, 2, 600)['last_increase']);
    }

    /** A forged home projection cannot become the collector's previous sample. */
    public function testOomProjectionReadsOnlyRootState(): void
    {
        // Keep the root entrypoint free of reads from the tenant-controlled home.
        $projectorSource = (string) file_get_contents(dirname(__DIR__, 2).'/resources/oomStatus.php');
        foreach (['file_get_contents(', 'fopen(', 'readfile(', 'pmssReadSerializedArrayFile('] as $reader) {
            $this->assertSame(false, strpos($projectorSource, $reader) !== false);
        }
        $root = $this->pmssMakeTempDir('pmss-oom-project-', 0700);
        $homeRoot = $root.'/home';
        $stateRoot = $root.'/state';
        @mkdir($homeRoot.'/www-data', 0700, true);
        @mkdir($stateRoot, 0700);
        $homePath = $homeRoot.'/www-data/.oomKillStatus';
        $this->pmssWriteFile($homePath, serialize(['count' => 1, 'last_increase' => 1, 'sampled_at' => 1]));

        $this->assertTrue(\pmssOomStatusProject('www-data', 4, 100, $homeRoot, $stateRoot));
        $this->assertSame(null, unserialize((string) file_get_contents($homePath))['last_increase']);
        $this->pmssWriteFile($homePath, serialize(['count' => 1000, 'last_increase' => 200, 'sampled_at' => 200]));
        $this->assertTrue(\pmssOomStatusProject('www-data', 5, 300, $homeRoot, $stateRoot));
        $projected = unserialize((string) file_get_contents($homePath));
        $this->assertSame(5, $projected['count']);
        $this->assertSame(300, $projected['last_increase']);
        $this->assertSame(5, json_decode((string) file_get_contents($stateRoot.'/www-data.json'), true)['count']);
    }
    /** Metrics alone must load the counter reader without the persistence writer. */
    public function testMetricsEntrypointLoadsOnlyCounterBoundary(): void
    {
        $loaded = $this->pmssRunRepoInlinePhpRequireJson('scripts/lib/resources/metrics.php',
            'echo json_encode([function_exists("pmssResourceLogReadCountersV1"), function_exists("pmssCounterStateUpdate")]);');
        $this->assertSame([true, false], $loaded);
    }

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
            'blkio/'.$slice.'/blkio.bfq.io_service_bytes' =>
                "8:0 Read 1000\n8:0 Write 2000\n8:16 Read 500\n8:16 Write 250\nTotal 3750\n",
            'blkio/'.$slice.'/blkio.throttle.io_service_bytes' => "8:0 Read 1\n8:0 Write 2\n",
        ]);
        $m = \pmssUserMetricsCollect(1000, $root);
        $this->assertEquals(1500, $m['io_bytes_read']);
        $this->assertEquals(2250, $m['io_bytes_write']);
        $this->assertTrue(!array_key_exists('io_ops_read', $m));
    }
}
