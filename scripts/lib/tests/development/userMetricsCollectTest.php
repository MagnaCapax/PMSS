<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/metrics.php';
require_once dirname(__DIR__, 2).'/resources/oomStatus.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/webCgroupMemoryStatus.php';

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
        $reset = \pmssOomStatusStateNext($increase, 2, 600);
        $this->assertSame(['count' => 2, 'last_increase' => null, 'sampled_at' => 600], $reset);
        $this->assertSame(null, \pmssOomStatusStateNext($reset, 2, 700)['last_increase']);
        $resumed = \pmssOomStatusStateNext($reset, 3, 800);
        $this->assertSame(800, $resumed['last_increase']);
        $this->assertSame(false, \pmssWebCgroupMemoryStatusOomRecent(2, $reset, 600));
        $this->assertSame(true, \pmssWebCgroupMemoryStatusOomRecent(3, $resumed, 800));
    }

    /** Both read-only boundaries must include disjoint session/run counters at every depth. */
    public function testOomReadersSumAccountDescendants(): void
    {
        $cases = [
            [[
                'memory.oom_control' => "oom_kill 2\n",
                'user@1000.service/memory.oom_control' => "oom_kill 3\n",
                'user@1000.service/app.slice/run-nested.scope/memory.oom_control' => "oom_kill 5\n",
                'session-1.scope/memory.oom_control' => "oom_kill 7\n",
                'run-1.scope/memory.oom_control' => "oom_kill 11\n",
            ], 28, 28],
            [['session-1.scope/memory.oom_control' => "oom_kill 4\n"], 4, 4],
            [[
                'memory.oom_control' => "oom_kill invalid\n",
                'session-1.scope/memory.oom_control' => "oom_kill -2\n",
                'session-1.scope/nested/memory.oom_control' => "oom_kill 4\n",
            ], 4, 4],
            [[], null, 0],
            [['memory.oom_control' => "oom_kill 0\n"], 0, 0],
            [[
                'memory.oom_control' => 'oom_kill '.(PHP_INT_MAX - 1)."\n",
                'session-1.scope/memory.oom_control' => "oom_kill 2\n",
            ], null, PHP_INT_MAX],
        ];
        foreach ($cases as [$files, $rootCount, $panelCount]) {
            $slice = $this->tree($files);
            $this->assertSame($rootCount, \pmssOomStatusV1CountRead($slice));
            $this->assertSame($panelCount, \pmssWebCgroupMemoryStatusV1OomKillRead($slice, 1000));
        }
        $this->assertSame(null, \pmssOomStatusV1CountRead($slice.'/missing'));
        $this->assertSame(0, \pmssWebCgroupMemoryStatusV1OomKillRead('', 1000));
        // Preserve the existing UID gate: invalid identities permit only the parent read.
        $slice = $this->tree(['memory.oom_control' => "oom_kill 2\n", 'session-1.scope/memory.oom_control' => "oom_kill 3\n"]);
        foreach ([null, -1, '1000'] as $uid) {
            $this->assertSame(2, \pmssWebCgroupMemoryStatusV1OomKillRead($slice, $uid));
        }
    }

    /** A sibling account or symlink cycle must never become part of the account total. */
    public function testOomReadersSkipSymlinkedScopesAndCounters(): void
    {
        $slice = $this->tree(['session-1.scope/memory.oom_control' => "oom_kill 3\n"]);
        $outside = $this->tree(['memory.oom_control' => "oom_kill 99\n"]);
        $this->assertTrue(symlink($outside, $slice.'/other.scope'));
        $this->assertTrue(symlink($slice, $slice.'/session-1.scope/loop'));
        $this->assertTrue(symlink($outside.'/memory.oom_control', $slice.'/memory.oom_control'));
        $this->assertSame(3, \pmssOomStatusV1CountRead($slice));
        $this->assertSame(3, \pmssWebCgroupMemoryStatusV1OomKillRead($slice, 1000));
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

    /** Lock both consumers' schemas, ordering, zeroes, and distinct memory policies. */
    public function testV1ConsumersKeepTheirOwnProjections(): void
    {
        $slice = 'user.slice/user-1234.slice/';
        $root = $this->tree([
            'cpuacct/'.$slice.'cpuacct.usage' => "900\n",
            'cpuacct/'.$slice.'cpuacct.stat' => "system 8\nuser 12\n",
            'cpu/'.$slice.'cpu.stat' => "throttled_time 42\nnr_throttled 0\nnr_periods 7\n",
            'memory/'.$slice.'memory.usage_in_bytes' => "5000\n",
            'memory/'.$slice.'memory.max_usage_in_bytes' => "6000\n",
            'memory/'.$slice.'memory.limit_in_bytes' => "7000\n",
            'memory/'.$slice.'memory.failcnt' => "0\n",
            'memory/'.$slice.'memory.memsw.usage_in_bytes' => "8000\n",
            'memory/'.$slice.'memory.oom_control' => "oom_kill 2\n",
            'memory/'.$slice.'memory.stat' => "total_rss 300\ntotal_cache 400\nrss 0\ncache 10\nswap 0\n",
            'pids/'.$slice.'pids.current' => "21\n",
            'pids/'.$slice.'pids.events' => "max 0\n",
            'blkio/'.$slice.'blkio.bfq.io_service_bytes' => "8:0 Read 100\n8:0 Write 200\n",
            'blkio/'.$slice.'blkio.bfq.io_serviced' => "8:0 Read 1\n8:0 Write 2\n",
        ]);
        $this->assertSame([
            'cpu_nsec' => 900, 'memory' => 5000, 'tasks' => 21,
            'io_read' => 100, 'io_write' => 200, 'io_read_ops' => 1, 'io_write_ops' => 2, 'io_source' => 'bfq',
            'memory_anon' => 300, 'memory_file' => 400,
        ], \pmssResourceLogReadCountersV1(1234, $root.'/'));
        $this->assertSame([
            'cpu_usage_nsec' => 900, 'cpu_user_ticks' => 12, 'cpu_system_ticks' => 8,
            'cpu_nr_periods' => 7, 'cpu_nr_throttled' => 0, 'cpu_throttled_nsec' => 42,
            'mem_current' => 5000, 'mem_peak' => 6000, 'mem_limit' => 7000, 'mem_failcnt' => 0,
            'memsw_current' => 8000, 'mem_oom_kill' => 2, 'mem_rss' => 0, 'mem_cache' => 10, 'mem_swap' => 0,
            'pids_current' => 21, 'pids_events_max' => 0,
            'io_bytes_read' => 100, 'io_bytes_write' => 200, 'io_ops_read' => 1, 'io_ops_write' => 2,
        ], \pmssUserMetricsCollect(1234, $root));
    }

    public function testV1ConsumersRetainFirstNumericRowAndSentinelOmission(): void
    {
        $slice = 'user.slice/user-1000.slice/';
        $root = $this->tree([
            'cpuacct/'.$slice.'cpuacct.usage' => "18446744073709551615\n",
            'cpuacct/'.$slice.'cpuacct.stat' => "user invalid\nuser 12\nuser 99\nsystem 0\n",
            'memory/'.$slice.'memory.stat' => "total_rss 0\nrss 9\ncache 9223372036854775807\ncache 5\nswap 0\n",
        ]);
        $this->assertSame(['memory_anon' => 0], \pmssResourceLogReadCountersV1(1000, $root));
        $this->assertSame(['cpu_user_ticks' => 12, 'cpu_system_ticks' => 0, 'mem_rss' => 9, 'mem_swap' => 0],
            \pmssUserMetricsCollect(1000, $root));
    }

    public function testV1ConsumersKeepDistinctPartialIoRules(): void
    {
        $slice = 'blkio/user.slice/user-1000.slice/';
        $root = $this->tree([
            $slice.'blkio.bfq.io_service_bytes' => "8:0 Read 100\n8:0 Write 0\n",
            // A different family's ops cannot complete a BFQ billing sample.
            $slice.'blkio.throttle.io_serviced' => "8:0 Read 99\n8:0 Write 99\n",
        ]);
        $this->assertSame(null, \pmssResourceLogReadCountersV1(1000, $root));
        $this->assertSame(['io_bytes_read' => 100, 'io_bytes_write' => 0], \pmssUserMetricsCollect(1000, $root));
    }

    public function testV1ConsumersKeepZeroIoAndThrottleFallback(): void
    {
        $slice = 'blkio/user.slice/user-1000.slice/';
        foreach ([0, 10] as $bytes) {
            $root = $this->tree([
                $slice.'blkio.bfq.io_service_bytes' => "8:0 Read 0\n8:0 Write 0\n",
                $slice.'blkio.bfq.io_serviced' => "8:0 Read 0\n8:0 Write 0\n",
                $slice.'blkio.throttle.io_service_bytes' => "8:0 Read {$bytes}\n8:0 Write 0\n",
                $slice.'blkio.throttle.io_serviced' => "8:0 Read {$bytes}\n8:0 Write 0\n",
            ]);
            $this->assertSame([
                'io_read' => $bytes, 'io_write' => 0, 'io_read_ops' => $bytes, 'io_write_ops' => 0,
                'io_source' => $bytes === 0 ? 'bfq' : 'throttle',
            ], \pmssResourceLogReadCountersV1(1000, $root));
            $this->assertSame(['io_bytes_read' => $bytes, 'io_bytes_write' => 0, 'io_ops_read' => $bytes, 'io_ops_write' => 0],
                \pmssUserMetricsCollect(1000, $root));
        }
    }

    public function testV1ConsumersOmitMalformedSources(): void
    {
        $slice = 'user.slice/user-1000.slice/';
        $root = $this->tree([
            'cpuacct/'.$slice.'cpuacct.usage' => "-1\n",
            'memory/'.$slice.'memory.usage_in_bytes' => "1 extra\n",
            'memory/'.$slice.'memory.stat' => "rss invalid\ncache -1\n",
            'pids/'.$slice.'pids.current' => "0\n",
        ]);
        $this->assertSame(['tasks' => 0], \pmssResourceLogReadCountersV1(1000, $root));
        $this->assertSame(['pids_current' => 0], \pmssUserMetricsCollect(1000, $root));
    }
}
