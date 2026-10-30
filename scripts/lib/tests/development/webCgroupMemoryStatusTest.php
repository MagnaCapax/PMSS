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
            'oom' => [['memory.usage_in_bytes' => '100', 'memory.limit_in_bytes' => '400', 'memory.oom_control' => 'oom_kill 4'], '34825e5d6c3dd4ab5f54654d5ba81909804ca8623344a19e14fd104eb8bc1593'],
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

    public function testFormatBytesRejectsNonFiniteValues(): void
    {
        foreach ([INF, -INF, NAN, '1e999', str_repeat('9', 400), true, false, [], new \stdClass()] as $value) {
            $this->assertSame('n/a', \pmssWebCgroupMemoryStatusFormatBytes($value));
        }
        foreach ([['0001024', '1.0 KiB'], [1024.5, '1.0 KiB']] as [$value, $expected]) {
            $this->assertSame($expected, \pmssWebCgroupMemoryStatusFormatBytes($value));
        }
    }

    public function testMemoryStatParserSkipsInvalidCountersWithoutLosingValidFields(): void
    {
        foreach (['total_rss', 'total_cache', 'anon', 'file'] as $field) {
            $key = in_array($field, ['total_rss', 'anon'], true) ? 'anon' : 'file';
            foreach (['', '-1', '1.5', '1e999', 'INF', "1\0x", str_repeat('9', 400)] as $value) {
                $this->assertSame([], \pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($field.' '.$value));
                $this->assertSame([$key => 42.0], \pmssWebCgroupMemoryStatusMemoryStatBreakdownParse(
                    $field." 42\n".$field.' '.$value
                ));
            }
            foreach (['0', '00042', '18446744073709551615'] as $value) {
                $this->assertSame([$key => (float) $value], \pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($field.' '.$value));
            }
        }
        foreach ([null, [], 42, ''] as $raw) {
            $this->assertSame([], \pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($raw));
        }
    }

    public function testReadFallsBackToCurrentWhenV1AnonymousCounterOverflows(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-overflow-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "100\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "400\n");
        $this->pmssWriteFile($dir.'/memory.stat', 'total_rss '.str_repeat('9', 400)."\ntotal_cache 50\n");

        // Disable UID fallback so the fixture never reads the host's account counters.
        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => -1]);
        $this->pmssAssertArraySubsetSame([
            'pressure_usage_percent' => 25.0,
            'status' => 'LOW',
        ], $status);
        $this->assertTrue(is_string(json_encode($status)));
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

        $this->pmssAssertArraySubsetSame([
            'status' => 'THROTTLED', 'usage_percent' => 50.0, 'high_percent' => 100.0,
        ], $status);
        $this->assertStringContainsString('reduced speed', $status['message']);
    }

    public function testReadFallsBackToMemoryHighWhenMaxIsUnlimited(): void
    {
        $dir = $this->writeMemoryStatusFixture('2147483648', '3221225472', 'max', "high 0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->pmssAssertArraySubsetSame(['limit_source' => 'memory.high', 'limit_bytes' => 3221225472], $status);
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
        $this->pmssAssertArraySubsetSame([
            'memory_current' => 2147483648, 'memory_high' => 3221225472, 'memory_max' => null,
            'limit_source' => 'memory.high',
        ], $status);
        $this->assertStringContainsString('2.0 GiB / 3.0 GiB', $status['usage_text']);
        $this->pmssAssertRepoFileContract('etc/skel/www/webCgroupMemoryRead.php', array(
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
        $this->pmssWriteFile($dir.'/oom.state', serialize(['count' => 4, 'last_increase' => 100, 'sampled_at' => 100]));

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234, 'oom_status_path' => $dir.'/oom.state', 'now' => 100]);

        $this->pmssAssertArraySubsetSame(['oom_kill_events' => 4, 'status' => 'HIGH'], $status);
        $this->assertStringContainsAllStrings(['out-of-memory', 'Extra RAM'], $status['message']);
    }

    public function testReadReadsChildServiceSliceOomKillWhenParentSliceIsZero(): void
    {
        // systemd can record the memcg OOM kills on the child user@<uid>.service slice while the
        // parent user-<uid>.slice reads 0 — the reader must include the child counter.
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-v1-oomchild-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "104857600\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "327155712\n");
        $this->pmssWriteFile($dir.'/memory.oom_control', "oom_kill_disable 0\nunder_oom 0\noom_kill 0\n");
        $childDir = $dir.'/user@1234.service';
        @mkdir($childDir, 0777, true);
        $this->pmssWriteFile($childDir.'/memory.oom_control', "oom_kill_disable 0\nunder_oom 0\noom_kill 3\n");
        $this->pmssWriteFile($dir.'/oom.state', serialize(['count' => 2, 'last_increase' => null, 'sampled_at' => 100]));

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234, 'oom_status_path' => $dir.'/oom.state', 'now' => 100]);

        $this->pmssAssertArraySubsetSame(['oom_kill_events' => 3, 'status' => 'HIGH'], $status);
    }

    public function testReadSurfacesNestedSessionOomKills(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-v1-scopes-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "100\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "400\n");
        $this->pmssWriteFile($dir.'/memory.oom_control', "oom_kill 0\n");
        $this->pmssWriteFile($dir.'/session-1.scope/run-1.scope/memory.oom_control', "oom_kill 2\n");
        $this->pmssWriteFile($dir.'/oom.state', serialize(['count' => 0, 'last_increase' => null, 'sampled_at' => 100]));
        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234, 'oom_status_path' => $dir.'/oom.state', 'now' => 100]);
        $this->pmssAssertArraySubsetSame([
            'oom_kill_events' => 2, 'oom_kill_recent' => true, 'status' => 'HIGH',
        ], $status);
    }

    public function testOomRecencyRejectsLifetimeAndStaleSignals(): void
    {
        $state = ['count' => 4, 'last_increase' => 100, 'sampled_at' => 86500];
        $this->assertSame(false, \pmssWebCgroupMemoryStatusOomRecent(4, $state, 86500));
        $this->assertSame(true, \pmssWebCgroupMemoryStatusOomRecent(5, $state, 86500));
        $this->assertSame(false, \pmssWebCgroupMemoryStatusOomRecent(3, $state, 86500));
        $this->assertSame(false, \pmssWebCgroupMemoryStatusOomRecent(4, ['count' => 4, 'last_increase' => 86500, 'sampled_at' => 85000], 86500));
        $this->assertSame(false, \pmssWebCgroupMemoryStatusOomRecent(4, [], 86500));
    }

    public function testHistoricalOomDoesNotAdvertiseRamUpgrade(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-stale-oom-');
        $this->pmssWriteFile($dir.'/memory.usage_in_bytes', "100\n");
        $this->pmssWriteFile($dir.'/memory.limit_in_bytes', "400\n");
        $this->pmssWriteFile($dir.'/memory.oom_control', "oom_kill 4\n");
        $this->pmssWriteFile($dir.'/oom.state', serialize(['count' => 4, 'last_increase' => 100, 'sampled_at' => 86500]));

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir, 'uid' => 1234, 'oom_status_path' => $dir.'/oom.state', 'now' => 86500]);
        $this->pmssAssertArraySubsetSame(['oom_kill_events' => 4, 'status' => 'LOW', 'message' => ''], $status);
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

        $this->pmssAssertArraySubsetSame([
            'pressure_some_avg10' => null, 'pressure_full_avg10' => null, 'status' => 'LOW',
        ], $status);
    }

    public function testCustomerPanelsOmitUnavailableThrottleEventCount(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('etc/skel/www/welcomeMemory.php', [
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
        $this->assertStringContainsAllStrings(['reduced speed', 'upgrading your plan'], $status['message']);
        $this->pmssAssertStringNotContainsString('killed', $status['message']);
        $this->pmssAssertStringNotContainsString('OOM', $status['message']);
    }

    public function testWelcomePageSplitsThrottleAndOomWarningCopy(): void
    {
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/welcomeMemory.php',
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

    /** Freeze policy boundaries and precedence independently of counter acquisition. */
    public function testPressurePolicyBoundaryCharacterization(): void
    {
        foreach ([
            ['LOW', ['usage_percent' => 79.9, 'high_percent' => null]],
            ['MEDIUM', ['usage_percent' => 80.0, 'high_percent' => null]],
            ['MEDIUM', ['usage_percent' => 94.9, 'high_percent' => null]],
            ['HIGH', ['usage_percent' => 95.0, 'high_percent' => null]],
            ['LOW', ['pressure_some_avg10' => 0.249]],
            ['MEDIUM', ['pressure_some_avg10' => 0.25]],
            ['MEDIUM', ['pressure_some_avg10' => 0.999]],
            ['HIGH', ['pressure_some_avg10' => 1.0]],
            ['LOW', ['pressure_full_avg10' => 0.099]],
            ['HIGH', ['pressure_full_avg10' => 0.10]],
            ['HIGH', ['high_percent' => 95.0, 'throttle_events' => 1000]],
            ['THROTTLED', ['high_percent' => 95.0, 'throttle_events' => 1001]],
            ['THROTTLED', ['memory_current' => 1000, 'throttle_events' => 1]],
            ['HIGH', ['memory_current' => 1000, 'throttle_events' => 1, 'oom_kill_recent' => true]],
            ['LOW', ['memory_current' => null, 'memory_high' => null, 'usage_percent' => null,
                'high_percent' => null, 'pressure_some_avg10' => null, 'pressure_full_avg10' => null,
                'throttle_events' => null]],
        ] as [$expected, $overrides]) {
            $this->assertClassifiesAs($expected, $overrides);
        }
    }

    /** Freeze the inclusive sample window and exclusive last-increase cutoff. */
    public function testOomRecencyBoundaryCharacterization(): void
    {
        foreach ([
            [true, 5, ['count' => 4, 'sampled_at' => 99100]],
            [false, 5, ['count' => 4, 'sampled_at' => 99099]],
            [false, 5, ['count' => 4, 'sampled_at' => 100001]],
            [true, 4, ['count' => 4, 'sampled_at' => 100000, 'last_increase' => 13601]],
            [false, 4, ['count' => 4, 'sampled_at' => 100000, 'last_increase' => 13600]],
            [false, 4, ['count' => 4, 'sampled_at' => 100000, 'last_increase' => 100001]],
            [false, 3, ['count' => 4, 'sampled_at' => 100000, 'last_increase' => 100000]],
            [false, 0, ['count' => 0, 'sampled_at' => 100000, 'last_increase' => 100000]],
            [false, 5, ['count' => '4', 'sampled_at' => 100000]],
            [false, 5, ['count' => 4, 'sampled_at' => '100000']],
        ] as [$expected, $count, $state]) {
            $this->assertSame($expected, \pmssWebCgroupMemoryStatusOomRecent($count, $state, 100000));
        }
    }

    /** Distinct consumers retain first-anon versus complete-pair selection and fallback. */
    public function testMemoryStatTraversalKeepsConsumerPolicies(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-memory-candidates-');
        $paths = [];
        foreach (['file 2', '', 'anon 3', "anon 4\nfile 5", 'total_cache 6'] as $index => $raw) {
            $paths[] = $this->pmssWriteFile($dir.'/'.$index, $raw);
        }
        $this->assertSame(['anon' => 3.0], \pmssWebCgroupMemoryStatusMemoryStatBreakdownRead($paths, ['anon']));
        $this->assertSame(['anon' => 4.0, 'file' => 5.0], \pmssWebCgroupMemoryStatusMemoryStatBreakdownRead($paths, ['anon', 'file']));
        $this->assertSame(['file' => 2.0], \pmssWebCgroupMemoryStatusMemoryStatBreakdownRead(array_slice($paths, 0, 2), ['anon']));
        $this->assertSame(['file' => 6.0], \pmssWebCgroupMemoryStatusMemoryStatBreakdownRead([$paths[2], $paths[4]], ['anon', 'file']));
        $this->assertSame([], \pmssWebCgroupMemoryStatusMemoryStatBreakdownRead([$dir.'/absent', $paths[1]], ['anon']));
        $this->assertSame([], \pmssWebCgroupMemoryStatusMemoryStatBreakdownRead([], ['anon']));
    }

    /** A heal arriving ahead of any module must leave all memory entrypoints unavailable. */
    public function testPartialMemoryDeliveryDefinesNoEntrypoints(): void
    {
        $modules = ['webCgroupMemorySource', 'webCgroupMemoryPolicy', 'webCgroupMemoryRead', 'welcomeMemory'];
        foreach ($modules as $missing) {
            $dir = $this->pmssMakeTempDir('pmss-web-memory-delivery-');
            $facade = $this->pmssWriteFile($dir.'/webCgroupMemoryStatus.php', $this->pmssReadRepoFile('etc/skel/www/webCgroupMemoryStatus.php'));
            $this->pmssWriteFile($dir.'/scriptsInc.php', '<?php');
            foreach ($modules as $module) {
                if ($module !== $missing) $this->pmssWriteFile($dir.'/'.$module.'.php', $this->pmssReadRepoFile('etc/skel/www/'.$module.'.php'));
            }
            $this->assertSame('[false,false,false,false]', $this->pmssRunInlinePhpRequire($facade,
                'echo json_encode(array_map("function_exists", ["pmssWebCgroupMemoryStatusRead",'
                .'"pmssWelcomeMemoryStateBuild", "pmssWebCgroupMemoryStatusDetectDir", "pmssWebCgroupMemoryStatusClassify"]));', [], '2>&1'), $missing);
        }
        $source = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        foreach ($modules as $module) {
            $this->assertTrue(strpos($source, "'www/".$module.".php'") < strpos($source, "'www/webCgroupMemoryStatus.php'"));
        }
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
