<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsShadow.php';

/** ADR 0236: on rotational /home the pool multiplier steps toward a read-latency band. */
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
    private function cycle(?float $latency, ?float $previous, ?string $media = 'hdd', float $psi = 15): array
    {
        return \pmssDynamicIopsAllocate($this->users(), $this->policy(), ['blend' => $psi, 'iowait_blend' => 10,
            'latency_ms' => $latency, 'previous_multiplier' => $previous, 'media' => $media], 500.0, null, null, false);
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

    public function testSolidStateAndUnknownMediaKeepThePressureMultiplier(): void
    {
        foreach (['ssd', 'nvme', null] as $media) {
            $result = $this->cycle(0.2, 5.0, $media);
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
            ['latencyHighMs' => 5000], ['latencyLowMs' => -1]] as $bad) {
            $this->assertSame(null, \pmssDynamicIopsSettings($this->policy($bad)));
        }
        $settings = \pmssDynamicIopsSettings($this->policy());
        $this->assertSame([20.0, 25.0, 1.1], [$settings['latencyLowMs'], $settings['latencyHighMs'], $settings['latencyStep']]);
    }
}
