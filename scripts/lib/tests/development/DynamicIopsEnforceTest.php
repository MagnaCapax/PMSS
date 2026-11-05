<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsShadow.php';

/** Pure enforcement policy boundaries; no live controller or account data. */
class DynamicIopsEnforceTest extends TestCase
{
    private function policy(array $settings = []): array
    {
        return ['cgroup' => ['dynamicIops' => $settings + ['mode' => 'enforce']]];
    }

    private function users(array $source = []): array
    {
        return ['fixture' => ['source' => $source + ['quota' => 1000, 'IOWeight' => 100,
            'resourceIOPSReadMax' => 100000], 'weight' => 100]];
    }

    private function allocate(array $users, array $policy, $iowait = 80, ?float $capacity = 500.0, $psi = 15): ?array
    {
        return \pmssDynamicIopsAllocate($users, $policy, ['blend' => $psi, 'iowait_blend' => $iowait],
            $capacity, null, null, false);
    }

    public function testCurbEndpointsMidpointAndSaturation(): void
    {
        foreach ([[49, 1.0], [50, 1.0], [65, 0.875], [80, 0.75], [95, 0.75]] as [$wait, $factor]) {
            $result = $this->allocate($this->users(), $this->policy(), $wait);
            $this->assertSame((float) $wait, $result['iowait_blend']);
            $this->assertSame($factor, $result['curb_factor']);
            $this->assertSame(1000.0, $result['pool_uncurbed']);
            $this->assertSame(1000.0 * $factor, $result['pool']);
        }
    }

    public function testEnforcePoolIgnoresBenchmarkFigure(): void
    {
        $pressure = ['blend' => 15, 'iowait_blend' => 10];
        $enforce = \pmssDynamicIopsAllocate($this->users(), $this->policy(), $pressure, 500.0, 900000.0, null, false);
        $this->assertSame(1000.0, $enforce['pool']);
        $missing = \pmssDynamicIopsAllocate($this->users(), $this->policy(), $pressure, null, 900000.0, null, false);
        $this->assertSame('missing-ceiling', $missing['fallback']);
        $shadow = \pmssDynamicIopsAllocate($this->users(), $this->policy(['mode' => 'shadow']), $pressure, 500.0, 900000.0, null, false);
        $this->assertSame(1800000.0, $shadow['pool']);
    }

    public function testMissingOrInvalidIowaitDoesNotSelectFallback(): void
    {
        foreach ([null, -1, 101, INF, NAN, [], 'bad'] as $wait) {
            $result = $this->allocate($this->users(), $this->policy(), $wait);
            $this->assertSame(null, $result['iowait_blend']);
            $this->assertSame(1.0, $result['curb_factor']);
            $this->assertSame(1000.0, $result['pool']);
            $this->assertSame(null, $result['fallback']);
        }
    }

    public function testIowaitBlendRequiresEveryWindowAndUsesTwelveFourOne(): void
    {
        $now = 1800000000; $samples = [];
        for ($n = 0; $n < 12; $n++) $samples[] = ['time' => $now - 300 * $n,
            'cpuIowaitPct' => $n === 0 ? 12 : 0, 'psiFullAvg300' => 15];
        $this->assertSame(161 / 17, \pmssDynamicIopsPressure($samples, $now)['iowait_blend']);
        foreach ([0, 1, 2, 3, 11] as $missing) {
            $bad = $samples; unset($bad[$missing]['cpuIowaitPct']);
            $result = \pmssDynamicIopsPressure($bad, $now);
            $this->assertSame(null, $result['iowait_blend']);
            $this->assertSame(15.0, $result['blend']);
        }
    }

    public function testOffAndShadowKeepEveryLegacyProjectionDespiteCurb(): void
    {
        foreach (['off', 'shadow'] as $mode) foreach ([[], ['IOReadIOPS' => '/home:1000000']] as $source) {
            $users = $this->users($source); $policy = $this->policy(['mode' => $mode]);
            $before = $this->allocate($users, $policy, null);
            $after = $this->allocate($users, $policy, 95);
            $this->assertSame($before['pool'], $after['pool']);
            $this->assertSame($before['variants'], $after['variants']);
            $this->assertSame(0.75, $after['curb_factor']);
            $this->assertSame(null, \pmssDynamicIopsReadCaps($users, $policy, $after));
        }
    }

    public function testExplicitLowerLimitWinsAndPlaceholdersAreReplaced(): void
    {
        foreach ([['/home:10', 10], ['/dev/md0:20', 20], ['/home:1000000', 750],
            [null, 750], ['bad:2', 750], ['/home:0', 750]] as [$explicit, $expected]) {
            $users = $this->users(['IOReadIOPS' => $explicit]); $policy = $this->policy();
            $this->assertSame(['fixture' => $expected], \pmssDynamicIopsReadCaps($users, $policy, $this->allocate($users, $policy)));
        }
    }

    public function testFloorAndTierCeilingAreAppliedBeforeExplicitMinimum(): void
    {
        foreach ([[[], 1.0, 15], [['resourceIOPSReadMin' => 30], 1.0, 30],
            [['resourceIOPSReadMax' => 20], 500.0, 20],
            [['resourceIOPSReadMax' => 8], 1.0, 8], [['IOReadIOPS' => '/home:5'], 1.0, 5]] as [$source, $capacity, $expected]) {
            $users = $this->users($source); $policy = $this->policy();
            $this->assertSame(['fixture' => $expected], \pmssDynamicIopsReadCaps($users, $policy, $this->allocate($users, $policy, 80, $capacity)));
        }
    }

    public function testOnlyComputedQuotaVariantsAreSelectable(): void
    {
        $users = $this->users(); $users['large'] = $this->users(['quota' => 32000])['fixture'];
        foreach ([1.0, 0.5] as $exponent) {
            $policy = $this->policy(['quotaExponent' => $exponent]); $result = $this->allocate($users, $policy);
            $label = $exponent === 1.0 ? '1.0' : '0.5';
            $this->assertSame(array_column($result['variants'][$label]['users'], 'would_be'),
                array_values(\pmssDynamicIopsReadCaps($users, $policy, $result)));
        }
    }

    public function testInvalidSettingsAndFailedAllocationCannotProduceReadCaps(): void
    {
        foreach ([['curbStartIowait' => 80], ['curbFullIowait' => 50], ['curbFullIowait' => 101],
            ['curbStartIowait' => -1], ['curbMaxCut' => -0.1], ['curbMaxCut' => 1.1], ['curbMaxCut' => INF],
            ['curbFullIowait' => []], ['quotaExponent' => 0.75], ['quotaExponent' => null]] as $settings) {
            $policy = $this->policy($settings);
            $this->assertSame(null, \pmssDynamicIopsSettings($policy));
            $this->assertSame(null, \pmssDynamicIopsReadCaps($this->users(), $policy, null));
        }
        foreach ([[null, 15], [0.0, 15], [500.0, null]] as [$capacity, $psi]) {
            $users = $this->users(['IOReadIOPS' => '/home:1000000']); $policy = $this->policy();
            $this->assertSame(null, \pmssDynamicIopsReadCaps($users, $policy, $this->allocate($users, $policy, 80, $capacity, $psi)));
        }
        foreach ([null, 0, -1, [], INF] as $quota) {
            $users = $this->users(['quota' => $quota]); $policy = $this->policy();
            $this->assertSame(null, \pmssDynamicIopsReadCaps($users, $policy, $this->allocate($users, $policy)));
        }
    }

    public function testIncompleteOrInvalidCapMapsNeverYieldPartialEnforcement(): void
    {
        $users = $this->users(); $policy = $this->policy();
        foreach ([null, 0, -1, 1.5, '50'] as $cap) {
            $result = $this->allocate($users, $policy);
            $result['variants']['1.0']['users']['fixture']['would_be'] = $cap;
            $this->assertSame(null, \pmssDynamicIopsReadCaps($users, $policy, $result));
        }
        $users['absent'] = $users['fixture'];
        $result = $this->allocate($users, $policy);
        unset($result['variants']['1.0']['users']['absent']);
        $this->assertSame(null, \pmssDynamicIopsReadCaps($users, $policy, $result));
    }
}
