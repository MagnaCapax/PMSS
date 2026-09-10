<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/webCgroupMemoryStatus.php';

class WebCgroupMemoryStatusTest extends TestCase
{
    /** Lock the complete pre-refactor payload, including types, key order, and display text. */
    public function testMemoryReaderPayloadSnapshots(): void
    {
        $cases = [
            'v1' => [['memory.usage_in_bytes' => '100', 'memory.soft_limit_in_bytes' => '200', 'memory.limit_in_bytes' => '400'], '244f327bb546cd77b01992a321152286034264d531ffa9e5b7746c79dfbeac0c'],
            'unlimited' => [['memory.usage_in_bytes' => '100', 'memory.soft_limit_in_bytes' => '200', 'memory.limit_in_bytes' => '1125899906842624'], 'b13f997189cf41dc30eeac3d348dc7a24e82ce0c5f940f4dcc6692679deec1df'],
            'mixed' => [['memory.current' => '0', 'memory.usage_in_bytes' => '100', 'memory.high' => 'max', 'memory.soft_limit_in_bytes' => '200', 'memory.max' => '-1', 'memory.limit_in_bytes' => '400'], 'e0d31abe844743a809c096f2277697e801847a2db87190b226e6880d2b2bc0e4'],
            'pressure' => [['memory.current' => '195', 'memory.high' => '200', 'memory.max' => '400', 'memory.stat' => "anon 160\nfile 35", 'cgroup.controllers' => 'cpu memory io', 'memory.pressure' => "some avg10=0.50 total=1\nfull avg10=0.10 total=2", 'memory.events' => "high 1001\nmax 2\noom 0\noom_kill 0"], '83d6b1884a8fc2cbbdd4c0d1afc0e7f5a016d375707fbab640301a9e10836201'],
            'oom' => [['memory.usage_in_bytes' => '100', 'memory.limit_in_bytes' => '400', 'memory.oom_control' => 'oom_kill 4'], '9b86c22953066fb6084cf2c605989188340dde98d0bce43a9fae8e5ea1585298'],
            'invalid' => [['memory.current' => '-1', 'memory.usage_in_bytes' => '1.2', 'memory.high' => '', 'memory.max' => 'max'], '7f43320f26a6efcabc24f23aa74a5ae1e927c6bc45ff9f68f9e9302bbb28b977'],
        ];
        foreach ($cases as $name => [$files, $hash]) {
            $dir = $this->pmssMakeTempDir('pmss-web-cgroup-snapshot-');
            foreach ($files as $file => $value) $this->pmssWriteFile($dir.'/'.$file, $value."\n");
            // Isolate customer formatting from the operator formatter loaded by the full suite.
            $actual = $this->pmssRunRepoInlinePhpRequire('etc/skel/www/webCgroupMemoryStatus.php',
                '$status = pmssWebCgroupMemoryStatusRead(["uid" => -1, "cgroup_dir" => '.var_export($dir, true).']);'
                .'$status["cgroup_dir"] = "<slice>"; echo hash("sha256", serialize($status));', [], '2>&1');
            $this->assertSame($hash, $actual, $name);
        }
    }

    public function testFormatBytesCoversInvalidAndGiBValues(): void
    {
        foreach ([[null, 'n/a'], [-1, 'n/a'], [5 * 1024 * 1024 * 1024, '5.0 GiB']] as [$value, $expected]) {
            $this->assertSame($expected, \pmssWebCgroupMemoryStatusFormatBytes($value));
        }
    }

    public function testDetectDirPrefersExplicitOverride(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        $this->assertSame($dir, \pmssWebCgroupMemoryStatusDetectDir(['cgroup_dir' => $dir]));
    }

    public function testDetectDirRejectsControllerlessUnifiedSlice(): void
    {
        $unifiedDir = $this->pmssMakeTempDir('pmss-web-cgroup-unified-');
        $v1Dir = $this->pmssMakeTempDir('pmss-web-cgroup-v1-');
        $this->pmssWriteFile($unifiedDir.'/cgroup.controllers', "\n");
        $this->pmssWriteFile($unifiedDir.'/memory.stat', "anon 99\n");
        $this->pmssWriteFile($unifiedDir.'/memory.pressure', "some avg10=2.00\n");
        $this->pmssWriteFile($v1Dir.'/memory.stat', "total_rss 42\n");

        $this->assertSame($v1Dir, \pmssWebCgroupMemoryStatusDetectDir([
            'uid' => 1234,
            'cgroup_dir_candidates' => [$unifiedDir, $v1Dir],
        ]));
    }

    public function testSharedCustomerFileReadersKeepSymlinkAndMapContracts(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        $integerPath = $dir.'/memory.current';
        $integerLink = $dir.'/memory.link';
        file_put_contents($integerPath, "42\n");
        file_put_contents($dir.'/memory.zero', "0\n");
        $this->pmssCreateSymlinkOrSkip($integerPath, $integerLink);
        file_put_contents($dir.'/memory.events', "high 7\nfull avg10=0.25 avg60=0.00\nbadline\n");
        file_put_contents($dir.'/resource.data', serialize(['memory' => ['current' => 42]]));

        $this->assertSame(42, \pmssCustomerUnsignedIntegerFileRead($integerPath));
        $this->assertSame(null, \pmssCustomerPositiveIntegerFileRead($dir.'/memory.zero'));
        $this->assertSame(null, \pmssCustomerUnsignedIntegerFileRead($integerLink));
        $this->assertSame(42, \pmssCustomerUnsignedIntegerFileRead($integerLink, true));
        $this->assertSame(['memory' => ['current' => 42]], \pmssCustomerSerializedArrayFileRead($dir.'/resource.data'));
        $this->assertSame(
            ['high' => '7', 'full' => 'avg10=0.25 avg60=0.00'],
            \pmssCustomerKeyValueFileRead($dir.'/memory.events')
        );
    }

    public function testClassifyReturnsExpectedSeverityBands(): void
    {
        foreach ([
            'THROTTLED' => ['memory_current' => 2048, 'high_percent' => 200.0, 'throttle_events' => 1],
            'HIGH' => ['memory_current' => 950, 'usage_percent' => 95.0, 'high_percent' => 95.0],
            'MEDIUM' => ['memory_current' => 800, 'usage_percent' => 80.0, 'high_percent' => 80.0],
            'LOW' => ['memory_current' => 400, 'usage_percent' => 40.0, 'high_percent' => 40.0],
        ] as $expected => $overrides) {
            $this->assertClassifiesAs($expected, $overrides);
        }
    }

    public function testClassifyDetectsSustainedSoftThrottleBelowMemoryHigh(): void
    {
        $this->assertClassifiesAs('THROTTLED', [
            'memory_current' => 9970,
            'memory_high' => 10000,
            'high_percent' => 99.7,
            'throttle_events' => 1001,
        ]);
    }

    public function testClassifyDoesNotPromoteStaleThrottleEventsBelowSoftLimit(): void
    {
        $this->assertClassifiesAs('MEDIUM', [
            'memory_current' => 9490,
            'memory_high' => 10000,
            'high_percent' => 94.9,
            'throttle_events' => 1001,
        ]);
    }

    public function testReadParsesCgroupCountersAndFormatsUsage(): void
    {
        $dir = $this->writeMemoryStatusFixture(
            '4294967296', '4831838208', '5368709120',
            "low 0\nhigh 17\nmax 0\noom 0\noom_kill 0\n",
            "some avg10=0.33 avg60=0.01 avg300=0.00 total=123\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n"
        );

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->assertTrue($status['available']);
        $this->pmssAssertArraySubsetSame(['memory_current' => 4294967296, 'memory_high' => 4831838208, 'memory_max' => 5368709120, 'throttle_events' => 17, 'max_events' => 0, 'oom_events' => 0, 'oom_kill_events' => 0, 'status' => 'MEDIUM'], $status);
        $this->assertStringContainsString('4.0 GiB / 5.0 GiB', $status['usage_text']);
    }

    public function testReadClassifiesSustainedSoftThrottleBeforeHardLimit(): void
    {
        $dir = $this->writeMemoryStatusFixture(
            '16774565888', '16777216000', '33554432000',
            "low 0\nhigh 137149598\nmax 0\noom 0\noom_kill 0\n",
            "some avg10=0.50 avg60=0.01 avg300=0.00 total=123\nfull avg10=0.00 avg60=0.01 avg300=0.00 total=0\n"
        );

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->assertSame('THROTTLED', $status['status']);
        $this->assertSame(50.0, $status['usage_percent']);
        $this->assertSame(100.0, $status['high_percent']);
        $this->assertStringContainsString('reduced speed', $status['message']);
    }

    public function testReadFallsBackToMemoryHighWhenMaxIsUnlimited(): void
    {
        $dir = $this->writeMemoryStatusFixture('2147483648', '3221225472', 'max', "high 0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->assertSame('memory.high', $status['limit_source']);
        $this->assertSame(3221225472, $status['limit_bytes']);
        $this->assertStringContainsString('2.0 GiB / 3.0 GiB', $status['usage_text']);
    }

    public function testReadUsesCgroupV1MemoryCountersWithoutSystemctlFallback(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-v1-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "2147483648\n");
        $this->pmssWriteFile($dir.'/memory.soft_limit_in_bytes', "3221225472\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "9223372036854771712\n");
        $this->pmssWriteFile($dir.'/memory.events', "high 0\n");
        $this->pmssWriteFile($dir.'/memory.pressure', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234]);

        $this->assertTrue($status['available']);
        $this->assertSame(2147483648, $status['memory_current']);
        $this->assertSame(3221225472, $status['memory_high']);
        $this->assertSame(null, $status['memory_max']);
        $this->assertSame('memory.high', $status['limit_source']);
        $this->assertStringContainsString('2.0 GiB / 3.0 GiB', $status['usage_text']);
        $this->pmssAssertRepoFileContract('etc/skel/www/webCgroupMemoryStatus.php', array(
            'forbidden' => array('systemctl show user-'),
        ));
    }

    public function testReadSurfacesV1OomKillFromMemoryOomControlWithUpgradeCta(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-v1-oom-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "104857600\n");
        $this->pmssWriteFile($dir.'/memory.soft_limit_in_bytes', "327155712\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "327155712\n");
        $this->pmssWriteFile($dir.'/memory.events', "high 0\n");
        // Real OOM kills on the account's own slice — the ONLY sound v1 pressure signal.
        $this->pmssWriteFile($dir.'/memory.oom_control', "oom_kill_disable 0\nunder_oom 0\noom_kill 4\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234]);

        $this->assertSame(4, $status['oom_kill_events']);
        $this->assertSame('HIGH', $status['status']);
        $this->assertStringContainsString('out-of-memory', $status['message']);
        $this->assertStringContainsString('Extra RAM', $status['message']);
    }

    public function testReadReadsChildServiceSliceOomKillWhenParentSliceIsZero(): void
    {
        // systemd can record the memcg OOM kills on the child user@<uid>.service slice while the
        // parent user-<uid>.slice reads 0 — the reader must check both and take the max.
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-v1-oomchild-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "104857600\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "327155712\n");
        $this->pmssWriteFile($dir.'/memory.oom_control', "oom_kill_disable 0\nunder_oom 0\noom_kill 0\n");
        $childDir = $dir.'/user@1234.service';
        @mkdir($childDir, 0777, true);
        $this->pmssWriteFile($childDir.'/memory.oom_control', "oom_kill_disable 0\nunder_oom 0\noom_kill 3\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234]);

        $this->assertSame(3, $status['oom_kill_events']);
        $this->assertSame('HIGH', $status['status']);
    }

    public function testReadUsesAnonymousMemoryForPressureButReportsInclusiveCurrent(): void
    {
        $dir = $this->writeMemoryStatusFixture(
            '1136082944', '1048576000', '1310720000', "high 0\nmax 0\noom 0\noom_kill 0\n"
        );
        $this->pmssWriteFile($dir.'/memory.stat', "total_rss 715689984\ntotal_cache 369463296\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234]);

        $this->pmssAssertArraySubsetSame([
            'memory_current' => 1136082944,
            'usage_percent' => 86.7,
            'high_percent' => 108.3,
            'pressure_usage_percent' => 54.6,
            'pressure_high_percent' => 68.3,
            'status' => 'LOW',
        ], $status);
        $this->assertStringContainsString('1.1 GiB / 1.2 GiB (86.7%; pressure 54.6%)', $status['usage_text']);
    }

    public function testReadDoesNotExposeCgroupV1FailcntAsThrottleEvents(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-v1-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "1073741824\n");
        $this->pmssWriteFile($dir.'/memory.soft_limit_in_bytes', "1073741824\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "2147483648\n");
        $this->pmssWriteFile($dir.'/memory.failcnt', "1270090200\n");
        $this->pmssWriteFile($dir.'/memory.pressure', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");
        $this->pmssWriteFile($dir.'/memory.stat', "total_rss 1073741824\ntotal_cache 0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234]);

        $this->pmssAssertArraySubsetSame(['throttle_events' => null, 'status' => 'HIGH'], $status);
    }

    public function testReadIgnoresPsiFromCgroupV1MemoryController(): void
    {
        $dir = $this->writeMemoryStatusFixture(
            '1073741824', '4294967296', '8589934592', "high 0\n",
            "some avg10=2.00 avg60=2.00 avg300=2.00 total=1\nfull avg10=1.00 avg60=1.00 avg300=1.00 total=1\n"
        );
        $this->pmssWriteFile($dir.'/memory.stat', "total_rss 1073741824\ntotal_cache 0\n");

        unlink($dir.'/cgroup.controllers');
        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234]);

        $this->assertSame(null, $status['pressure_some_avg10']);
        $this->assertSame(null, $status['pressure_full_avg10']);
        $this->assertSame('LOW', $status['status']);
    }

    public function testCustomerPanelsOmitUnavailableThrottleEventCount(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('etc/skel/www/webCgroupMemoryStatus.php', [
            "if (\$pressureStatus['throttle_events'] !== null)",
            "\$pressureParts[] = '<br />Throttle events: '",
        ]);
        $this->pmssAssertRepoFileContainsOrderedStrings('etc/skel/www/stats.php', [
            "if (\$pmssMemoryPressure['throttle_events'] !== null):",
            '<span class="label">Throttle events:</span>',
        ]);
    }

    public function testThrottleMessageUsesReducedSpeedCopyWithoutOomLanguage(): void
    {
        $dir = $this->writeMemoryStatusFixture('2147483648', '1073741824', '3221225472', "high 4\nmax 0\noom 0\noom_kill 0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->pmssAssertArraySubsetSame(['status' => 'THROTTLED', 'status_color' => '#d2691e'], $status);
        $this->assertStringContainsString('reduced speed', $status['message']);
        $this->assertStringContainsString('upgrading your plan', $status['message']);
        $this->pmssAssertStringNotContainsString('killed', $status['message']);
        $this->pmssAssertStringNotContainsString('OOM', $status['message']);
    }

    public function testWelcomePageSplitsThrottleAndOomWarningCopy(): void
    {
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/webCgroupMemoryStatus.php',
            ['RAM THROTTLE ACTIVE', 'RAM LIMIT EXCEEDED', '$isThrottleActive', '$hasOomEvents']
        );

        $this->assertTrue(
            strpos($source, 'RAM THROTTLE ACTIVE') < strpos($source, 'RAM LIMIT EXCEEDED'),
            'Throttle copy must be selected before the hard-limit/OOM warning copy.'
        );
    }

    public function testMemoryStatParserKeepsCgroupV1AndV2MemoryFields(): void
    {
        foreach ([
            ["total_rss 134217728\nhierarchical_memory_limit 999\ntotal_cache 67108864\n", 134217728.0, 67108864.0],
            ["anon 268435456\nslab 123\nfile 33554432\n", 268435456.0, 33554432.0],
        ] as [$raw, $anon, $file]) {
            $breakdown = \pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($raw);
            $this->assertSame($anon, (float) $breakdown['anon']);
            $this->assertSame($file, (float) $breakdown['file']);
        }
    }

    public function testMemoryStatCandidatePathsPreferV2BeforeV1(): void
    {
        $paths = \pmssCustomerCgroupCounterPaths(1234, '', 'memory.stat', 'memory', 'memory.stat');

        $this->assertSame('/sys/fs/cgroup/user.slice/user-1234.slice/memory.stat', $paths[0]);
        $this->assertSame('/sys/fs/cgroup/unified/user.slice/user-1234.slice/memory.stat', $paths[1]);
        $this->assertSame('/sys/fs/cgroup/memory/user.slice/user-1234.slice/memory.stat', $paths[2]);
    }

    public function testMemoryStatCandidatePathsKeepUidFallbackAfterExplicitSlice(): void
    {
        $paths = \pmssCustomerCgroupCounterPaths(1234, '/sys/fs/cgroup/unified/user.slice/user-1234.slice', 'memory.stat', 'memory', 'memory.stat');

        $this->assertSame('/sys/fs/cgroup/unified/user.slice/user-1234.slice/memory.stat', $paths[0]);
        $this->assertTrue(in_array('/sys/fs/cgroup/memory/user.slice/user-1234.slice/memory.stat', $paths, true));
    }

    private function assertClassifiesAs(string $expected, array $overrides): void
    {
        $this->assertSame($expected, \pmssWebCgroupMemoryStatusClassify(array_replace([
            'memory_current' => 400,
            'memory_high' => 1000,
            'usage_percent' => 40.0,
            'high_percent' => 40.0,
            'pressure_some_avg10' => 0.0,
            'pressure_full_avg10' => 0.0,
            'throttle_events' => 0,
        ], $overrides)));
    }

    private function writeMemoryStatusFixture(
        string $current,
        string $high,
        string $max,
        string $events,
        string $pressure = "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n"
    ): string
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        $this->pmssWriteFile($dir.'/cgroup.controllers', "memory\n");
        $this->pmssWriteFile($dir.'/memory.stat', "anon {$current}\nfile 0\n");
        foreach (['current' => $current, 'high' => $high, 'max' => $max, 'events' => $events, 'pressure' => $pressure] as $name => $contents) {
            $this->pmssWriteFile($dir.'/memory.'.$name, rtrim((string) $contents, "\n")."\n");
        }

        return $dir;
    }
}
