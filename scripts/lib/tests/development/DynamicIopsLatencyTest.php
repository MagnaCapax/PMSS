<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsShadow.php';

/** ADR 0236: on rotational /home the pool multiplier steps toward a read-latency band; ADR 0237: on solid state toward a multiple of the reference latency. */
class DynamicIopsLatencyTest extends TestCase
{
    private function policy(array $settings = []): array
    {
        return ['cgroup' => ['dynamicIops' => $settings + ['mode' => 'enforce']]];
    }

    private function users(): array
    {
        return ['fixture' => ['source' => ['quota' => 1000, 'IOWeight' => 100, 'resourceIOPSReadMax' => 100000], 'weight' => 100]];
    }

    /** One cycle: PSI blend 15 alone would give the busy multiplier 2. */
    private function cycle(?float $latency, ?float $previous, ?string $media = 'hdd', float $psi = 15, ?float $reference = null): array
    {
        return \pmssDynamicIopsAllocate($this->users(), $this->policy(), ['blend' => $psi, 'iowait_blend' => 10,
            'latency_ms' => $latency, 'previous_multiplier' => $previous, 'media' => $media,
            'latency_reference_ms' => $reference], 500.0, null, null, false);
    }

    public function testLatencyIsTheMeanOfTheValidProbesInTheLastThreeBuckets(): void
    {
        $now = 1800000000;
        $rows = function (array $pings) use ($now): array {
            $out = [];
            foreach ($pings as $i => $ms) $out[] = ['time' => $now - 300 * $i - 10, 'psiFullAvg300' => 1, 'iopingHomeMs' => $ms];
            return $out;
        };
        $this->assertSame(20.0, \pmssDynamicIopsPressure($rows([18, 20, 22, 90]), $now)['latency_ms']);
        // A missed or absurd probe is skipped, not a reason to drop latency for three cycles.
        $this->assertSame(20.0, \pmssDynamicIopsPressure($rows([18, null, 22]), $now)['latency_ms']);
        $this->assertSame(20.0, \pmssDynamicIopsPressure($rows([null, 18, 22]), $now)['latency_ms']);
        $this->assertSame(19.0, \pmssDynamicIopsPressure($rows([18, 20]), $now)['latency_ms']);
        $this->assertSame(20.0, \pmssDynamicIopsPressure($rows([18, 70000, 22]), $now)['latency_ms']);
        $this->assertSame(150.0, \pmssDynamicIopsPressure($rows([150, 150, 150]), $now)['latency_ms']);
        // Older than the last three buckets does not count; no valid probe means no latency.
        $this->assertSame(null, \pmssDynamicIopsPressure($rows([null, null, null, 22]), $now)['latency_ms']);
        $this->assertSame(null, \pmssDynamicIopsPressure([], $now)['latency_ms']);
    }

    /** One missed probe (the production pattern, about 1-3% of probes) keeps steering from the previous multiplier. */
    public function testOneMissedProbeKeepsLatencySteering(): void
    {
        $now = 1800000000;
        $rows = [];
        foreach ([null, 5.0, 6.0] as $i => $ms) $rows[] = ['time' => $now - 300 * $i - 10, 'psiFullAvg300' => 90, 'iopingHomeMs' => $ms];
        $pressure = \pmssDynamicIopsPressure($rows, $now);
        $result = $this->cycle($pressure['latency_ms'], 6.0);
        $this->assertSame('latency', $result['steer']);
        $this->assertTrue(abs($result['multiplier'] - 6.6) < 1e-9);
    }

    public function testQuietDiskRaisesTheMultiplierOneStepPerCycle(): void
    {
        $result = $this->cycle(7.5, 2.0);
        $this->assertSame('latency', $result['steer']);
        $this->assertSame(2.2, $result['multiplier']);
        $this->assertSame(1100.0, $result['pool']);
    }

    public function testInsideTheBandHoldsAndAboveItLowers(): void
    {
        $this->assertSame(6.0, $this->cycle(20.0, 6.0)['multiplier']);
        $this->assertSame(6.0, $this->cycle(25.0, 6.0)['multiplier']);
        $this->assertTrue(abs($this->cycle(30.0, 5.5)['multiplier'] - 5.0) < 1e-9);
    }

    public function testMultiplierStaysBetweenBusyAndIdle(): void
    {
        $this->assertSame(10.0, $this->cycle(1.0, 9.5)['multiplier']);
        $this->assertSame(2.0, $this->cycle(80.0, 2.1)['multiplier']);
    }

    public function testFirstCycleStartsFromThePressureMultiplier(): void
    {
        $this->assertSame(2.2, $this->cycle(5.0, null, 'hdd', 15)['multiplier']);
        $this->assertTrue(abs($this->cycle(40.0, null, 'hdd', 0)['multiplier'] - 10 / 1.1) < 1e-9);
    }

    public function testSolidStateWithoutAReferenceAndUnknownMediaKeepThePressureMultiplier(): void
    {
        foreach ([['ssd', null], ['nvme', null], [null, 0.24], ['ssd', 0.0]] as [$media, $reference]) {
            $result = $this->cycle(0.2, 5.0, $media, 15, $reference);
            $this->assertSame('pressure', $result['steer']);
            $this->assertSame(2.0, $result['multiplier']);
        }
        $missing = $this->cycle(null, 5.0);
        $this->assertSame('pressure', $missing['steer']);
        $this->assertSame(2.0, $missing['multiplier']);
    }

    /** Closed loop with the real 15-minute lag (mean of the last three samples): settles in the band and stays. */
    public function testClosedLoopSettlesInTheBand(): void
    {
        foreach ([[3.0, 20.0, 25.0], [3.6, 20.0, 25.0], [5.0, 20.0, 25.0], [1.0, 10.0, 10.0], [40.0, 80.0, 80.0]] as [$msPerMultiplier, $low, $high]) {
            $multiplier = 2.0; $seen = []; $samples = [];
            for ($i = 0; $i < 80; $i++) {
                array_unshift($samples, min(80.0, $msPerMultiplier * $multiplier));
                $samples = array_slice($samples, 0, 3);
                if (count($samples) === 3) $multiplier = $this->cycle(array_sum($samples) / 3, $multiplier)['multiplier'];
                if ($i >= 60) $seen[] = min(80.0, $msPerMultiplier * $multiplier);
            }
            $this->assertTrue(min($seen) >= $low && max($seen) <= $high,
                "ms/x {$msPerMultiplier}: settled latency ".min($seen).'..'.max($seen)." outside {$low}..{$high}");
            $this->assertSame(1, count(array_unique(array_map('strval', $seen))));
        }
    }

    public function testInvalidBandSettingsAreRejected(): void
    {
        foreach ([['latencyLowMs' => 25, 'latencyHighMs' => 20], ['latencyStep' => 1.0], ['latencyStep' => 3.0],
            ['latencyHighMs' => 5000], ['latencyLowMs' => -1], ['ssdLatencyLowX' => 10, 'ssdLatencyHighX' => 5],
            ['ssdLatencyLowX' => 0.5], ['ssdLatencyHighX' => 2000], ['ssdLatencyLowX' => 'x']] as $bad) {
            $this->assertSame(null, \pmssDynamicIopsSettings($this->policy($bad)));
        }
        $settings = \pmssDynamicIopsSettings($this->policy());
        $this->assertSame([20.0, 25.0, 1.1, 5.0, 10.0], [$settings['latencyLowMs'], $settings['latencyHighMs'],
            $settings['latencyStep'], $settings['ssdLatencyLowX'], $settings['ssdLatencyHighX']]);
    }

    /** Solid state, group median 0.24 ms: the band is 1.2-2.4 ms (5x-10x the reference). */
    public function testSolidStateStepsTowardAMultipleOfTheReference(): void
    {
        foreach (['ssd', 'nvme'] as $media) {
            $quiet = $this->cycle(0.2, 2.0, $media, 30, 0.24);
            $this->assertSame('latency', $quiet['steer']);
            $this->assertSame(2.2, $quiet['multiplier']);
            $this->assertSame(6.0, $this->cycle(2.0, 6.0, $media, 30, 0.24)['multiplier']);
            $this->assertTrue(abs($this->cycle(3.0, 5.5, $media, 30, 0.24)['multiplier'] - 5.0) < 1e-9);
        }
        // The hard-disk band ignores the reference: 2 ms is far below 20 ms whatever the group does.
        $this->assertSame(2.2, $this->cycle(2.0, 2.0, 'hdd', 30, 0.24)['multiplier']);
    }

    /** Group median from fleetCompare.json for three days; the disk's own quiet floor when that is higher. */
    public function testReferenceIsTheGroupMedianOrTheOwnQuietFloorWhicheverIsHigher(): void
    {
        $now = 1800000000;
        $group = ['tier' => 'M1000-SSD', 'psi' => 1.0, 'svcMs' => 1.0, 'ts' => $now - 3600, 'groupIopingMs' => 0.24];
        $this->assertSame(0.24, \pmssDynamicIopsLatencyReference($group, 0.2, $now));
        $this->assertSame(5.1, \pmssDynamicIopsLatencyReference($group, 5.1, $now));
        $this->assertSame(0.24, \pmssDynamicIopsLatencyReference($group, null, $now));
        $this->assertSame(0.2, \pmssDynamicIopsLatencyReference(['ts' => $now - 4 * 86400] + $group, 0.2, $now));
        $this->assertSame(0.2, \pmssDynamicIopsLatencyReference(['ts' => $now + 60] + $group, 0.2, $now));
        $this->assertSame(0.2, \pmssDynamicIopsLatencyReference(['groupIopingMs' => 0] + $group, 0.2, $now));
        $this->assertSame(0.2, \pmssDynamicIopsLatencyReference(['tier' => 'M1000', 'psi' => 1.0, 'svcMs' => 1.0, 'ts' => $now], 0.2, $now));
        $this->assertSame(null, \pmssDynamicIopsLatencyReference(null, null, $now));
        $this->assertSame(null, \pmssDynamicIopsLatencyReference(['groupIopingMs' => 'x'] + $group, 0.0, $now));
    }

    /** A disk that answers in 5.1 ms even idle, in a group whose median is 0.24 ms, is judged against itself. */
    public function testInherentlySlowSolidStateIsNotBrakedForItsHardware(): void
    {
        $now = 1800000000;
        $reference = \pmssDynamicIopsLatencyReference(['ts' => $now, 'groupIopingMs' => 0.24], 5.1, $now);
        $this->assertSame(2.2, $this->cycle(5.1, 2.0, 'ssd', 30, $reference)['multiplier']);
        // Against the group median alone the same disk would be pushed to the busy multiplier.
        $this->assertSame(2.0, $this->cycle(5.1, 2.1, 'ssd', 30, 0.24)['multiplier']);
    }

    /** Solid-state closed loop with the 15-minute lag settles inside 5x-10x the reference and stays. */
    public function testSolidStateClosedLoopSettlesInTheBand(): void
    {
        foreach ([[0.1, 0.24], [0.3, 0.24], [2.0, 5.1]] as [$msPerMultiplier, $reference]) {
            $multiplier = 2.0; $seen = []; $samples = [];
            for ($i = 0; $i < 80; $i++) {
                array_unshift($samples, $msPerMultiplier * $multiplier);
                $samples = array_slice($samples, 0, 3);
                if (count($samples) === 3) $multiplier = $this->cycle(array_sum($samples) / 3, $multiplier, 'ssd', 30, $reference)['multiplier'];
                if ($i >= 60) $seen[] = $msPerMultiplier * $multiplier;
            }
            $low = min(5 * $reference, $msPerMultiplier * 10); $high = min(10 * $reference, $msPerMultiplier * 10);
            $this->assertTrue(min($seen) >= $low - 1e-9 && max($seen) <= $high + 1e-9,
                "ms/x {$msPerMultiplier}: settled ".min($seen).'..'.max($seen)." outside {$low}..{$high}");
            $this->assertSame(1, count(array_unique(array_map('strval', $seen))));
        }
    }
}
