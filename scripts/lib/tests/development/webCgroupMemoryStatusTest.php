<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/webCgroupMemoryStatus.php';

class WebCgroupMemoryStatusTest extends TestCase
{
    public function testFormatBytesHandlesInvalidValues(): void
    {
        $this->assertSame('n/a', \pmssWebCgroupMemoryStatusFormatBytes(null));
        $this->assertSame('n/a', \pmssWebCgroupMemoryStatusFormatBytes(-1));
    }

    public function testFormatBytesScalesToGiB(): void
    {
        $this->assertSame('5.0 GiB', \pmssWebCgroupMemoryStatusFormatBytes(5 * 1024 * 1024 * 1024));
    }

    public function testDetectDirPrefersExplicitOverride(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        $this->assertSame($dir, \pmssWebCgroupMemoryStatusDetectDir(['cgroup_dir' => $dir]));
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

        $this->assertSame(42, \pmssCustomerUnsignedIntegerFileRead($integerPath));
        $this->assertSame(null, \pmssCustomerPositiveIntegerFileRead($dir.'/memory.zero'));
        $this->assertSame(null, \pmssCustomerUnsignedIntegerFileRead($integerLink));
        $this->assertSame(42, \pmssCustomerUnsignedIntegerFileRead($integerLink, true));
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
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        file_put_contents($dir.'/memory.current', "4294967296\n");
        file_put_contents($dir.'/memory.high', "4831838208\n");
        file_put_contents($dir.'/memory.max', "5368709120\n");
        file_put_contents($dir.'/memory.events', "low 0\nhigh 17\nmax 0\noom 0\noom_kill 0\n");
        file_put_contents($dir.'/memory.pressure', "some avg10=0.33 avg60=0.01 avg300=0.00 total=123\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->assertTrue($status['available']);
        $this->assertSame(4294967296, $status['memory_current']);
        $this->assertSame(4831838208, $status['memory_high']);
        $this->assertSame(5368709120, $status['memory_max']);
        $this->assertSame(17, $status['throttle_events']);
        $this->assertSame(0, $status['max_events']);
        $this->assertSame(0, $status['oom_events']);
        $this->assertSame(0, $status['oom_kill_events']);
        $this->assertSame('MEDIUM', $status['status']);
        $this->assertStringContainsString('4.0 GiB / 5.0 GiB', $status['usage_text']);
    }

    public function testReadClassifiesSustainedSoftThrottleBeforeHardLimit(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        file_put_contents($dir.'/memory.current', "16774565888\n");
        file_put_contents($dir.'/memory.high', "16777216000\n");
        file_put_contents($dir.'/memory.max', "33554432000\n");
        file_put_contents($dir.'/memory.events', "low 0\nhigh 137149598\nmax 0\noom 0\noom_kill 0\n");
        file_put_contents($dir.'/memory.pressure', "some avg10=0.50 avg60=0.01 avg300=0.00 total=123\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->assertSame('THROTTLED', $status['status']);
        $this->assertSame(50.0, $status['usage_percent']);
        $this->assertSame(100.0, $status['high_percent']);
        $this->assertStringContainsString('reduced speed', $status['message']);
    }

    public function testReadFallsBackToMemoryHighWhenMaxIsUnlimited(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        file_put_contents($dir.'/memory.current', "2147483648\n");
        file_put_contents($dir.'/memory.high', "3221225472\n");
        file_put_contents($dir.'/memory.max', "max\n");
        file_put_contents($dir.'/memory.events', "high 0\n");
        file_put_contents($dir.'/memory.pressure', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->assertSame('memory.high', $status['limit_source']);
        $this->assertSame(3221225472, $status['limit_bytes']);
        $this->assertStringContainsString('2.0 GiB / 3.0 GiB', $status['usage_text']);
    }

    public function testThrottleMessageUsesReducedSpeedCopyWithoutOomLanguage(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-web-cgroup-');
        file_put_contents($dir.'/memory.current', "2147483648\n");
        file_put_contents($dir.'/memory.high', "1073741824\n");
        file_put_contents($dir.'/memory.max', "3221225472\n");
        file_put_contents($dir.'/memory.events', "high 4\nmax 0\noom 0\noom_kill 0\n");
        file_put_contents($dir.'/memory.pressure', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");

        $status = \pmssWebCgroupMemoryStatusRead(['cgroup_dir' => $dir]);

        $this->assertSame('THROTTLED', $status['status']);
        $this->assertSame('#d2691e', $status['status_color']);
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

    public function testMemoryStatParserAcceptsCgroupV1Totals(): void
    {
        $breakdown = \pmssWebCgroupMemoryStatusMemoryStatBreakdownParse(
            "total_rss 134217728\nhierarchical_memory_limit 999\ntotal_cache 67108864\n"
        );

        $this->assertSame(134217728.0, (float) $breakdown['anon']);
        $this->assertSame(67108864.0, (float) $breakdown['file']);
    }

    public function testMemoryStatParserKeepsCgroupV2Fields(): void
    {
        $breakdown = \pmssWebCgroupMemoryStatusMemoryStatBreakdownParse(
            "anon 268435456\nslab 123\nfile 33554432\n"
        );

        $this->assertSame(268435456.0, (float) $breakdown['anon']);
        $this->assertSame(33554432.0, (float) $breakdown['file']);
    }

    public function testMemoryStatCandidatePathsPreferV2BeforeV1(): void
    {
        $paths = \pmssWebCgroupMemoryStatusMemoryStatCandidatePaths(1234);

        $this->assertSame('/sys/fs/cgroup/user.slice/user-1234.slice/memory.stat', $paths[0]);
        $this->assertSame('/sys/fs/cgroup/unified/user.slice/user-1234.slice/memory.stat', $paths[1]);
        $this->assertSame('/sys/fs/cgroup/memory/user.slice/user-1234.slice/memory.stat', $paths[2]);
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
}
