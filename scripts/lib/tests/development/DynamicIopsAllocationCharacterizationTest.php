<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsPolicy.php';

/** Lock dynamicIopsAllocation.php, dynamicIopsPool.php and dynamicIopsWorkConserving.php as one pure flow. */
class DynamicIopsAllocationCharacterizationTest extends TestCase
{
    /** Literal payloads retain projection order, independent charges and demand annotations. */
    public function testCompleteAllocationPayloads(): void
    {
        $source = ['quota' => 1000, 'IOWeight' => 100, 'resourceIOPSReadMax' => 100000];
        $row = ['quota' => 1000.0, 'weight' => 100, 'share' => 0.5, 'floor' => 15,
            'ceiling' => 100000, 'explicit' => false, 'legacy' => 200];
        foreach ([
            'share-only' => [],
            'empty-rates' => ['rates' => [], 'demand' => [100000.0, 100000.0], 'water' => [[200.0, 200.0], [200.0, 200.0]]],
            'quiet-reader' => ['rates' => ['fixtur01' => 0.0], 'demand' => [15.0, 100000.0],
                'water' => [[15.0, 15.0], [385.0, 385.0]], 'caps' => [[200, 200], [390, 390]]],
            'explicit-floor' => ['source' => ['IOReadIOPS' => '/home:50', 'resourceIOPSReadMin' => 1000],
                'row' => ['floor' => 1000, 'explicit' => true, 'legacy' => null], 'rates' => [],
                'demand' => [50.0, 100000.0], 'water' => [[50.0, 50.0], [350.0, 150.0]],
                'caps' => [[null, 1000], [350, 150]], 'lifts' => [0.0, 800.0], 'pools' => [400.0, 200.0]],
            'tier-bound' => ['source' => ['resourceIOPSReadMax' => 25], 'row' => ['ceiling' => 25, 'legacy' => 25],
                'rates' => [], 'demand' => [25.0, 100000.0], 'water' => [[25.0, 25.0], [375.0, 375.0]],
                'caps' => [[25, 25], [380, 380]]],
            'missing-pressure' => ['pressure' => ['blend' => null], 'fallback' => 'missing-pressure',
                'rates' => [], 'caps' => [[200, null], [200, null]]],
            'missing-capacity' => ['passive' => null, 'pool' => 0.0, 'fallback' => 'missing-ceiling',
                'rates' => [], 'lifts' => [30.0, 30.0], 'caps' => [[200, null], [200, null]]],
            'idle-pressure' => ['pressure' => ['blend' => 5.0], 'multiplier' => 10.0,
                'pool' => 2000.0, 'caps' => [[1000, 1000], [1000, 1000]]],
            'latency-band' => ['pressure' => ['blend' => 15.0, 'media' => 'ssd', 'latency_ms' => 10.0,
                'latency_reference_ms' => 2.0, 'previous_multiplier' => 6.0], 'steer' => 'latency',
                'multiplier' => 6.0, 'pool' => 1200.0, 'caps' => [[600, 600], [600, 600]]],
            'hdd-probe' => ['pressure' => ['blend' => null, 'media' => 'hdd'], 'steer' => 'probe',
                'multiplier' => 5.0, 'pool' => 1000.0, 'caps' => [[500, 500], [500, 500]]],
            'iowait-curb' => ['pressure' => ['blend' => 15.0, 'iowait_blend' => 80.0],
                'pool' => 300.0, 'curb' => 0.75, 'caps' => [[150, 150], [150, 150]]],
        ] as $label => $case) {
            $pressure = $case['pressure'] ?? ['blend' => 15.0];
            $passive = array_key_exists('passive', $case) ? $case['passive'] : 200.0;
            $pool = $case['pool'] ?? 400.0;
            $expected = ['pressure' => $pressure, 'passive_read_iops' => $passive, 'benchmark_read_iops' => null,
                'host_read_iops' => $passive ?? 0.0, 'multiplier' => $case['multiplier'] ?? 2.0,
                'steer' => $case['steer'] ?? 'pressure', 'pool' => $pool,
                'fallback' => $case['fallback'] ?? null, 'variants' => []];
            foreach (['1.0' => 1.0, '0.5' => 0.5] as $variant => $alpha) {
                $rows = ['fixtur01' => array_replace($row, $case['row'] ?? []), 'fixtur02' => $row];
                foreach (array_keys($rows) as $index => $user) {
                    if (isset($case['demand'])) {
                        $rows[$user] += ['rate' => $case['rates'][$user] ?? null, 'demand' => $case['demand'][$index],
                            'water' => ['proposed' => $case['water'][$index][0], 'would_be' => $case['water'][$index][1]]];
                    }
                    $caps = $case['caps'] ?? [[200, 200], [200, 200]];
                    $rows[$user] += ['proposed' => $caps[$index][0], 'would_be' => $caps[$index][1]];
                }
                $expected['variants'][$variant] = ['alpha' => $alpha, 'floor_lift' => $case['lifts'][0] ?? 0.0,
                    'charged_pool' => $case['pools'][0] ?? $pool, 'users' => $rows,
                    'would_be_floor_lift' => $case['lifts'][1] ?? 0.0, 'would_be_charged_pool' => $case['pools'][1] ?? $pool];
            }
            $expected += ['iowait_blend' => $pressure['iowait_blend'] ?? null, 'curb_factor' => $case['curb'] ?? 1.0,
                'pool_uncurbed' => $label === 'iowait-curb' ? 400.0 : $pool];
            $actual = \pmssDynamicIopsAllocate([
                'fixtur01' => ['source' => array_replace($source, $case['source'] ?? []), 'weight' => 100],
                'fixtur02' => ['source' => $source, 'weight' => 100],
            ], [], $pressure, $passive, null, 'hdd', true, null, $case['rates'] ?? null);
            $this->assertSame(json_encode($expected, JSON_PRESERVE_ZERO_FRACTION),
                json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), $label);
        }
    }

    /** Array projections must retain numeric keys and both independent enforced bounds. */
    public function testAccountKeysAndExplicitReadCapsStayStable(): void
    {
        $users = [7 => ['source' => ['quota' => 1000, 'IOReadIOPS' => '/home:50',
            'resourceIOPSReadMin' => 1000, 'resourceIOPSReadMax' => 100000], 'weight' => 100],
            'fixtur02' => ['source' => ['quota' => 1000, 'resourceIOPSReadMax' => 100000], 'weight' => 100]];
        $result = \pmssDynamicIopsAllocate($users, [], ['blend' => 15.0], 200.0, null, 'hdd', true, null, []);
        $this->assertSame([7, 'fixtur02'], array_keys($result['variants']['1.0']['users']));
        $this->assertSame([7 => 50, 'fixtur02' => 150], \pmssDynamicIopsReadCaps($users, [], $result));
    }
}
