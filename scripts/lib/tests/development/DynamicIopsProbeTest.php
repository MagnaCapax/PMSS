<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsShadow.php';
require_once __DIR__.'/DynamicIopsEnforceFixture.php';

/** ADR 0238 expectations use synthetic counts, clock and identity; no live probes. */
class DynamicIopsProbeTest extends TestCase
{
    use DynamicIopsEnforceFixture;

    private function state(float $lo = 3.0): array
    {
        return ['lo' => $lo, 'arm' => 'lo', 'block_start' => 1800000000, 'block_work' => 0.0,
            'block_samples' => 0, 'last_counts' => [1 => 0], 'pairs' => [],
            'pending_work' => null, 'last_time' => 1799999700];
    }

    /**
     * Eleven adjacent pairs (twelve blocks) at constant per-arm work, each first cycle omitted.
     * Without a gain L lowers only when the low arm delivered over 10% more, or when the low arm's
     * caps bound most of the time and the high arm delivered no more. A held low arm whose high arm
     * delivered 5% more holds: that gain is too small to prove, not evidence that tighter is free.
     * Caps that never bound tested nothing, so L holds. L rises as far as idle, where both arms are
     * equal, nothing is tested and L holds even with binding caps.
     */
    public function testDecisionsFollowMeasuredGainAndRespectBounds(): void
    {
        foreach ([[3.0, 130, false, 3 * sqrt(2), 'raise'], [3.0, 100, false, 3.0, 'hold'],
            [3.0, 105, false, 3.0, 'hold'], [3.0, 100, true, 3 / sqrt(2), 'lower'], [3.0, 105, true, 3.0, 'hold'],
            [3.0, 85, false, 3 / sqrt(2), 'lower'], [5.0, 130, false, 5 * sqrt(2), 'raise'], [2.0, 100, true, 2.0, 'lower'],
            [10.0, 130, false, 10.0, 'raise'], [10.0, 100, false, 10.0, 'hold'], [10.0, 100, true, 10.0, 'hold']] as [$lo, $hiWork, $held, $expected, $decision]) {
            $state = $this->state($lo); $count = 0;
            for ($cycle = 0; $cycle <= 144; $cycle++) {
                $count += $state['arm'] === 'lo' ? 100 : $hiWork;
                $result = \pmssDynamicIopsProbeCycle($state, \pmssDynamicIopsSettings([]), [1 => $count],
                    1800000000 + $cycle * 300, '', false, true, $held && $state['arm'] === 'lo');
                $state = $result['state'];
                if ($cycle === 24) {
                    $this->assertSame(1, $result['probe']['pairs_n']);
                    $this->assertTrue(abs($result['probe']['last_ratio'] - log($hiWork / 100)) < 1e-9);
                }
            }
            $this->assertTrue(abs($state['lo'] - $expected) < 1e-9);
            $this->assertSame($decision, $result['probe']['decision']);
            $this->assertSame([], $state['pairs']);
            $this->assertSame('lo', $state['arm']);
        }
    }

    /**
     * A mean gain above 10% that the host's own pair-to-pair noise explains is not evidence: the high
     * arm alternates 2.0x and 0.7x the low arm, so the mean (about +0.21) sits inside two standard
     * errors (about 0.33) and L holds. The same mean with steady blocks raises.
     */
    public function testNoisyGainWithinTwoStandardErrorsHolds(): void
    {
        foreach ([[[200, 70], 'hold', 3.0], [[123, 123], 'raise', 3 * sqrt(2)]] as [$his, $decision, $lo]) {
            $state = $this->state(); $count = 0;
            for ($cycle = 0; $cycle <= 144; $cycle++) {
                $block = intdiv($cycle, 12);
                $count += $state['arm'] === 'lo' ? 100 : $his[intdiv($block, 2) % 2];
                $result = \pmssDynamicIopsProbeCycle($state, \pmssDynamicIopsSettings([]), [1 => $count],
                    1800000000 + $cycle * 300, '', false, true, false);
                $state = $result['state'];
            }
            $this->assertSame($decision, $result['probe']['decision']);
            $this->assertTrue(abs($state['lo'] - $lo) < 1e-9);
            $this->assertTrue($result['probe']['gate'] >= log(1.1));
        }
    }

    /**
     * Demand rising 15% an hour under both arms is not a gain: adjacent pairs cancel the trend. The odd
     * pair left over keeps a small positive mean, so binding caps hold L rather than lower it.
     */
    public function testSteadyDemandTrendDoesNotRaise(): void
    {
        foreach ([[false, 'hold', 3.0], [true, 'hold', 3.0]] as [$held, $decision, $lo]) {
            $state = $this->state(); $count = 0;
            for ($cycle = 0; $cycle <= 144; $cycle++) {
                $count += (int) round(1000 * pow(1.15, $cycle / 12));
                $result = \pmssDynamicIopsProbeCycle($state, \pmssDynamicIopsSettings([]), [1 => $count],
                    1800000000 + $cycle * 300, '', false, true, $held);
                $state = $result['state'];
            }
            $this->assertSame($decision, $result['probe']['decision']);
            $this->assertTrue(abs($state['lo'] - $lo) < 1e-9);
        }
    }

    /** Held in fewer than half of the low cycles is not binding; the counters reset after a decision. */
    public function testMinorityHeldLowCyclesHold(): void
    {
        $state = $this->state(); $count = 0; $low = 0;
        for ($cycle = 0; $cycle <= 144; $cycle++) {
            $count += 100;
            if ($state['arm'] === 'lo') $low++;
            $result = \pmssDynamicIopsProbeCycle($state, \pmssDynamicIopsSettings([]), [1 => $count],
                1800000000 + $cycle * 300, '', false, true, $state['arm'] === 'lo' && $low % 3 === 0);
            $state = $result['state'];
        }
        $this->assertSame('hold', $result['probe']['decision']);
        $this->assertSame(0, $state['lo_cycles']);
        $this->assertSame(0, $state['lo_held']);
    }

    /** A state file from before the held counters keeps its experiment instead of restarting. */
    public function testStateWithoutHeldCountersIsKept(): void
    {
        $saved = $this->state(4.0); $saved['pairs'] = [0.1];
        $row = \pmssDynamicIopsProbeCycle($saved, \pmssDynamicIopsSettings([]), [1 => 100], 1800000000, '', false);
        $this->assertSame(4.0, $row['state']['lo']);
        $this->assertSame([0.1], $row['state']['pairs']);
        $this->assertSame(0, $row['state']['lo_cycles']);
    }

    public function testInvalidCyclesDiscardBlocksWithoutRecordingPairs(): void
    {
        foreach (['reset', 'missing', 'sync', 'short', 'long', 'fallback'] as $fault) {
            $state = $this->state();
            $state['last_counts'] = [1 => 100];
            if ($fault === 'missing') $state['last_counts'] = [];
            if ($fault === 'short') $state['last_time'] = 1799999761; // 239 seconds.
            if ($fault === 'long') $state['last_time'] = 1799999579; // 421 seconds.
            $row = \pmssDynamicIopsProbeCycle($state, \pmssDynamicIopsSettings([]), [1 => $fault === 'reset' ? 99 : 200],
                1800000000, '', $fault === 'sync', $fault !== 'fallback');
            $this->assertSame(null, $row['state']['block_work'], $fault);
            // Even a completed high block cannot pair with this discarded low block.
            $state = $row['state']; $state['block_samples'] = 12; $state['last_time'] = 1800003300;
            $next = \pmssDynamicIopsProbeCycle($state, \pmssDynamicIopsSettings([]), [1 => 1000], 1800003600, '', false);
            $this->assertSame('hi', $next['state']['arm']);
            $this->assertSame(null, $next['state']['pending_work']);
            $this->assertSame([], $next['state']['pairs']);
        }
    }

    public function testNewAccountsOnlyCountAfterTheyHaveTwoEndpoints(): void
    {
        $this->assertSame(20.0, \pmssDynamicIopsProbeWork([1 => 100], [1 => 120, 2 => 1000]));
        $this->assertSame(20.0, \pmssDynamicIopsProbeWork([1 => 120, 2 => 1000], [1 => 130, 2 => 1010]));
        $this->assertSame(10.0, \pmssDynamicIopsProbeWork([1 => 120, 2 => 1000], [2 => 1010]));
        $this->assertSame(null, \pmssDynamicIopsProbeWork([], [2 => 1010]));
        $this->assertSame(null, \pmssDynamicIopsProbeWork([1 => 120, 2 => 1000], [1 => 119, 2 => 1010]));
    }

    public function testFirstCycleIsSkippedAndPhaseIsStablePerIdentity(): void
    {
        $row = \pmssDynamicIopsProbeCycle($this->state(), \pmssDynamicIopsSettings([]), [1 => 10000], 1800000000, '', false);
        $this->assertSame(0.0, $row['state']['block_work']);
        $row = \pmssDynamicIopsProbeCycle($row['state'], \pmssDynamicIopsSettings([]), [1 => 10100], 1800000300, '', false);
        $this->assertSame(100.0, $row['state']['block_work']);
        // Synthetic identities have known CRC32 residues 3 and 5 modulo 12.
        $this->assertSame(3, \pmssDynamicIopsProbePhase('a', 12));
        $this->assertSame(5, \pmssDynamicIopsProbePhase('b', 12));
    }

    public function testMissingAndCorruptStateStartAtFive(): void
    {
        foreach ([null, \pmssJsonDecodeAssoc('{broken'), [], ['lo' => INF], ['pairs' => 'broken'],
            ['block_work' => -1] + $this->state(), ['lo' => 100] + $this->state()] as $saved) {
            $row = \pmssDynamicIopsProbeCycle($saved, \pmssDynamicIopsSettings([]), [1 => 100], 1800000000, '', false);
            $this->assertSame(5.0, $row['state']['lo']);
            $this->assertSame([], $row['state']['pairs']);
            $this->assertSame('lo', $row['state']['arm']);
        }
    }

    public function testProbeSettingsRejectMalformedAndOutOfRangeValues(): void
    {
        foreach ([['probeStartLo' => 1], ['probeStartLo' => 6], ['probeStartLo' => INF], ['probeGain' => 0],
            ['probeGain' => 1.01], ['probeBlockCycles' => 3], ['probeBlockCycles' => 49], ['probeBlockCycles' => 12.5],
            ['probePairs' => 1], ['probePairs' => 25], ['probePairs' => '6']] as $bad) {
            $this->assertSame(null, \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => $bad]]));
        }
        $this->assertSame(12, \pmssDynamicIopsSettings([])['probeBlockCycles']);
        $this->assertSame(11, \pmssDynamicIopsSettings([])['probePairs']);
    }

    /** A late iostat sample leaves no PSI blend; the probe does not use it, so HDD caps still apply. */
    public function testMissingPressureStopsOnlyBlendSteeredHosts(): void
    {
        $hdd = $this->enforceCycle(['pressure' => ['blend' => null, 'iowait_blend' => null]]);
        $row = $hdd['logs']['PMSS_DYNAMIC_IOPS_LOG'][0];
        $this->assertSame('probe', $row['steer']);
        $this->assertSame(null, $row['fallback']);
        $this->assertSame('apply', $row['read_action']);
        $this->assertTrue($hdd['writes'] !== []);
        foreach (['ssd', 'nvme'] as $media) {
            $row = $this->enforceCycle(['media' => $media, 'pressure' => ['blend' => null, 'iowait_blend' => null]])['logs']['PMSS_DYNAMIC_IOPS_LOG'][0];
            $this->assertSame('missing-pressure', $row['fallback']);
            $this->assertSame('keep-kernel', $row['read_action']);
        }
    }

    public function testPreviewWritesNoProbeStateAndSolidStateKeepsItsBand(): void
    {
        foreach ([[[], ['--dry-run']], [['envDryRun' => '1'], []]] as [$options, $args]) {
            $row = $this->enforceCycle($options, $args);
            $this->assertSame([], $row['stateWrites']);
            $this->assertSame([], $row['writes']);
        }
        $normal = $this->enforceCycle();
        $this->assertSame('probe', $normal['logs']['PMSS_DYNAMIC_IOPS_LOG'][0]['steer']);
        $this->assertSame(5.0, (float) $normal['stateWrites'][0][1]['lo']);
        foreach (['ssd', 'nvme'] as $media) {
            $row = $this->enforceCycle(['media' => $media, 'pressure' => ['blend' => 15.0, 'latency_ms' => 0.2]]);
            // The fixture supplies no reference: ADR 0237 keeps pressure steering.
            $this->assertSame('pressure', $row['logs']['PMSS_DYNAMIC_IOPS_LOG'][0]['steer']);
            $this->assertSame([], $row['stateWrites']);
        }
    }
}
