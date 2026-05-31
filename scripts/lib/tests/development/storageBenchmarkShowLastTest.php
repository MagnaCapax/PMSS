<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkShowLastTest extends TestCase
{
    public function testShowLastParsesAndPrintsSummary(): void
    {
        $runId  = '20250101010101-aaa';
        $runTs  = '2025-01-01T01:01:01Z';
        $older  = '2024-12-31T23:59:59Z';
        $log = $this->pmssWriteStorageBenchmarkLog([
            // Older run we should ignore
            $this->pmssStorageBenchmarkPreflightEntry('old', $older, ['timestamp'=>$older]),
            // Current run
            $this->pmssStorageBenchmarkPreflightEntry($runId, $runTs, ['timestamp'=>$runTs,'ioping_avg_ms'=>1.23,'iostat_util_pct'=>3.0]),
            $this->pmssStorageBenchmarkFileEntry($runId, $runTs, 'randread-small', ['read_bw_MBps'=>123.45,'read_iops'=>999.9,'read_p95_ms'=>2.34], ['timestamp'=>$runTs]),
            // Device sample
            $this->pmssStorageBenchmarkEntry($runId, $runTs, 'device-seqread-dd', ['timestamp'=>$runTs,'device'=>'/dev/sda','metrics'=>['seqread_MBps'=>250.5,'elapsed_s'=>4.0]]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertTrue(is_string($out) && $out !== '', 'no output from storageBenchmark --show-last');
        $this->assertStringContainsAllStrings(['== Storage benchmark (last run) ==', 'File-backed tests', 'randread-small', 'Per-device tests', '/dev/sda'], $out);
    }

    public function testShowLastAcceptsEqualsFormForJsonPath(): void
    {
        $runId  = '20250202020202-ccc';
        $runTs  = '2025-02-02T02:02:02Z';
        $log = $this->pmssWriteStorageBenchmarkRunLog($runId, $runTs, [
            $this->pmssStorageBenchmarkFileEntry($runId, $runTs, 'randread-small', ['read_bw_MBps'=>10,'read_iops'=>10], ['timestamp'=>$runTs]),
        ], ['timestamp'=>$runTs,'ioping_avg_ms'=>1.0,'iostat_util_pct'=>1.0]);
        $out = $this->pmssRunRepoPhpScript('scripts/util/storageBenchmark.php', ['--show-last', '--json='.$log]);
        $this->assertStringContainsAllStrings(['== Storage benchmark (last run) ==', 'randread-small'], (string) $out);
    }

    public function testShowLastIgnoresExtraIntegerOptionsWhileUsingSharedValueDispatch(): void
    {
        $runId  = '20250202020203-int';
        $runTs  = '2025-02-02T02:02:03Z';
        $log = $this->pmssWriteStorageBenchmarkRunLog($runId, $runTs, [
            $this->pmssStorageBenchmarkFileEntry($runId, $runTs, 'randread-small', ['read_bw_MBps'=>10,'read_iops'=>10], ['timestamp'=>$runTs]),
        ], ['timestamp'=>$runTs,'ioping_avg_ms'=>1.0,'iostat_util_pct'=>1.0]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log, ['--runtime=15', '--device-runtime', '45', '--idle-util=70']);
        $this->assertStringContainsAllStrings(['== Storage benchmark (last run) ==', 'randread-small'], (string) $out);
    }

    public function testShowLastIgnoresMalformedBenchmarkRuntimeOptions(): void
    {
        $runId = '20250202020204-readonly';
        $runTs = '2025-02-02T02:02:04Z';
        $log = $this->pmssWriteStorageBenchmarkRunLog($runId, $runTs, [], ['timestamp'=>$runTs]);

        $out = $this->pmssRunStorageBenchmarkShowLast($log, ['--runtime=bad']);
        $this->assertStringContainsString('Run ID: '.$runId, (string) $out);
    }

    public function testShowLastTreatsShortHelpTokenAsJsonPathValue(): void
    {
        $out = $this->pmssRunRepoPhpScript('scripts/util/storageBenchmark.php', ['--show-last', '--json', '-h']);

        $this->assertStringContainsString('No log at -h', (string) $out);
        $this->assertStringNotContainsString('Storage benchmark (non-destructive)', (string) $out, 'Expected --json to consume -h as its value instead of triggering help');
    }

    public function testShowLastHandlesMalformedLines(): void
    {
        $runId  = '20250102020202-bbb';
        $runTs  = '2025-01-02T02:02:02Z';
        $log = $this->pmssWriteStorageBenchmarkLog([
            '{this is not json}', // junk
            $this->pmssStorageBenchmarkPreflightEntry($runId, $runTs, ['timestamp'=>$runTs]),
            'BROKEN LINE',
            $this->pmssStorageBenchmarkFileEntry($runId, $runTs, 'randread-small', [], ['timestamp'=>$runTs]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsAllStrings(['Storage benchmark (last run)', 'randread-small'], $out);
    }

    public function testShowLastSelectsLatestRunByTimestamp(): void
    {
        $older = '2025-01-03T00:00:00Z';
        $newer = '2025-01-03T01:00:00Z';
        $log = $this->pmssWriteStorageBenchmarkLog([
            $this->pmssStorageBenchmarkPreflightEntry('old', $older, ['timestamp'=>$older]),
            $this->pmssStorageBenchmarkFileEntry('old', $older, 'randread-small', [], ['timestamp'=>$older]),
            $this->pmssStorageBenchmarkPreflightEntry('new', $newer, ['timestamp'=>$newer]),
            $this->pmssStorageBenchmarkFileEntry('new', $newer, 'randread-small', ['read_bw_MBps'=>2,'read_iops'=>2,'read_p95_ms'=>2], ['timestamp'=>$newer]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        // Only the newer result value should appear
        $this->assertMatches('/\t2[.,]00\t0[.,]00\t2[.,]0\t0[.,]0\t2[.,]00\t0[.,]00/', $out);
    }

    public function testShowLastMatchesMixedRunSnapshot(): void
    {
        $runId = '2025-01-05T05:05:05Z-snap';
        $runTs = '2025-01-05T05:05:05Z';
        $log = $this->pmssWriteStorageBenchmarkRunLog($runId, $runTs, [
            $this->pmssStorageBenchmarkFileEntry($runId, $runTs, 'randread-small', ['read_bw_MBps' => 123.45, 'read_iops' => 456.7, 'read_p95_ms' => 3.21], ['timestamp' => $runTs, 'label' => 'array-a']),
            $this->pmssStorageBenchmarkEntry($runId, $runTs, 'device-seqread-dd', ['timestamp' => $runTs, 'label' => 'array-a', 'device' => '/dev/sda', 'metrics' => ['seqread_MBps' => 250.5, 'elapsed_s' => 4.0]]),
            $this->pmssStorageBenchmarkEntry($runId, $runTs, 'device-ioping', ['timestamp' => $runTs, 'label' => 'array-a', 'device' => '/dev/sda', 'metrics' => ['ioping_avg_ms' => 1.23]]),
            $this->pmssStorageBenchmarkEntry($runId, $runTs, 'dev-randread-4k', ['timestamp' => $runTs, 'label' => 'array-a', 'device' => '/dev/sda', 'metrics' => ['read_bw_MBps' => 12.34, 'read_iops' => 567.8, 'read_p95_ms' => 0.91]]),
            $this->pmssStorageBenchmarkEntry($runId, $runTs, 'dev-randread-1M', ['timestamp' => $runTs, 'label' => 'array-a', 'device' => '/dev/sda', 'metrics' => ['read_bw_MBps' => 345.67, 'read_iops' => 89.1, 'read_p95_ms' => 4.56]]),
        ], ['timestamp' => $runTs, 'label' => 'array-a', 'ioping_avg_ms' => 1.5, 'iostat_util_pct' => 2]);

        $expected = "\n== Storage benchmark (last run) ==\nRun ID: {$runId}  Time: {$runTs}  Label: array-a\n\n";
        $expected .= "Preflight: ioping=1.5 ms util=2%\n\n";
        $expected .= "File-backed tests\n";
        $expected .= "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95\twrite_p95\n";
        $expected .= sprintf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n", 'randread-small', 123.45, 0.0, 456.7, 0.0, 3.21, 0.0);
        $expected .= "\nPer-device tests\n/dev/sda\n";
        $expected .= sprintf("  %-18s seq_MB/s=%.2f t=%.2fs\n", 'device-seqread-dd', 250.5, 4.0);
        $expected .= sprintf("  %-18s avg_ms=%.2f\n", 'device-ioping', 1.23);
        $expected .= sprintf("  %-18s read_MB/s=%.2f IOPS=%.1f p95=%.2fms\n", 'dev-randread-4k', 12.34, 567.8, 0.91);
        $expected .= sprintf("  %-18s read_MB/s=%.2f IOPS=%.1f p95=%.2fms\n", 'dev-randread-1M', 345.67, 89.1, 4.56);

        $this->assertSame($expected, $this->pmssRunRepoPhpScript('scripts/util/storageBenchmark.php', ['--show-last', '--json', $log]));
    }

    public function testShowLastLibraryRendererMatchesCliWrapper(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/storageBenchmark.php');
        $runId = '2025-01-06T06:06:06Z-lib';
        $runTs = '2025-01-06T06:06:06Z';
        $log = $this->pmssWriteStorageBenchmarkRunLog($runId, $runTs, [
            $this->pmssStorageBenchmarkFileEntry($runId, $runTs, 'seqread-large', ['read_bw_MBps' => 42.5, 'read_iops' => 7.0, 'read_p95_ms' => 1.5], ['timestamp' => $runTs]),
        ], ['timestamp' => $runTs, 'ioping_avg_ms' => 2.5]);

        [$rc, $libraryOut] = $this->pmssCaptureStdout(static function () use ($log): int {
            return \storageBenchmarkShowLast($log);
        });

        $this->assertSame(0, $rc);
        $this->assertSame($this->pmssRunRepoPhpScript('scripts/util/storageBenchmark.php', ['--show-last', '--json', $log]), $libraryOut);
    }

    public function testShowLastPrintsPreflightOnlyIfNoTests(): void
    {
        $runTs  = '2025-01-04T03:03:03Z';
        $log = $this->pmssWriteStorageBenchmarkRunLog('only', $runTs, [], ['timestamp'=>$runTs,'ioping_avg_ms'=>12.3,'iostat_util_pct'=>40]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Preflight: ioping=12.3 ms', $out);
    }
}
