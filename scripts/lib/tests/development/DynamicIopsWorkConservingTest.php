<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsShadow.php';

/** Demand feedback and weighted allocation use synthetic accounts and a memory-only disk. */
class DynamicIopsWorkConservingTest extends TestCase
{
    private function users(): array
    {
        $users = [];
        for ($i = 0; $i < 30; $i++) $users['fixture'.$i] = ['weight' => 100,
            'source' => ['quota' => 1000, 'IOWeight' => 100, 'resourceIOPSReadMax' => 100000]];
        return $users;
    }

    private function allocate(array $users, ?array $rates = null, ?array $wants = null, array $settings = []): array
    {
        return \pmssDynamicIopsAllocate($users, ['cgroup' => ['dynamicIops' => $settings]],
            ['blend' => 15.0], 3000.0, null, 'hdd', true, null, $rates, $wants);
    }

    public function testWaterFillReclaimsQuietSharesAndSplitsRemainingPoolByWeight(): void
    {
        $weights = array_fill_keys(array_keys($this->users()), 1 / 30);
        $demand = array_fill_keys(array_keys($weights), 15.0); $demand['fixture0'] = INF;
        $water = \pmssDynamicIopsWaterFill($weights, $demand, 6000.0);
        $this->assertEquals(5565.0, $water['fixture0']);
        $this->assertEquals(6000.0, array_sum($water));
        $this->assertSame(15.0, $water['fixture29']);
        foreach ([
            [[1, 3], [INF, INF], 1000.0, [250.0, 750.0]],
            [[1, 1, 1], [10, 45, INF], 120.0, [10.0, 45.0, 65.0]],
            [[1, 1], [10, 20], 100.0, [10.0, 20.0]],
            [[1, 1], [INF, INF], 0.0, [0.0, 0.0]],
            [[0, 1], [INF, 10], 100.0, [0.0, 10.0]],
            [[], [], 100.0, []],
        ] as [$weights, $demand, $pool, $expected]) {
            $this->assertSame($expected, \pmssDynamicIopsWaterFill($weights, $demand, $pool));
        }
    }

    public function testQuietCapsKeepTodaysShareAndUnknownOrHeldReadersCanBorrow(): void
    {
        $users = $this->users(); $rates = array_fill_keys(array_keys($users), 0.0);
        $rates['fixture0'] = 200.0;
        $legacy = $this->allocate($users);
        foreach ([true, false] as $held) {
            $result = $this->allocate($users, $rates, ['fixture0' => $held]);
            foreach ($result['variants'] as $label => $variant) {
                foreach ($variant['users'] as $user => $row) {
                    $this->assertTrue($row['would_be'] >= $legacy['variants'][$label]['users'][$user]['would_be']);
                    $this->assertSame($user === 'fixture0' ? ($held ? 5600 : 600) : 200, $row['would_be']);
                    // Unbounded demand stops at the fixtures' 100000 tier ceiling.
                    $this->assertSame($held && $user === 'fixture0' ? 100000.0 : ($user === 'fixture0' ? 600.0 : 15.0), $row['demand']);
                }
            }
        }
        $rates['fixture0'] = null;
        $this->assertSame(5600, $this->allocate($users, $rates)['variants']['1.0']['users']['fixture0']['would_be']);
        $this->assertTrue(\pmssJsonEncodeSafe($this->allocate($users, $rates)) !== null);
        foreach ([-1, INF, NAN, [], null] as $invalid) {
            $rates['fixture0'] = $invalid;
            $this->assertSame(100000.0, $this->allocate($users, $rates)['variants']['1.0']['users']['fixture0']['demand']);
        }
        // Without a ceiling, unknown demand is unbounded and logged as JSON null.
        foreach ($users as &$entry) unset($entry['source']['resourceIOPSReadMax']);
        unset($entry);
        $this->assertSame(null, \pmssCgroupPolicyReadIopsTierCeiling($users['fixture0']['source'], []));
        foreach ([null, 200.0] as $rate) {
            $rates['fixture0'] = $rate;
            $this->assertSame(null, $this->allocate($users, $rates, ['fixture0' => true])['variants']['1.0']['users']['fixture0']['demand']);
        }
    }

    public function testBothQuotaVariantsWaterFillByTheirOwnSharesAndRespectTierCeilings(): void
    {
        $users = $this->users(); $users['fixture1']['source']['quota'] = 4000;
        $rates = array_fill_keys(array_keys($users), 0.0); $rates['fixture0'] = $rates['fixture1'] = null;
        foreach ($this->allocate($users, $rates)['variants'] as $label => $variant) {
            $rows = $variant['users'];
            $this->assertEquals($label === '1.0' ? 4.0 : 2.0,
                $rows['fixture1']['water']['would_be'] / $rows['fixture0']['water']['would_be']);
        }
        $users['fixture1']['source']['resourceIOPSReadMax'] = 1000;
        $legacy = $this->allocate($users);
        foreach ($this->allocate($users, $rates)['variants'] as $label => $variant) {
            $rows = $variant['users'];
            foreach ($rows as $user => $row) foreach (['proposed', 'would_be'] as $field) {
                $this->assertTrue($row[$field] >= $legacy['variants'][$label]['users'][$user][$field]);
                $this->assertTrue($row[$field] <= $row['ceiling']);
            }
            $this->assertSame(1000, $rows['fixture1']['would_be']);
            // The ceiling-bound reader claims only 1000; the rest of the pool goes to the other reader.
            $this->assertEquals(1000.0, $rows['fixture1']['water']['would_be']);
            $this->assertEquals($variant['would_be_charged_pool'], array_sum(array_column(array_column($rows, 'water'), 'would_be')));
        }
    }

    public function testOmittedRatesAndFallbackKeepTheCompleteLegacyPayload(): void
    {
        $users = $this->users(); $users['fixture0']['source']['quota'] = 32000;
        $users['fixture1']['source']['IOReadIOPS'] = '/home:50';
        $old = \pmssDynamicIopsAllocate($users, [], ['blend' => 15.0], 3000.0, null, 'hdd', true);
        $this->assertSame($old, $this->allocate($users, null, null));
        $this->assertSame($old, $this->allocate($users, null, ['fixture0' => true]));
        $users['fixture0']['source']['quota'] = null;
        $this->assertSame($this->allocate($users), $this->allocate($users, [], []));
        $this->assertSame($this->allocate($users, null, null, ['mode' => 'off']),
            $this->allocate($users, [], [], ['mode' => 'off']));
    }

    public function testEachProjectionUsesItsOwnFloorChargedPoolAndExplicitCapWins(): void
    {
        $users = $this->users(); $users['fixture1']['source'] += ['IOReadIOPS' => '/home:50', 'resourceIOPSReadMin' => 1000];
        $rates = array_fill_keys(array_keys($users), 0.0); $rates['fixture0'] = null;
        $result = $this->allocate($users, $rates);
        foreach ($result['variants'] as $variant) {
            $row = $variant['users']['fixture0'];
            // The explicit /home:50 account claims 50, not its 1000 floor; 28 quiet accounts claim 15 each.
            $this->assertEquals($variant['charged_pool'] - 470, $row['water']['proposed']);
            $this->assertEquals($variant['would_be_charged_pool'] - 470, $row['water']['would_be']);
            $this->assertSame(null, $variant['users']['fixture1']['proposed']);
        }
        $this->assertSame(50, \pmssDynamicIopsReadCaps($users, [], $result)['fixture1']);
    }

    public function testFeedbackSettlesForDiskLimitedLightAndTwoHeavyReaders(): void
    {
        foreach ([1.0, 0.5] as $alpha) foreach ([[10000.0], [300.0], [10000.0, 10000.0]] as $want) {
            $users = $this->users(); $rates = null; $wants = null; $settled = null;
            $policy = ['cgroup' => ['dynamicIops' => ['quotaExponent' => $alpha]]];
            for ($cycle = 1; $cycle <= 10; $cycle++) {
                $result = $this->allocate($users, $rates, $wants, ['quotaExponent' => $alpha]);
                $caps = \pmssDynamicIopsReadCaps($users, $policy, $result);
                if ($cycle === 5) $settled = $caps;
                if ($cycle > 5) $this->assertSame($settled, $caps);
                // Proportionally share a 1500/s disk when the sum of capped delivery exceeds it.
                $delivery = [];
                foreach ($want as $i => $rate) $delivery['fixture'.$i] = min($rate, $caps['fixture'.$i]);
                $scale = min(1.0, 1500.0 / array_sum($delivery));
                $rates = array_fill_keys(array_keys($users), 0.0); $wants = [];
                foreach ($delivery as $user => $rate) {
                    $count = (int) floor($rate * $scale * 300);
                    $rates[$user] = $count / 300;
                    $wants[$user] = \pmssDynamicIopsSliceHeld($caps[$user], 0, $count, 300, \PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO);
                }
            }
            $this->assertTrue($settled['fixture0'] > 200);
        }
    }

    public function testHeadroomRequiresFiniteValueAboveTheStabilityBoundary(): void
    {
        foreach ([2.0, 1.5, 0, -1, 101, INF, NAN, [], null] as $headroom) {
            $this->assertSame(null, \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => ['workConservingHeadroom' => $headroom]]]));
        }
        foreach ([3.0, 100.0] as $headroom) $this->assertSame($headroom,
            \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => ['workConservingHeadroom' => $headroom]]])['workConservingHeadroom']);
        $this->assertSame(3.0, \pmssDynamicIopsSettings([])['workConservingHeadroom']);
    }
}
