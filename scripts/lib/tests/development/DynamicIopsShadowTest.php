<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsShadow.php';

/** Hermetic shadow allocation coverage; never invokes a live cron or controller. */
class DynamicIopsShadowTest extends TestCase
{
    private function users(): array
    {
        return ['small' => ['source' => ['quota' => 1000, 'IOWeight' => 100, 'resourceIOPSReadMax' => 100000], 'weight' => 100],
            'large' => ['source' => ['quota' => 32000, 'IOWeight' => 100, 'resourceIOPSReadMax' => 100000], 'weight' => 100]];
    }

    private function allocate(array $users, $blend = 15.0, array $policy = [], ?float $passive = 3300.0): array
    {
        return \pmssDynamicIopsAllocate($users, $policy, ['blend' => $blend], $passive, null, 'hdd', true);
    }

    public function testSettingsRejectUnsafeTypesRangesAndEnforcementMode(): void
    {
        $this->assertSame('off', \pmssDynamicIopsSettings([])['mode']);
        foreach ([['mode' => 'enforce'], ['mode' => true], ['floor' => 0], ['floorCharge' => 1.1],
            ['idlePressure' => 15], ['busyPressure' => 101], ['busyMultiplier' => 1], ['idleMultiplier' => INF],
            ['floor' => []], ['idleMultiplier' => 1], ['idleMultiplier' => 101]] as $settings) {
            $this->assertSame(null, \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => $settings]]));
        }
        $this->assertSame(null, \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => 'shadow']]));
        $this->assertTrue(\pmssDynamicIopsShadowCycle([], []));
        $this->assertTrue(\pmssDynamicIopsShadowCycle([], ['cgroup' => ['dynamicIops' => ['mode' => 'enforce']]]));
    }

    public function testPressureUsesWeightedWindowsAndRejectsIncompleteEvidence(): void
    {
        $now = 1800000000; $samples = [];
        for ($n = 0; $n < 12; $n++) $samples[] = ['time' => $now - $n * 300,
            'psiFullAvg300' => $n === 0 ? 12 : 0, 'psiMemFullAvg300' => 3, 'psiCpuFullAvg300' => null];
        $result = \pmssDynamicIopsPressure(array_reverse($samples), $now);
        $this->assertEquals([5 => 12, 15 => 4, 60 => 1], $result['io']);
        $this->assertEquals(161 / 17, $result['blend']);
        $this->assertEquals(3, $result['memory'][60]);
        $this->assertSame(null, $result['cpu'][5]);
        foreach ([[], array_slice($samples, 1), array_fill(0, 12, $samples[0]),
            [['time' => $now + 1]], [['time' => $now - 3600]], [['time' => (string) $now]]] as $bad) {
            $this->assertSame(null, \pmssDynamicIopsPressure($bad, $now)['blend']);
        }
        foreach ([101, -1, NAN, INF, [], null] as $value) {
            $samples[0]['psiFullAvg300'] = $value;
            $this->assertSame(null, \pmssDynamicIopsPressure($samples, $now)['blend']);
        }
    }

    public function testQuotaFairnessPoolAndMonotonicPressureResponse(): void
    {
        $previous = null;
        foreach ([0, 5, 7.5, 10, 12.5, 15, 100] as $pressure) {
            $result = $this->allocate($this->users(), $pressure);
            $rows = $result['variants']['1.0']['users'];
            $this->assertEquals(32, $rows['large']['share'] / $rows['small']['share']);
            $this->assertTrue(abs(array_sum(array_column($rows, 'proposed')) - $result['pool']) <= $result['pool'] * 0.05);
            if ($previous !== null) foreach ($rows as $user => $row) $this->assertTrue($row['proposed'] <= $previous[$user]['proposed']);
            $previous = $rows;
        }
        $this->assertEquals(200, $previous['small']['proposed']);
        $this->assertEquals(6400, $previous['large']['proposed']);
        $half = $result['variants']['0.5']['users'];
        $this->assertEquals(sqrt(32), $half['large']['share'] / $half['small']['share']);
        $users = $this->users(); $users['small']['weight'] = 400;
        $this->assertTrue($this->allocate($users)['variants']['1.0']['users']['small']['share'] > $rows['small']['share']);
    }

    public function testFloorsCeilingsExplicitLimitsAndFractionalFloorCharge(): void
    {
        $users = $this->users(); $users['small']['source']['resourceIOPSReadMin'] = 1000;
        $result = $this->allocate($users);
        $this->assertEquals(1000, $result['variants']['1.0']['users']['small']['proposed']);
        $this->assertEquals(800, $result['variants']['1.0']['floor_lift']);
        $this->assertEquals(6400, $result['variants']['1.0']['charged_pool']);
        $users['small']['source']['resourceIOPSReadMax'] = 10;
        $this->assertSame(10, $this->allocate($users)['variants']['1.0']['users']['small']['proposed']);
        foreach (['/home:777', '0', '/home:max', '', 0] as $explicit) {
            $users['small']['source']['IOReadIOPS'] = $explicit;
            $row = $this->allocate($users)['variants']['1.0']['users']['small'];
            $this->assertTrue($row['explicit']); $this->assertSame(null, $row['proposed']);
        }
        $this->assertSame([], $this->allocate([])['variants']['1.0']['users']);
    }

    public function testMissingInputsPreserveLegacyProposalsAndRejectOverflow(): void
    {
        foreach ([null, NAN, INF, -1, 101] as $pressure) {
            $result = $this->allocate($this->users(), $pressure);
            $this->assertSame('missing-pressure', $result['fallback']);
            foreach ($result['variants']['1.0']['users'] as $row) $this->assertSame($row['legacy'], $row['proposed']);
        }
        foreach ([null, 0.0, NAN, INF, -1.0] as $host) $this->assertSame('missing-ceiling', $this->allocate($this->users(), 15, [], $host)['fallback']);
        foreach ([null, 0, -1, [], INF] as $quota) {
            $users = $this->users(); $users['small']['source']['quota'] = $quota;
            $result = $this->allocate($users);
            $this->assertSame('missing-quota-or-weight', $result['fallback']);
            foreach ($result['variants']['1.0']['users'] as $row) $this->assertSame($row['legacy'], $row['proposed']);
        }
        $this->assertSame(null, \pmssDynamicIopsAllocate($this->users(), [], [], PHP_FLOAT_MAX, null, null, true));
        $result = \pmssDynamicIopsAllocate($this->users(), [], ['blend' => 15], 100.0, 3300.0, null, false);
        $this->assertEquals(3300, $result['host_read_iops']);
        $this->assertSame(null, $result['variants']['1.0']['users']['small']['legacy']);
        $stale = \pmssDynamicIopsAllocate($this->users(), [], ['blend' => 15], null, null, 'hdd', true, 3300.0);
        $this->assertSame('missing-ceiling', $stale['fallback']);
        $this->assertSame(3300, $stale['variants']['1.0']['users']['small']['proposed']);
    }

    public function testBoundedReadersRejectRedirectedMalformedAndPartialRecords(): void
    {
        $root = $this->pmssMakeTempDir('pmss-dynamic-iops-', 0700); $path = $root.'/history';
        $sample = ['time' => 1800000000, 'psiFullAvg300' => 10];
        $line = '2026-01-01 00:00:00 || '.serialize($sample)."\n";
        file_put_contents($path, str_repeat('x', 70000)."\n".$line.'partial');
        $this->assertSame([$sample], iterator_to_array(\pmssDynamicIopsHistory($path), false));
        $this->pmssCreateSymlinkOrSkip($path, $root.'/link');
        $this->assertSame([], \pmssDynamicIopsTailLines($root.'/link'));
        $this->assertSame([], \pmssDynamicIopsTailLines($root));
        $this->assertSame([], \pmssDynamicIopsTailLines($root.'/missing'));
        $this->assertSame([], \pmssDynamicIopsTailLines($path."\0"));
        $base = ['ok' => true, 'device' => '/dev/example', 'test' => 'home-device-randread-4k',
            'run_ts' => gmdate('c', time() - 3600), 'metrics' => ['read_iops' => 4000]];
        foreach ([['ok' => false], ['device' => '/dev/other'], ['test' => 'dev-randread-4k'],
            ['run_ts' => 'invalid'], ['metrics' => ['read_iops' => INF]]] as $bad) {
            file_put_contents($path, json_encode(array_replace($base, $bad))."\n");
            $this->assertSame(null, \pmssDynamicIopsBenchmark($path, '/dev/example'));
        }
        file_put_contents($path, json_encode($base)."\n".json_encode(array_replace($base, ['metrics' => ['read_iops' => 9000]])));
        $this->assertEquals(4000, \pmssDynamicIopsBenchmark($path, '/dev/example'));
    }
}
