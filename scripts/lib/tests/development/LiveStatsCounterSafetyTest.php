<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/liveStats.php';

/** Counter boundaries in liveStats.php use text fixtures only; no host probes. */
class LiveStatsCounterSafetyTest extends TestCase
{
    /** Keep the proc layout fixed while varying only the counter under review. */
    private function parseCounters(array $overrides = []): ?array
    {
        $values = $overrides + ['cpu' => array_fill(0, 8, '0'), 'total' => '1000',
            'available' => '400', 'rx' => '7000', 'tx' => '3000'];
        return \pmssLiveStatsNodeCountersParse(
            'cpu  '.implode(' ', $values['cpu'])."\n",
            'MemTotal: '.$values['total']." kB\nMemAvailable: ".$values['available']." kB\n",
            ' eth0: '.$values['rx'].' 1 0 0 0 0 0 0 '.$values['tx']." 1 0 0 0 0 0 0\n",
            'eth0'
        );
    }

    public function testCpuRejectsOverflowInEveryCountedFieldAndInTheSum(): void
    {
        $overflow = substr((string) PHP_INT_MAX, 0, -1).'8'; // Both supported integer widths end in 7.
        foreach (range(0, 7) as $index) {
            foreach ([$overflow, '000'.$overflow, (string) PHP_INT_MAX.'0'] as $raw) {
                $cpu = array_fill(0, 8, '0');
                $cpu[$index] = $raw;
                $this->assertSame(null, $this->parseCounters(['cpu' => $cpu]), 'oversized tick '.$index);
            }
            // Both summands fit individually, including when the maximum is last.
            $cpu = array_fill(0, 8, '0');
            $cpu[$index] = (string) PHP_INT_MAX;
            $cpu[($index + 1) % 8] = '1';
            $this->assertSame(null, $this->parseCounters(['cpu' => $cpu]), 'oversized total '.$index);
        }
    }

    public function testCpuRetainsExactLimitsPaddingAndIgnoredGuestFields(): void
    {
        foreach (range(0, 7) as $index) {
            $cpu = array_fill(0, 8, '0');
            $cpu[$index] = '000'.PHP_INT_MAX;
            $result = $this->parseCounters(['cpu' => $cpu]);
            $this->assertSame(PHP_INT_MAX, $result['total']);
            $this->assertSame(in_array($index, [3, 4], true) ? 0 : PHP_INT_MAX, $result['busy']);
            $this->assertSame($index === 7 ? PHP_INT_MAX : 0, $result['steal']);
        }
        foreach (range(4, 10) as $count) {
            $cpu = array_fill(0, $count, '1');
            if ($count > 8) $cpu[8] = (string) PHP_INT_MAX.'0';
            if ($count > 9) $cpu[9] = (string) PHP_INT_MAX.'0';
            $result = $this->parseCounters(['cpu' => $cpu]);
            $this->assertSame(min($count, 8), $result['total']);
            $this->assertSame($count >= 8 ? 1 : 0, $result['steal']);
        }
    }

    public function testMemoryAndNetworkRejectOversizedCountersAndByteConversions(): void
    {
        $overflow = substr((string) PHP_INT_MAX, 0, -1).'8';
        foreach (['total', 'available', 'rx', 'tx'] as $field) {
            foreach ([$overflow, '000'.$overflow, (string) PHP_INT_MAX.'0', str_repeat('9', 400)] as $raw) {
                $this->assertSame(null, $this->parseCounters([$field => $raw]), $field);
            }
        }
        foreach (['total', 'available'] as $field) {
            foreach ([(string) (intdiv(PHP_INT_MAX, 1024) + 1), (string) PHP_INT_MAX] as $raw) {
                $this->assertSame(null, $this->parseCounters([$field => $raw]), $field.' byte overflow');
            }
        }
    }

    public function testRepresentableMemoryAndNetworkKeepExactValuesAndFieldOrder(): void
    {
        $limitKiB = intdiv(PHP_INT_MAX, 1024);
        foreach (['0', '0001', (string) $limitKiB] as $memory) {
            foreach (['0', '0001', '000'.PHP_INT_MAX] as $bytes) {
                $this->assertSame(['busy' => 0, 'total' => 0, 'mem_total' => (int) $memory * 1024,
                    'mem_used' => 0, 'rx' => (int) $bytes, 'tx' => (int) $bytes, 'steal' => 0],
                    $this->parseCounters(['total' => $memory, 'available' => $memory, 'rx' => $bytes, 'tx' => $bytes]));
            }
        }
        $this->assertSame(614400, $this->parseCounters()['mem_used']);
        $this->assertSame($limitKiB * 1024, $this->parseCounters(['total' => (string) $limitKiB, 'available' => '0'])['mem_used']);
        $this->assertSame(-$limitKiB * 1024, $this->parseCounters(['total' => '0', 'available' => (string) $limitKiB])['mem_used']);
    }

    public function testTrafficCountersRetainFreshnessAndRejectOverflow(): void
    {
        $timestamp = '2026-10-17 10:00:00';
        $now = strtotime($timestamp);
        $overflow = substr((string) PHP_INT_MAX, 0, -1).'8';
        foreach (['0' => 0, '0001' => 1, (string) PHP_INT_MAX => PHP_INT_MAX,
            '000'.PHP_INT_MAX => PHP_INT_MAX, (string) PHP_INT_MAX.'0' => 0,
            $overflow => 0, '000'.$overflow => 0, str_repeat('9', 400) => 0,
            '-1' => 0, '1.5' => 0, '1e3' => 0, '' => 0] as $raw => $expected) {
            $this->assertSame($expected, \pmssLiveStatsTraffic($timestamp.': '.$raw, $now), (string) $raw);
        }
        foreach ([null, '', 'invalid', '2026-10-17 09:00:00: 123'] as $line) {
            $this->assertSame(0, \pmssLiveStatsTraffic($line, $now));
        }
        $line = $timestamp.': 123';
        $this->assertSame(0, \pmssLiveStatsTraffic($line, $now, $now));
        $this->assertSame(123, \pmssLiveStatsTraffic($line, $now, $now - 1));
        $this->assertSame(123, \pmssLiveStatsTraffic($line, $now + 420));
        $this->assertSame(0, \pmssLiveStatsTraffic($line, $now + 421));
    }
}
