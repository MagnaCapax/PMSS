<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/ioCeilingHistory.php';

class IoCeilingTest extends TestCase
{
    private const DAY = 86400;
    private const NOW = 1704844800; // 2024-01-10 00:00 UTC.

    private function policy(array $settings = []): array
    {
        return ['ioCeiling' => $settings + ['minSamplesPerDay' => 20, 'minDays' => 1]];
    }

    private function samples(int $daysAgo, array $values): array
    {
        $rows = [];
        foreach ($values as $i => $value) {
            $rows[] = ['time' => self::NOW - $daysAgo * self::DAY + $i * 300,
                'iopsRead' => $value, 'iopsWrite' => $value,
                'throughputRead' => $value, 'throughputWrite' => $value];
        }
        return $rows;
    }

    private function history(array $rows): string
    {
        return implode('', array_map(static function (array $row): string {
            return '1999-01-01 00:00:00 || '.serialize($row)."\n";
        }, $rows));
    }

    public function testQuietDayCannotCraterWholeWindowPercentile(): void
    {
        $busy = $this->samples(2, array_merge(array_fill(0, 18, 1), [100, 100]));
        $quiet = $this->samples(1, array_fill(0, 20, 1));
        $result = \pmssIoCeilingCompute(array_merge($busy, $quiet), $this->policy(), self::NOW);
        $this->assertSame(100.0, $result['published']['read_iops']);
        $this->assertSame('2024-01-08', $result['published']['from_day']['read_iops']);
    }

    public function testCurrentDayBurstAndQuietSamplesNeverChangePublication(): void
    {
        $prior = $this->samples(1, array_fill(0, 20, 5));
        $burst = array_merge(array_fill(0, 90, 1), array_fill(0, 10, 100));
        foreach ([$burst, array_merge($burst, array_fill(0, 110, 1))] as $values) {
            $result = \pmssIoCeilingCompute(array_merge($prior, $this->samples(0, $values)), $this->policy(), self::NOW + 80000);
            $this->assertSame(5.0, $result['published']['read_iops']);
            $this->assertSame(['2024-01-09'], array_keys($result['days']));
            $this->assertSame('2024-01-10', $result['excluded_current_day']);
        }
    }

    public function testCeilingFallsOnlyWhenWinningDayExpires(): void
    {
        $rows = array_merge($this->samples(7, array_fill(0, 20, 100)), $this->samples(1, array_fill(0, 20, 1)));
        $this->assertSame(100.0, \pmssIoCeilingCompute($rows, $this->policy(), self::NOW)['published']['read_iops']);
        $this->assertSame(1.0, \pmssIoCeilingCompute($rows, $this->policy(), self::NOW + self::DAY)['published']['read_iops']);
    }

    public function testNearestRankAndIndependentDimensionOrigins(): void
    {
        $rows = $this->samples(2, range(1, 20));
        $other = $this->samples(1, array_fill(0, 20, 1));
        foreach ($other as &$row) {
            $row['iopsWrite'] = '99.5';
            $row['throughputWrite'] = '0';
        }
        unset($row);
        $result = \pmssIoCeilingCompute(array_merge($rows, $other), $this->policy(), self::NOW);
        $this->assertSame(19.0, $result['published']['read_iops']);
        $this->assertSame(99.5, $result['published']['write_iops']);
        $this->assertSame('2024-01-09', $result['published']['from_day']['write_iops']);
        $this->assertSame(1.0, \pmssIoCeilingCompute($rows, $this->policy(['percentile' => 1]), self::NOW)['published']['read_mbs']);
        $this->assertSame(20.0, \pmssIoCeilingCompute($rows, $this->policy(['percentile' => 100]), self::NOW)['published']['write_mbs']);
    }

    public function testThinMissingExpiredFutureAndDuplicateSamplesPublishNothing(): void
    {
        $thin = $this->samples(1, array_fill(0, 19, 1));
        foreach ([[], $thin, array_merge($thin, $thin), $this->samples(8, range(1, 20)), $this->samples(-1, range(1, 20))] as $rows) {
            $this->assertSame(null, \pmssIoCeilingCompute($rows, $this->policy(), self::NOW));
        }
        $this->assertSame(null, \pmssIoCeilingCompute($this->samples(1, range(1, 20)), $this->policy(['minDays' => 2]), self::NOW));
    }

    public function testInvalidConfigAndMalformedDimensionsFailClosed(): void
    {
        foreach (['percentile' => [0, 101, '95', null], 'windowDays' => [0, 32], 'minDays' => [0, 8], 'minSamplesPerDay' => [0, 289]] as $key => $values) {
            foreach ($values as $value) {
                $this->assertSame(null, \pmssIoCeilingSettings($this->policy([$key => $value])));
            }
        }
        foreach ([null, false, [], new \stdClass(), -1, INF, NAN, 'bad', '1e999'] as $value) {
            $rows = $this->samples(1, array_fill(0, 20, 1));
            $rows[0]['throughputRead'] = $value;
            $this->assertSame(null, \pmssIoCeilingCompute($rows, $this->policy(), self::NOW));
        }
    }

    public function testDefaultGatesRequireThreeHalfDaysOfSamples(): void
    {
        $rows = [];
        for ($day = 1; $day <= 3; ++$day) {
            $rows = array_merge($rows, $this->samples($day, array_fill(0, 144, 0)));
            $result = \pmssIoCeilingCompute($rows, [], self::NOW);
            $this->assertSame($day === 3, $result !== null);
        }
        $this->assertSame(0.0, $result['published']['read_iops']);
    }

    public function testRefreshCombinesRotationAndLiveLogAndClearsStaleCache(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $history = $dir.'/history';
        $state = $dir.'/state.json';
        $rows = $this->samples(1, range(1, 20));
        $this->pmssWriteFile($history.'.1', $this->history(array_slice($rows, 0, 10)));
        $this->pmssWriteFile($history, "bad\n".$this->history(array_slice($rows, 10)).'partial');
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $state, self::NOW, $dir.'/anchor.json'));
        $result = $this->pmssReadJsonArrayFile($state);
        $this->assertEquals(19, $result['published']['read_iops']);
        $this->assertSame(19.0, \pmssIoCeilingPublishedReadIops($state));
        $this->assertSame(20, $result['days']['2024-01-09']['samples']);
        unlink($history.'.1');
        // A compressed-only remainder fails the sample gate; stale values disappear.
        $this->pmssWriteFile($history.'.2.gz', gzencode($this->history($rows)));
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $state, self::NOW, $dir.'/anchor.json'));
        $this->assertFalse(file_exists($state));
        unlink($history);
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $state, self::NOW, $dir.'/anchor.json'));
        $this->assertFalse(file_exists($state));
    }

    public function testReaderSkipsObjectsOversizedAndPartialRecords(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $path = $dir.'/history';
        $valid = $this->history($this->samples(1, [1]));
        $this->pmssWriteFile($path, str_repeat('x', 9000).$valid."bad\n1999-01-01 00:00:00 || O:8:\"stdClass\":0:{}\n".$valid.substr($valid, 0, -1));
        $this->assertSame(1, count(iterator_to_array(\pmssIoCeilingHistorySamples($path))));
        $this->pmssWriteSymlinks($dir, ['link' => $path]);
        $this->assertSame([], iterator_to_array(\pmssIoCeilingHistorySamples($dir.'/link')));
        $this->assertFalse(\pmssIoCeilingRefresh([], $path, $dir.'/link', self::NOW, $dir.'/anchor.json'));
    }

    public function testByteBudgetDropsCutDayAndTimezoneDoesNotAffectBucketing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $path = $dir.'/history';
        $this->pmssWriteFile($path, str_repeat('x', 16 * 1024 * 1024)."\n"
            .$this->history($this->samples(2, [999])).$this->history($this->samples(1, range(1, 20))));
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $result = \pmssIoCeilingCompute(\pmssIoCeilingHistorySamples($path), $this->policy(), self::NOW);
            $this->assertSame(['2024-01-09'], array_keys($result['days']));
            $this->assertSame(19.0, $result['published']['read_iops']);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    /** ADR 0239: while read caps are enforced the published read ceiling may rise but never falls. */
    public function testEnforcedReadCeilingRisesButNeverFalls(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $history = $dir.'/history';
        $state = $dir.'/state.json';
        $anchor = $dir.'/anchor.json';
        $off = $this->policy() + ['cgroup' => ['dynamicIops' => ['mode' => 'off']]];
        $refresh = function (array $policy, int $now) use ($history, $state, $anchor): array {
            $this->assertTrue(\pmssIoCeilingRefresh($policy, $history, $state, $now, $anchor));
            return $this->pmssReadJsonArrayFile($state);
        };
        $this->pmssWriteFile($history, $this->history(array_merge($this->samples(7, array_fill(0, 20, 600)), $this->samples(1, array_fill(0, 20, 80)))));
        $this->assertSame(600.0, (float) $refresh($this->policy(), self::NOW)['published']['read_iops']);
        // The uncapped 600 day leaves the window; the capped 80 day alone would publish 80.
        $result = $refresh($this->policy(), self::NOW + self::DAY);
        $this->assertSame(600.0, (float) $result['published']['read_iops']);
        $this->assertSame('2024-01-03', $result['published']['from_day']['read_iops']);
        $this->assertTrue($result['read_iops_anchored']);
        $this->assertSame(600.0, \pmssIoCeilingPublishedReadIops($state));
        // Without enforcement the measured reads are not cut, so the ceiling and the anchor follow them down.
        $result = $refresh($off, self::NOW + self::DAY);
        $this->assertSame(80.0, (float) $result['published']['read_iops']);
        $this->assertFalse(isset($result['read_iops_anchored']));
        $this->assertSame(80.0, (float) $refresh($this->policy(), self::NOW + self::DAY)['published']['read_iops']);
        // A higher day raises it and the anchor keeps the new value.
        file_put_contents($history, $this->history($this->samples(0, array_fill(0, 20, 900))), FILE_APPEND);
        $this->assertSame(900.0, (float) $refresh($this->policy(), self::NOW + self::DAY)['published']['read_iops']);
        $this->assertSame(900.0, (float) $this->pmssReadJsonArrayFile($anchor)['read_iops']);
        // A malformed anchor is ignored and rewritten.
        $this->pmssWriteFile($anchor, json_encode(['read_iops' => 5000]));
        $this->assertSame(900.0, (float) $refresh($this->policy(), self::NOW + self::DAY)['published']['read_iops']);
        $this->assertSame('2024-01-10', $this->pmssReadJsonArrayFile($anchor)['from_day']);
        // Thin history removes the publication and leaves the anchor alone.
        unlink($history);
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $state, self::NOW + self::DAY, $anchor));
        $this->assertFalse(file_exists($state));
        $this->assertSame(900.0, (float) $this->pmssReadJsonArrayFile($anchor)['read_iops']);
    }

    /** ADR 0239: the first run anchors the value already published, so an update itself changes no cap. */
    public function testFirstRunAnchorsTheCurrentValue(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $history = $dir.'/history';
        $this->pmssWriteFile($history, $this->history(array_merge($this->samples(7, array_fill(0, 20, 600)), $this->samples(1, array_fill(0, 20, 80)))));
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $dir.'/state.json', self::NOW + self::DAY, $dir.'/anchor.json'));
        $result = $this->pmssReadJsonArrayFile($dir.'/state.json');
        $this->assertSame((float) \pmssIoCeilingCompute(\pmssIoCeilingHistorySamples($history), $this->policy(), self::NOW + self::DAY)['published']['read_iops'], (float) $result['published']['read_iops']);
        $this->assertFalse(isset($result['read_iops_anchored']));
        $this->assertSame(80.0, (float) $this->pmssReadJsonArrayFile($dir.'/anchor.json')['read_iops']);
    }

    /** ADR 0239 update: the enforced anchor holds 14 days, then falls 10% a day to half its highest value; measured demand above it wins. */
    public function testEnforcedAnchorDecaysAfterItsHoldToAFloor(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $history = $dir.'/history';
        $state = $dir.'/state.json';
        $anchor = $dir.'/anchor.json';
        // 2024-01-03 at 600 (uncapped), then every day through 2024-02-19 at 80, except 2024-01-20 at 400.
        $rows = $this->samples(7, array_fill(0, 20, 600));
        for ($ago = 6; $ago >= -40; --$ago) {
            $rows = array_merge($rows, $this->samples($ago, array_fill(0, 20, $ago === -10 ? 400 : 80)));
        }
        $this->pmssWriteFile($history, $this->history($rows));
        $refresh = function (int $days, ?array $policy = null) use ($history, $state, $anchor): array {
            $this->assertTrue(\pmssIoCeilingRefresh($policy ?? $this->policy(), $history, $state, self::NOW + $days * self::DAY, $anchor));
            return $this->pmssReadJsonArrayFile($state);
        };
        $kept = function () use ($anchor): array {
            return $this->pmssReadJsonArrayFile($anchor);
        };
        // 2024-01-10: the first run anchors 600 (measured 2024-01-03).
        $this->assertSame(600.0, (float) $refresh(0)['published']['read_iops']);
        $this->assertSame(600.0, (float) $kept()['peak_read_iops']);
        // 2024-01-17, 14 days old: still held in full.
        $result = $refresh(7);
        $this->assertSame(600.0, (float) $result['published']['read_iops']);
        $this->assertTrue($result['read_iops_anchored']);
        // 2024-01-18, day 15: one 10% step; the anchor file keeps its value and day so its age keeps counting.
        $result = $refresh(8);
        $this->assertTrue(abs(540.0 - (float) $result['published']['read_iops']) < 0.001);
        $this->assertTrue($result['read_iops_anchored']);
        $this->assertSame('2024-01-03', $result['published']['from_day']['read_iops']);
        $this->assertSame([600.0, '2024-01-03'], [(float) $kept()['read_iops'], $kept()['from_day']]);
        $this->assertTrue(abs(540.0 - (float) \pmssIoCeilingPublishedReadIops($state)) < 0.001);
        // 2024-01-23: the 400 day (2024-01-20) beats the decayed 318.9, so the anchor moves to the measurement.
        $result = $refresh(13);
        $this->assertSame(400.0, (float) $result['published']['read_iops']);
        $this->assertFalse(isset($result['read_iops_anchored']));
        $this->assertSame([400.0, '2024-01-20', 600.0], [(float) $kept()['read_iops'], $kept()['from_day'], (float) $kept()['peak_read_iops']]);
        // 2024-02-20, quiet for weeks: the floor is half of the highest value ever held, not of the current anchor.
        $result = $refresh(41);
        $this->assertSame(300.0, (float) $result['published']['read_iops']);
        $this->assertTrue($result['read_iops_anchored']);
        $this->assertSame(300.0, (float) $refresh(41)['published']['read_iops']);
        // Without enforcement the anchor and the highest value both follow measured demand.
        $off = $this->policy() + ['cgroup' => ['dynamicIops' => ['mode' => 'off']]];
        $this->assertSame(80.0, (float) $refresh(41, $off)['published']['read_iops']);
        $this->assertSame([80.0, 80.0], [(float) $kept()['read_iops'], (float) $kept()['peak_read_iops']]);
        // An anchor written before ADR 0244 has no highest value: it counts as its own.
        $this->pmssWriteFile($anchor, json_encode(['read_iops' => 1000, 'from_day' => '2024-01-03']));
        $this->assertSame(500.0, (float) $refresh(41)['published']['read_iops']);
        // An unreadable day keeps the value held in full.
        $this->assertSame(1000.0, \pmssIoCeilingAnchorEffective(1000.0, 1000.0, 'last week', self::NOW));
        $this->assertSame(1000.0, \pmssIoCeilingAnchorEffective(1000.0, 1000.0, '2099-01-01', self::NOW));
    }

    /** ADR 0237: the disk's quiet floor is the median of each accepted day's 10th-percentile /home read latency. */
    public function testQuietLatencyFloorIsTheMedianOfDailyTenthPercentiles(): void
    {
        $day = function (int $ago, float $low, int $missed = 0): array {
            $rows = $this->samples($ago, array_fill(0, 20, 50));
            foreach ($rows as $i => $row) $rows[$i]['iopingHomeMs'] = $i < $missed ? null : ($i < $missed + 2 ? $low : 9.0);
            return $rows;
        };
        $result = \pmssIoCeilingCompute(array_merge($day(1, 0.2), $day(2, 0.3), $day(3, 5.0)), $this->policy(), self::NOW);
        $this->assertSame(0.3, $result['ioping_floor_ms']);
        // A day short of probes keeps its ceiling row but gives no floor; a missed probe never drops the row.
        $result = \pmssIoCeilingCompute(array_merge($day(1, 0.2), $day(2, 0.3), $day(3, 5.0), $day(4, 0.1, 1)), $this->policy(), self::NOW);
        $this->assertSame(4, count($result['days']));
        $this->assertSame(50.0, $result['published']['read_iops']);
        $this->assertSame(0.3, $result['ioping_floor_ms']);
        $this->assertSame(0.25, \pmssIoCeilingCompute(array_merge($day(1, 0.2), $day(2, 0.3)), $this->policy(), self::NOW)['ioping_floor_ms']);
        // Complete probes on a rejected ceiling day cannot enter the latency floor.
        $rejected = $day(3, 0.01); $rejected[0]['throughputRead'] = null;
        $this->assertSame(0.25, \pmssIoCeilingCompute(array_merge($day(1, 0.2), $day(2, 0.3), $rejected), $this->policy(), self::NOW)['ioping_floor_ms']);
        // Without any probe the read ceiling still publishes and the floor is absent.
        $result = \pmssIoCeilingCompute($this->samples(1, array_fill(0, 20, 50)), $this->policy(), self::NOW);
        $this->assertSame(50.0, $result['published']['read_iops']);
        $this->assertSame(null, $result['ioping_floor_ms']);
    }
}
