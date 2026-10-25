<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageBenchmark.php';

class StorageBenchmarkJobsCharacterizationTest extends TestCase
{
    /** Lock ordered job definitions, including the file-only write job. */
    public function testJobCatalogsKeepTheirLegacyOrderAndOptions(): void
    {
        $this->assertSame([
            ['name' => 'randmix-large-95r5w', 'rw' => 'randrw', 'rwmixread' => 95,
                'bssplit' => '4k/2:64k/3:128k/5:256k/10:512k/20:768k/25:1024k/35', 'iodepth' => 32, 'numjobs' => 4, 'direct' => 1],
            ['name' => 'randread-large', 'rw' => 'randread', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 4, 'direct' => 1],
            ['name' => 'randread-small', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64, 'numjobs' => 4, 'direct' => 1],
            ['name' => 'randwrite-small-short', 'rw' => 'randwrite', 'bs' => '4k', 'iodepth' => 32, 'numjobs' => 2, 'direct' => 1],
            ['name' => 'seqread-large', 'rw' => 'read', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 2, 'direct' => 1],
        ], \storageBenchmarkFileJobs());
        $this->assertSame([
            ['name' => 'dev-randread-4k', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64],
            ['name' => 'dev-randread-1M', 'rw' => 'randread', 'bs' => '1M', 'iodepth' => 32],
        ], \storageBenchmarkDeviceJobs());
    }

    /** Freeze complete JSONL bytes and terminal text for mixed success/failure runs. */
    public function testMountedDeviceJobsKeepOrderedPayloadsAndFailureOutput(): void
    {
        $base = \storageBenchmarkEntryBase('fixed-time', 'label', 'fixed-run');
        $device = ['device' => '/dev/example', 'rota' => null, 'size' => 4096, 'mode' => 'home-device', 'ok' => true];
        foreach ([
            ['ok' => true, 'result' => ['read_bw_MBps' => 1234.5, 'read_iops' => 67.8]],
            ['ok' => true, 'result' => ['read_bw_MBps' => 0.0, 'read_iops' => 0.0]],
            ['ok' => false],
            ['ok' => false, 'error' => 'fixture failure'],
            ['ok' => false, 'error' => ''],
        ] as $result) {
            $log = $this->pmssMakeTempDir('pmss-benchmark-jobs-').'/run.jsonl';
            $calls = [];
            $runner = static function ($path, $size, $runtime, $job) use (&$calls, $result): array {
                $calls[] = [$path, $size, $runtime, $job];
                return $result;
            };
            [, $output] = $this->pmssCaptureStdout(static function () use ($log, $base, $device, $runner): void {
                \storageBenchmarkHomeDeviceRunTests($log, $base, $device, 0, 19, true, $runner);
            });
            $expectedLog = $expectedOutput = '';
            foreach ([['home-device-randread-4k', 'randread', '4k', 64], ['home-device-seqread-1M', 'read', '1M', 32]] as $index => $job) {
                [$name, $rw, $bs, $depth] = $job;
                $options = ['name' => $name, 'rw' => $rw, 'bs' => $bs, 'iodepth' => $depth,
                    'numjobs' => 1, 'direct' => 1, 'readonly' => true];
                $this->assertSame(['/dev/example', 4096, 19, $options], $calls[$index]);
                $entry = $base + array_diff_key($device, ['ok' => true]) + ['test' => $name,
                    'params' => array_diff_key($options, ['name' => true]) + ['runtime' => 19], 'ok' => $result['ok']];
                if ($result['ok']) $entry['metrics'] = $result['result'];
                else $entry['error'] = $result['error'] ?? 'fio failed';
                $expectedLog .= json_encode($entry, JSON_UNESCAPED_SLASHES)."\n";
                $expectedOutput .= "/dev/example\t{$name}\tread_MB/s=".
                    ($result['ok'] ? number_format($result['result']['read_bw_MBps'], 2) : 'n/a').
                    "\tread_IOPS=".($result['ok'] ? number_format($result['result']['read_iops'], 1) : 'n/a')."\n";
            }
            $this->assertSame(2, count($calls));
            $this->assertSame($expectedLog, file_get_contents($log));
            $this->assertSame($expectedOutput, $output);
        }
    }

    /** A failed runner must stop immediately without logging a fabricated result. */
    public function testRunnerThrowablesRetainIdentityAndStopBeforeTheNextJob(): void
    {
        foreach ([new \RuntimeException('fixture'), new \Error('fixture')] as $failure) {
            $log = $this->pmssMakeTempDir('pmss-benchmark-throw-').'/run.jsonl';
            $calls = 0;
            $runner = static function () use (&$calls, $failure): array { ++$calls; throw $failure; };
            try {
                \storageBenchmarkHomeDeviceRunTests($log, [], ['device' => '/dev/example', 'size' => 4096], 0, 19, true, $runner);
                $this->assertTrue(false, 'runner throwable was swallowed');
            } catch (\Throwable $caught) {
                $this->assertSame($failure, $caught);
            }
            $this->assertSame(1, $calls);
            $this->assertFalse(file_exists($log));
        }
    }

    public function testLiveSummaryAndPeerWarningsKeepTheirExactText(): void
    {
        [, $output] = $this->pmssCaptureStdout(static function (): void {
            \storageBenchmarkPrintFileSummary('/fixture', '/log', 'label', [['job', 1.25, 2.5, 3.0, 4.0, 5.25, 6.5]]);
            \storageBenchmarkPrintPeerWarnings([
                '/dev/slow' => ['dd_mb' => 1.0, 'iop_ms' => 200.0, 'fio4k_mb' => 1.0],
                '/dev/normal' => ['dd_mb' => 100.0, 'iop_ms' => 10.0, 'fio4k_mb' => 100.0],
                '/dev/fast' => ['dd_mb' => 100.0, 'iop_ms' => 10.0, 'fio4k_mb' => 100.0],
            ]);
        });
        $this->assertSame("\n== Storage benchmark summary (label on /fixture) ==\n".
            "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95_ms\twrite_p95_ms\n".
            "job\t1.25\t2.50\t3.0\t4.0\t5.25\t6.50\n\nJSON log: /log\n".
            "WARN: /dev/slow seqread < 60% median\nWARN: /dev/slow ioping > 2x median\n".
            "WARN: /dev/slow 4k randread < 50% median\n", $output);
    }
}
