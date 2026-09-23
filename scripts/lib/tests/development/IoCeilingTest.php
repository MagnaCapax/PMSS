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
        file_put_contents($history.'.1', $this->history(array_slice($rows, 0, 10)));
        file_put_contents($history, "bad\n".$this->history(array_slice($rows, 10)).'partial');
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $state, self::NOW));
        $result = json_decode(file_get_contents($state), true);
        $this->assertEquals(19, $result['published']['read_iops']);
        $this->assertSame(19.0, \pmssIoCeilingPublishedReadIops($state));
        $this->assertSame(20, $result['days']['2024-01-09']['samples']);
        unlink($history.'.1');
        // A compressed-only remainder fails the sample gate; stale values disappear.
        file_put_contents($history.'.2.gz', gzencode($this->history($rows)));
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $state, self::NOW));
        $this->assertFalse(file_exists($state));
        unlink($history);
        $this->assertTrue(\pmssIoCeilingRefresh($this->policy(), $history, $state, self::NOW));
        $this->assertFalse(file_exists($state));
    }

    public function testReaderSkipsObjectsOversizedAndPartialRecords(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $path = $dir.'/history';
        $valid = $this->history($this->samples(1, [1]));
        file_put_contents($path, str_repeat('x', 9000).$valid."bad\n1999-01-01 00:00:00 || O:8:\"stdClass\":0:{}\n".$valid.substr($valid, 0, -1));
        $this->assertSame(1, count(iterator_to_array(\pmssIoCeilingHistorySamples($path))));
        symlink($path, $dir.'/link');
        $this->assertSame([], iterator_to_array(\pmssIoCeilingHistorySamples($dir.'/link')));
        $this->assertFalse(\pmssIoCeilingRefresh([], $path, $dir.'/link', self::NOW));
    }

    public function testByteBudgetDropsCutDayAndTimezoneDoesNotAffectBucketing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-io-ceiling-');
        $path = $dir.'/history';
        file_put_contents($path, str_repeat('x', 16 * 1024 * 1024)."\n"
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
}
