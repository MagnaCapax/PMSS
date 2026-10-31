<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/liveStats.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/statsLive.php';

/** Live stats: node/user samples, ring buffer, and the customer-side model. */
class LiveStatsTest extends TestCase
{
    private function stat(int $busyBase): string
    {
        // user nice system idle iowait irq softirq steal
        return "cpu  {$busyBase} 0 100 800 100 0 0 0 0 0\ncpu0 1 0 0 1 0 0 0 0 0 0\ncpu1 1 0 0 1 0 0 0 0 0 0\n";
    }

    private function netDev(int $rx, int $tx): string
    {
        return "Inter-|   Receive\n face |bytes\n    lo: 5 1 0 0 0 0 0 0 5 1 0 0 0 0 0 0\n  eth0: {$rx} 10 0 0 0 0 0 0 {$tx} 10 0 0 0 0 0 0\n";
    }

    public function testNetworkCapacityUsesDetectionAndEgressShapingWithoutGuesses(): void
    {
        foreach ([[10000, 1000, 1000, 10000], [1000, 10000, 1000, 1000], [10000, 0, 10000, 10000],
            [0, 2000, 2000, 0], [0, 0, 0, 0], [-1, -1, 0, 0]] as $case) {
            $capacity = \pmssLiveStatsNetworkCapacity($case[0], $case[1]);
            $this->assertSame(['tx_capacity' => $case[2], 'rx_capacity' => $case[3]], $capacity);
            $sample = ['t' => 1000, 'tx' => 100, 'rx' => 200] + $capacity;
            $this->assertSame([$sample], json_decode(\pmssLiveStatsRingEncode([], $sample), true)['samples']);
        }
    }

    public function testNetworkBarsScaleToCapacityAndKeepTheOwnShareInsideNode(): void
    {
        foreach ([[500.0, 50.0, 10000, '5'], [5.0, 0.5, 10000, '0.05'], [5000.0, 500.0, 10000, '50'],
            [15000.0, 1500.0, 10000, '100'], [0.0, 0.0, 10000, '0']] as $case) {
            $html = \pmssStatsLiveCell($case[0], $case[1], ' Mbit/s', $case[2]);
            $this->assertStringContainsAllStrings(['live-node" style="width:'.$case[3].'%',
                'live-you" style="width:'.($case[0] > 0 ? 10 : 0).'%'], $html);
        }
        $this->assertStringContainsString('live-node" style="width:37%', \pmssStatsLiveCell(37.0, 5.0, '%'));
    }

    public function testUnknownNetworkCapacityShowsNumbersWithoutABar(): void
    {
        foreach ([0, -1] as $capacity) {
            $html = \pmssStatsLiveCell(500.0, 50.0, ' Mbit/s', $capacity);
            $this->assertFalse(strpos($html, 'class="live-bar"') !== false);
            $this->assertStringContainsAllStrings(['node 500 Mbit/s', 'you 50 Mbit/s'], $html);
        }
        // Old rings have no capacity fields, so the optional argument must also hide the bar.
        $this->assertFalse(strpos(\pmssStatsLiveCell(5.0, 0.5, ' Mbit/s'), 'class="live-bar"') !== false);
    }

    public function testNetworkTrendUsesCapacityAndHidesLinesWhenUnknown(): void
    {
        $points = [[500.0, 50.0], [5000.0, 500.0]];
        $svg = \pmssStatsLiveSvg($points, 10000);
        $this->assertStringContainsAllStrings(['class="live-node" points="0,36.2 300,20"', 'class="live-you" points="0,37.8 300,36.2"'], $svg);
        $this->assertStringContainsString('points="0,2 300,2"', \pmssStatsLiveSvg([[10000, 1000], [15000, 1500]], 10000));
        foreach ([0, -1] as $capacity) {
            $this->assertFalse(strpos(\pmssStatsLiveSvg($points, $capacity), '<polyline') !== false);
        }
    }

    public function testNodeCountersParseBusyTotalMemoryAndNetwork(): void
    {
        $meminfo = "MemTotal:       1000 kB\nMemAvailable:    400 kB\n";
        $c = \pmssLiveStatsNodeCountersParse($this->stat(100), $meminfo, $this->netDev(7000, 3000), 'eth0');
        $this->assertSame(1100, $c['total']);
        $this->assertSame(200, $c['busy']); // total - idle - iowait
        $this->pmssAssertArraySubsetSame([
            'mem_total' => 1024000, 'mem_used' => 614400, 'rx' => 7000, 'tx' => 3000, 'steal' => 0,
        ], $c);
        $this->assertSame(null, \pmssLiveStatsNodeCountersParse($this->stat(100), $meminfo, $this->netDev(1, 1), 'nope0'));
        $this->assertSame(null, \pmssLiveStatsNodeCountersParse('garbage', $meminfo, $this->netDev(1, 1), 'eth0'));
    }

    public function testNodeSampleIsDeltaAndRejectsResetsAndBadIntervals(): void
    {
        $prev = ['time' => 1000, 'busy' => 100, 'total' => 1000, 'rx' => 10, 'tx' => 20, 'mem_used' => 1, 'mem_total' => 2];
        $cur = ['time' => 1300, 'busy' => 400, 'total' => 1600, 'rx' => 110, 'tx' => 220, 'mem_used' => 5, 'mem_total' => 8];
        $s = \pmssLiveStatsNodeSample($prev, $cur, 1300);
        $this->assertEquals(['t' => 1300, 'dt' => 300, 'cb' => 3.0, 'ct' => 6.0, 'mu' => 5, 'mt' => 8, 'tx' => 200, 'rx' => 100], $s);
        $this->assertSame(null, \pmssLiveStatsNodeSample($prev, ['busy' => 1, 'total' => 1, 'rx' => 1, 'tx' => 1] + $cur, 1300), 'counter reset');
        $this->assertSame(null, \pmssLiveStatsNodeSample($prev, $cur, 1010), 'interval too short');
        $this->assertSame(null, \pmssLiveStatsNodeSample($prev, $cur, 9000), 'interval too long');
    }

    public function testNodeSampleCarriesStealOnlyWhenBothSnapshotsHaveIt(): void
    {
        $prev = ['time' => 1000, 'busy' => 100, 'total' => 1000, 'rx' => 10, 'tx' => 20, 'mem_used' => 1, 'mem_total' => 2, 'steal' => 50];
        $cur = ['time' => 1300, 'busy' => 400, 'total' => 1600, 'rx' => 110, 'tx' => 220, 'mem_used' => 5, 'mem_total' => 8, 'steal' => 80];
        $this->assertSame(0.3, \pmssLiveStatsNodeSample($prev, $cur, 1300)['cs']);
        unset($prev['steal']);
        $this->assertFalse(array_key_exists('cs', \pmssLiveStatsNodeSample($prev, $cur, 1300)));
        $prev['steal'] = 90;
        $this->assertFalse(array_key_exists('cs', \pmssLiveStatsNodeSample($prev, $cur, 1300)), 'steal counter went back');
    }

    public function testRingKeepsNewestSlotsAndReplacesDuplicateSlot(): void
    {
        $ring = [];
        foreach ([1, 2, 3, 4, 5] as $t) {
            $ring = json_decode(\pmssLiveStatsRingEncode($ring, ['t' => $t], 3), true)['samples'];
        }
        $this->assertSame([3, 4, 5], array_column($ring, 't'));
        $ring = json_decode(\pmssLiveStatsRingEncode($ring, ['t' => 5, 'x' => 1], 3), true)['samples'];
        $this->assertSame([3, 4, 5], array_column($ring, 't'));
        $this->assertSame(1, $ring[2]['x']);
    }

    public function testRingReadRefusesSymlinkAndMalformedFiles(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-livestats');
        file_put_contents($dir.'/ok.json', \pmssLiveStatsRingEncode([], ['t' => 1]));
        file_put_contents($dir.'/bad.json', '{not json');
        symlink($dir.'/ok.json', $dir.'/link.json');
        $this->assertSame([['t' => 1]], \pmssLiveStatsRingRead($dir.'/ok.json'));
        $this->assertSame([['t' => 1]], \pmssStatsLiveRingRead($dir.'/ok.json'));
        foreach (['bad.json', 'link.json', 'missing.json'] as $file) {
            $this->assertSame([], \pmssLiveStatsRingRead($dir.'/'.$file), $file);
            $this->assertSame([], \pmssStatsLiveRingRead($dir.'/'.$file), $file);
        }
    }

    public function testUserSampleReadsExistingLogsAndIgnoresStaleLines(): void
    {
        $root = $this->pmssMakeTempDir('pmss-livestats');
        $now = strtotime('2026-10-17 10:00:00');
        $this->pmssWriteFiles($root, [
            'resources/fixtur01' => "2026-10-17 09:55:01 0 0 0 0 1000 1 1 1 1\n2026-10-17 09:59:58 0 0 0 0 5000000000 2048 7 1024 1024\n",
            'traffic/fixtur01' => "2026-10-17 09:59:59: 12345\n", 'traffic-ingress/fixtur01' => "2026-10-17 09:40:00: 999\n", // stale
        ]);
        $s = \pmssLiveStatsUserSample('fixtur01', $now, $root);
        $this->assertEquals(['t' => $now, 'cpu' => 5.0, 'mem' => 2048, 'tx' => 12345, 'rx' => 0], $s);
        $this->assertEquals(['t' => $now, 'cpu' => 0.0, 'mem' => 0, 'tx' => 0, 'rx' => 0], \pmssLiveStatsUserSample('fixtur02', $now, $root));
    }

    public function testUserSampleNeverCountsTheSameLogLineTwiceAndIgnoresPartialLines(): void
    {
        $root = $this->pmssMakeTempDir('pmss-livestats');
        $this->pmssEnsureDir($root.'/traffic-ingress');
        $now = strtotime('2026-10-17 10:00:30');
        $this->pmssWriteFiles($root, [
            'resources/fixtur01' => "2026-10-17 09:59:58 0 0 0 0 5000000000 2048 7 1024 1024\n",
            'traffic/fixtur01' => "2026-10-17 09:59:59: 12345\n2026-10-17 10:00:20: 99",
        ]);
        $first = \pmssLiveStatsUserSample('fixtur01', $now, $root, 0);
        $this->assertSame(12345, $first['tx'], 'half-written last line is ignored');
        $again = \pmssLiveStatsUserSample('fixtur01', $now + 60, $root, $now);
        $this->assertEquals(['t' => $now + 60, 'cpu' => 0.0, 'mem' => 0, 'tx' => 0, 'rx' => 0], $again, 'already consumed');
    }

    public function testRingDropsFutureSamplesAfterAClockStepBack(): void
    {
        $ring = json_decode(\pmssLiveStatsRingEncode([['t' => 100], ['t' => 900]], ['t' => 500]), true)['samples'];
        $this->assertSame([100, 500], array_column($ring, 't'));
    }

    public function testModelJoinsSlotsAndComputesNodeAndOwnShare(): void
    {
        $node = [];
        $user = [];
        foreach ([1000, 1300, 1600] as $t) {
            $node[] = ['t' => $t, 'dt' => 300, 'cb' => 300.0, 'ct' => 600.0, 'mu' => 500, 'mt' => 1000, 'tx' => 3750000, 'rx' => 1875000];
            $user[] = ['t' => $t, 'cpu' => 60.0, 'mem' => 100, 'tx' => 375000, 'rx' => 0];
        }
        $rows = \pmssStatsLiveRows($node, $user, 1600, 400);
        $this->assertSame(2, count($rows)); // 1000 falls outside the 400 s window
        $s = \pmssStatsLiveSummary($rows);
        $this->pmssAssertArraySubsetSame([
            'cpu_node' => 50.0, 'cpu_you' => 10.0, 'ram_node' => 50.0, 'ram_you' => 10.0,
        ], $s);
        $this->assertSame(0.1, round($s['tx_node'], 1));
        $this->assertSame(0.01, round($s['tx_you'], 2));
        $this->assertSame(null, \pmssStatsLiveSummary([]));
    }

    public function testModelExposesOnlyNodeTotalsAndOwnValues(): void
    {
        $node = [['t' => 100, 'dt' => 300, 'cb' => 1.0, 'ct' => 2.0, 'mu' => 1, 'mt' => 2, 'tx' => 1, 'rx' => 1]];
        $s = \pmssStatsLiveSummary(\pmssStatsLiveRows($node, [['t' => 100, 'cpu' => 1.0]], 100, 600));
        $this->assertSame(['cpu_node', 'cpu_you', 'ram_node', 'ram_you', 'tx_node', 'tx_you', 'rx_node', 'rx_you'], array_keys($s));
    }

    public function testSeriesDownsamplesToRequestedPoints(): void
    {
        $rows = [];
        for ($i = 0; $i < 288; $i++) {
            $rows[] = ['node' => ['t' => $i, 'dt' => 300, 'cb' => 1.0, 'ct' => 2.0, 'mu' => 1, 'mt' => 2, 'tx' => 1, 'rx' => 1], 'user' => []];
        }
        $series = \pmssStatsLiveSeries($rows, 96);
        $this->assertSame(96, count($series['cpu']));
        $this->assertSame([], \pmssStatsLiveSeries([], 96)['cpu']);
    }

    public function testCustomerFilesNeverRequireOperatorTree(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/www/statsLive.php');
        $this->assertFalse((bool) preg_match('~(require|include)(_once)?[^;]*/scripts/~', $source), 'statsLive.php must not touch /scripts');
    }
}
