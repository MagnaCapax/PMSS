<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/webCgroupMemoryStatus.php';

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

    public function testClassifyReturnsThrottledWhenAboveHighWithEvents(): void
    {
        $this->assertSame('THROTTLED', \pmssWebCgroupMemoryStatusClassify([
            'memory_current' => 2048,
            'memory_high' => 1024,
            'usage_percent' => 90.0,
            'high_percent' => 200.0,
            'pressure_some_avg10' => 0.0,
            'pressure_full_avg10' => 0.0,
            'throttle_events' => 1,
        ]));
    }

    public function testClassifyReturnsHighNearLimitWithoutThrottle(): void
    {
        $this->assertSame('HIGH', \pmssWebCgroupMemoryStatusClassify([
            'memory_current' => 950,
            'memory_high' => 1000,
            'usage_percent' => 95.0,
            'high_percent' => 95.0,
            'pressure_some_avg10' => 0.0,
            'pressure_full_avg10' => 0.0,
            'throttle_events' => 0,
        ]));
    }

    public function testClassifyReturnsMediumAtElevatedUsage(): void
    {
        $this->assertSame('MEDIUM', \pmssWebCgroupMemoryStatusClassify([
            'memory_current' => 800,
            'memory_high' => 1000,
            'usage_percent' => 80.0,
            'high_percent' => 80.0,
            'pressure_some_avg10' => 0.0,
            'pressure_full_avg10' => 0.0,
            'throttle_events' => 0,
        ]));
    }

    public function testClassifyReturnsLowWhenUsageIsComfortable(): void
    {
        $this->assertSame('LOW', \pmssWebCgroupMemoryStatusClassify([
            'memory_current' => 400,
            'memory_high' => 1000,
            'usage_percent' => 40.0,
            'high_percent' => 40.0,
            'pressure_some_avg10' => 0.0,
            'pressure_full_avg10' => 0.0,
            'throttle_events' => 0,
        ]));
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
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('RAM THROTTLE ACTIVE', $source);
        $this->assertStringContainsString('RAM LIMIT EXCEEDED', $source);
        $this->assertStringContainsString('$isThrottleActive', $source);
        $this->assertStringContainsString('$hasOomEvents', $source);
        $this->assertTrue(
            strpos($source, 'RAM THROTTLE ACTIVE') < strpos($source, 'RAM LIMIT EXCEEDED'),
            'Throttle copy must be selected before the hard-limit/OOM warning copy.'
        );
    }
}
