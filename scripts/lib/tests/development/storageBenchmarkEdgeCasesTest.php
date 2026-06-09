<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkEdgeCasesTest extends TestCase
{
    private function writeEdgeRunLog(string $runId, string $runTs, array $entries = [], array $preflightExtra = []): string
    {
        return $this->pmssWriteStorageBenchmarkRunLog($runId, $runTs, $entries, $preflightExtra, 'pmss-bench-edge-');
    }

    public function testEmptyLogShowsNoRuns(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        @touch($log);
        $this->assertStringContainsString('No runs found.', $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testOnlyMalformedLinesDoesNotCrash(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $this->pmssAppendFixtureLines($log, ['{broken}', 'not json', '']);
        $this->assertStringContainsString('No runs found.', $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testRepresentativeMalformedAndExtremeEntriesRenderSafely(): void
    {
        foreach ($this->representativeEdgeCases() as $label => $case) {
            $log = $this->writeEdgeRunLog($case['run_id'], $case['run_ts'], $case['entries'], $case['preflight'] ?? ['timestamp' => $case['run_ts']]);
            $out = $this->pmssRunStorageBenchmarkShowLast($log);

            foreach ($case['contains'] as $needle) {
                $this->assertStringContainsString($needle, $out, $label);
            }
            foreach ($case['omits'] ?? [] as $needle) {
                $this->assertStringNotContainsString($needle, $out, $label);
            }
        }
    }

    public function testEmbeddedNewlinesInJsonStringAreIgnoredSafely(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $rid = 'nl-'.bin2hex(random_bytes(2));
        $ts = date('c');
        file_put_contents($log, '{"run_id":"'.$rid.'","run_ts":"'.$ts.'","test":"preflight-idle","ok":true'."\n", FILE_APPEND);
        file_put_contents($log, "}\n", FILE_APPEND);
        $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkFileEntry($rid, $ts)]);
        $this->assertStringContainsString('randread-small', $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testHugeLogSelectsLatestRun(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        for ($i = 0; $i < 50; $i++) {
            $ts = gmdate('c', time() - 100 + $i);
            $rid = 'rid-'.$i;
            $this->pmssAppendFixtureLines($log, [
                $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp' => $ts]),
                $this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-small', ['read_bw_MBps' => $i + 1], ['timestamp' => $ts]),
            ]);
        }
        $this->assertStringContainsString("\t50.00\t0.00\t1.0\t0.0\t1.00\t0.00", $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testRunIdCollisionAcrossRuns(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $t1 = gmdate('c', time() - 10);
        $t2 = gmdate('c', time());
        $this->pmssAppendFixtureLines($log, [
            $this->pmssStorageBenchmarkPreflightEntry('same', $t1, ['timestamp' => $t1]),
            $this->pmssStorageBenchmarkPreflightEntry('same', $t2, ['timestamp' => $t2]),
            $this->pmssStorageBenchmarkFileEntry('same', $t2, 'randread-small', ['read_bw_MBps' => 2, 'read_iops' => 2, 'read_p95_ms' => 2], ['timestamp' => $t2]),
        ]);
        $this->assertStringContainsString("\t2.00\t0.00\t2.0\t0.0\t2.00\t0.00", $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testWhitespaceAroundJsonLinesIsIgnored(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $rid = 'ws';
        $ts = date('c');
        $this->pmssAppendFixtureLines($log, ['  ', $this->pmssStorageBenchmarkPreflightEntry($rid, $ts), '  ', $this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-large', ['read_bw_MBps' => 3, 'read_iops' => 3, 'read_p95_ms' => 3])]);
        $this->assertStringContainsString('randread-large', $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testMissingRunTsIgnoredForLatestSelection(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkPreflightEntry('a', '2025-01-01T00:00:00Z'), ['run_id' => 'b', 'test' => 'preflight-idle', 'ok' => true]]);
        $this->assertStringContainsString('Run ID: a', $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testMixedOrderEntriesDoNotBreakGrouping(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $rid = 'mix';
        $ts = date('c');
        $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-small', ['read_bw_MBps' => 10]), $this->pmssStorageBenchmarkPreflightEntry($rid, $ts)]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('File-backed tests', $out);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testEqualTimestampsFavorFirstSeen(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $ts = '2025-01-05T00:00:00Z';
        $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkPreflightEntry('first', $ts), $this->pmssStorageBenchmarkPreflightEntry('second', $ts)]);
        $this->assertStringContainsString('Run ID: first', $this->pmssRunStorageBenchmarkShowLast($log));
    }

    public function testLabelWithAnsiSequencesDoesNotCrash(): void
    {
        $ts = date('c');
        $log = $this->writeEdgeRunLog('ansi', $ts, [], ['label' => chr(27).'[31mRED'.chr(27).'[0m', 'timestamp' => $ts]);
        $this->assertStringContainsString('Storage benchmark (last run)', $this->pmssRunStorageBenchmarkShowLast($log));
    }

    /** @return array<string, array<string, mixed>> */
    private function representativeEdgeCases(): array
    {
        $ts = date('c');

        return [
            'large_numbers' => ['run_id' => 'large', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkFileEntry('large', $ts, 'randread-large', ['read_bw_MBps' => 1.0e9, 'read_iops' => 1.0e7, 'read_p95_ms' => 123456.78], ['timestamp' => $ts])], 'contains' => ['randread-large']],
            'negative_metrics' => ['run_id' => 'negative', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkFileEntry('negative', $ts, 'randread-small', ['read_bw_MBps' => -1, 'write_bw_MBps' => -2, 'read_iops' => -3, 'write_iops' => -4, 'read_p95_ms' => -5, 'write_p95_ms' => -6], ['timestamp' => $ts])], 'contains' => ['randread-small']],
            'long_identity' => ['run_id' => str_repeat('R', 256), 'run_ts' => $ts, 'preflight' => ['label' => str_repeat('L', 128)], 'entries' => [], 'contains' => ['Storage benchmark (last run)']],
            'device_only' => ['run_id' => 'device-only', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('device-only', $ts, 'device-seqread-dd', ['timestamp' => $ts, 'device' => '/dev/sdb', 'metrics' => ['seqread_MBps' => 123.4, 'elapsed_s' => 1.5]])], 'contains' => ['Per-device tests', '/dev/sdb']],
            'binary_device' => ['run_id' => 'binary-device', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('binary-device', $ts, 'device-seqread-dd', ['timestamp' => $ts, 'device' => "/dev/sd".chr(0).'x', 'metrics' => ['seqread_MBps' => 10, 'elapsed_s' => 1]])], 'contains' => ['Per-device tests']],
            'missing_metrics' => ['run_id' => 'missing-metrics', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('missing-metrics', $ts, 'randread-small', ['timestamp' => $ts, 'params' => ['rw' => 'randread'], 'metrics' => []])], 'contains' => ['randread-small']],
            'unknown_test' => ['run_id' => 'unknown-test', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkFileEntry('unknown-test', $ts, 'mystery-test', ['write_bw_MBps' => 1, 'write_iops' => 1, 'write_p95_ms' => 1])], 'contains' => ['mystery-test']],
            'unicode_device' => ['run_id' => 'unicode-device', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('unicode-device', $ts, 'device-seqread-dd', ['device' => '/dev/disk-😀', 'metrics' => ['seqread_MBps' => 12.34, 'elapsed_s' => 1.0]])], 'contains' => ['Per-device tests']],
            'empty_params' => ['run_id' => 'empty-params', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('empty-params', $ts, 'randread-small')], 'contains' => ['Storage benchmark (last run)']],
            'device_ioping_fio' => ['run_id' => 'device-ioping-fio', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('device-ioping-fio', $ts, 'device-ioping', ['device' => '/dev/sdc', 'metrics' => ['ioping_avg_ms' => 2.34]]), $this->pmssStorageBenchmarkEntry('device-ioping-fio', $ts, 'dev-randread-4k', ['device' => '/dev/sdc', 'metrics' => ['read_bw_MBps' => 5.67, 'read_iops' => 123.4, 'read_p95_ms' => 1.23]])], 'contains' => ['/dev/sdc', 'dev-randread-4k']],
            'long_label' => ['run_id' => 'long-label', 'run_ts' => $ts, 'preflight' => ['label' => str_repeat('long-', 200)], 'entries' => [], 'contains' => ['Storage benchmark (last run)']],
            'non_numeric_metrics' => ['run_id' => 'non-numeric', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkFileEntry('non-numeric', $ts, 'randread-small', ['read_bw_MBps' => 'x', 'write_bw_MBps' => 'y', 'read_iops' => 'z', 'write_iops' => 'w', 'read_p95_ms' => 'q', 'write_p95_ms' => 'r'])], 'contains' => ['randread-small']],
            'structured_metrics' => ['run_id' => 'structured-metrics', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkFileEntry('structured-metrics', $ts, 'randread-small', ['read_bw_MBps' => ['bad' => 1], 'write_bw_MBps' => ['bad' => 2], 'read_iops' => ['bad' => 3], 'write_iops' => ['bad' => 4], 'read_p95_ms' => ['bad' => 5], 'write_p95_ms' => ['bad' => 6]])], 'contains' => ["randread-small\t0.00\t0.00\t0.0\t0.0\t0.00\t0.00"]],
            'structured_device' => ['run_id' => 'structured-device', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('structured-device', $ts, 'device-seqread-dd', ['device' => ['bad' => '/dev/sdz'], 'metrics' => ['seqread_MBps' => 1, 'elapsed_s' => 1]])], 'contains' => ['Per-device tests'], 'omits' => ['/dev/sdz']],
            'duplicate_device' => ['run_id' => 'duplicate-device', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('duplicate-device', $ts, 'device-seqread-dd', ['device' => '/dev/sdd', 'metrics' => ['seqread_MBps' => 100, 'elapsed_s' => 2]]), $this->pmssStorageBenchmarkEntry('duplicate-device', $ts, 'device-seqread-dd', ['device' => '/dev/sdd', 'metrics' => ['seqread_MBps' => 200, 'elapsed_s' => 1]])], 'contains' => ['/dev/sdd']],
            'combined_device_and_file' => ['run_id' => 'combined', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('combined', $ts, 'device-ioping', ['device' => '/dev/sde', 'metrics' => ['ioping_avg_ms' => 1.11]]), $this->pmssStorageBenchmarkFileEntry('combined', $ts, 'seqread-large', ['read_bw_MBps' => 400, 'read_iops' => 100, 'read_p95_ms' => 5], ['params' => ['rw' => 'read']])], 'contains' => ['seqread-large', '/dev/sde']],
            'symbol_device' => ['run_id' => 'symbol-device', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('symbol-device', $ts, 'device-seqread-dd', ['device' => '/dev/disk-[A]{B}(C)', 'metrics' => ['seqread_MBps' => 1.23, 'elapsed_s' => 0.5]])], 'contains' => ['Per-device tests']],
            'device_without_metrics' => ['run_id' => 'device-no-metrics', 'run_ts' => $ts, 'entries' => [$this->pmssStorageBenchmarkEntry('device-no-metrics', $ts, 'device-seqread-dd', ['device' => '/dev/sdf'])], 'contains' => ['Per-device tests']],
        ];
    }
}
