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
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testOnlyMalformedLinesDoesNotCrash(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $this->pmssAppendFixtureLines($log, ['{broken}', 'not json', '']);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testExtremelyLargeNumbersRender(): void
    {
        $rid = 'rid-'.bin2hex(random_bytes(2));
        $ts = date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-large', ['read_bw_MBps'=>1.0e9,'read_iops'=>1.0e7,'read_p95_ms'=>123456.78], ['timestamp'=>$ts])], ['timestamp' => $ts]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-large', $out);
    }

    public function testNegativeMetricsDoNotCrash(): void
    {
        $rid = 'neg-'.bin2hex(random_bytes(2)); $ts = date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-small', ['read_bw_MBps'=>-1,'write_bw_MBps'=>-2,'read_iops'=>-3,'write_iops'=>-4,'read_p95_ms'=>-5,'write_p95_ms'=>-6], ['timestamp'=>$ts])], ['timestamp' => $ts]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testVeryLongRunIdAndLabel(): void
    {
        $rid = str_repeat('R', 256); $ts=date('c'); $label=str_repeat('L', 128);
        $log = $this->writeEdgeRunLog($rid, $ts, [], ['timestamp'=>$ts,'label'=>$label]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testPerDeviceOnlyStillPrintsSection(): void
    {
        $rid='dev-'.bin2hex(random_bytes(2)); $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['timestamp'=>$ts,'device'=>'/dev/sdb','metrics'=>['seqread_MBps'=>123.4,'elapsed_s'=>1.5]])], ['timestamp' => $ts]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
        $this->assertStringContainsString('/dev/sdb', $out);
    }

    public function testEmbeddedNewlinesInJsonStringAreIgnoredSafely(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $rid='nl-'.bin2hex(random_bytes(2)); $ts=date('c');
        file_put_contents($log, '{"run_id":"'.$rid.'","run_ts":"'.$ts.'","test":"preflight-idle","ok":true'."\n", FILE_APPEND);
        file_put_contents($log, "}\n", FILE_APPEND);
        $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkFileEntry($rid, $ts)]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testBinaryInDeviceNameDoesNotCrash(): void
    {
        $rid='bin-'.bin2hex(random_bytes(2)); $ts=date('c');
        $dev = "/dev/sd".chr(0)."x";
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['timestamp'=>$ts,'device'=>$dev,'metrics'=>['seqread_MBps'=>10,'elapsed_s'=>1]])], ['timestamp' => $ts]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMissingMetricsKeysAreTolerated(): void
    {
        $rid='miss-'.bin2hex(random_bytes(2)); $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small', ['timestamp'=>$ts,'params'=>['rw'=>'randread'], 'metrics'=>[]])], ['timestamp' => $ts]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testHugeLogSelectsLatestRun(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        for ($i=0; $i<50; $i++) {
            $ts = gmdate('c', time()-100+$i);
            $rid = 'rid-'.$i;
            $this->pmssAppendFixtureLines($log, [
                $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp' => $ts]),
                $this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-small', ['read_bw_MBps'=>$i+1], ['timestamp'=>$ts]),
            ]);
        }
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString("\t50.00\t0.00\t1.0\t0.0\t1.00\t0.00", $out);
    }

    public function testRunIdCollisionAcrossRuns(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='same';
        $t1 = gmdate('c', time()-10); $t2 = gmdate('c', time());
        $this->pmssAppendFixtureLines($log, [
            $this->pmssStorageBenchmarkPreflightEntry($rid, $t1, ['timestamp'=>$t1]),
            $this->pmssStorageBenchmarkPreflightEntry($rid, $t2, ['timestamp'=>$t2]),
            $this->pmssStorageBenchmarkFileEntry($rid, $t2, 'randread-small', ['read_bw_MBps'=>2,'read_iops'=>2,'read_p95_ms'=>2], ['timestamp'=>$t2]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString("\t2.00\t0.00\t2.0\t0.0\t2.00\t0.00", $out);
    }

    public function testWhitespaceAroundJsonLinesIsIgnored(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='ws'; $ts=date('c');
        $this->pmssAppendFixtureLines($log, ["  ", $this->pmssStorageBenchmarkPreflightEntry($rid, $ts), "  ",
            $this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-large', ['read_bw_MBps'=>3,'read_iops'=>3,'read_p95_ms'=>3])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-large', $out);
    }

    public function testUnknownTestsAreListed(): void
    {
        $rid='unk'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkFileEntry($rid, $ts, 'mystery-test', ['write_bw_MBps'=>1,'write_iops'=>1,'write_p95_ms'=>1])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('mystery-test', $out);
    }

    public function testUnicodeInDeviceName(): void
    {
        $rid='uni'; $ts=date('c');
        $dev='/dev/disk-😀';
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>$dev,'metrics'=>['seqread_MBps'=>12.34,'elapsed_s'=>1.0]])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testEmptyParamsDoNotCrash(): void
    {
        $rid='empty'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small')]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testMissingRunTsIgnoredForLatestSelection(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry('a', '2025-01-01T00:00:00Z'), ['run_id'=>'b','test'=>'preflight-idle','ok'=>true] ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Run ID: a', $out);
    }

    public function testDeviceSectionShowsIopingAndFio(): void
    {
        $rid='dev2'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-ioping', ['device'=>'/dev/sdc','metrics'=>['ioping_avg_ms'=>2.34]]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'dev-randread-4k', ['device'=>'/dev/sdc','metrics'=>['read_bw_MBps'=>5.67,'read_iops'=>123.4,'read_p95_ms'=>1.23]]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('/dev/sdc', $out);
        $this->assertStringContainsString('dev-randread-4k', $out);
    }

    public function testRidiculouslyLongLabelDoesNotBreakHeader(): void
    {
        $rid='longlab'; $ts=date('c');
        $label=str_repeat('long-', 200);
        $log = $this->writeEdgeRunLog($rid, $ts, [], ['label'=>$label]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testNonNumericMetricsAreHandled(): void
    {
        $rid='nonn'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-small', ['read_bw_MBps'=>'x','write_bw_MBps'=>'y','read_iops'=>'z','write_iops'=>'w','read_p95_ms'=>'q','write_p95_ms'=>'r'])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testStructuredMetricFieldsRenderAsZero(): void
    {
        $rid='structmetric'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-small', ['read_bw_MBps'=>['bad'=>1],'write_bw_MBps'=>['bad'=>2],'read_iops'=>['bad'=>3],'write_iops'=>['bad'=>4],'read_p95_ms'=>['bad'=>5],'write_p95_ms'=>['bad'=>6]])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString("randread-small\t0.00\t0.00\t0.0\t0.0\t0.00\t0.00", $out);
    }

    public function testStructuredDeviceNameIsSkippedSafely(): void
    {
        $rid='structdev'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>['bad'=>'/dev/sdz'],'metrics'=>['seqread_MBps'=>1,'elapsed_s'=>1]])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
        $this->assertStringNotContainsString('/dev/sdz', $out);
    }

    public function testDuplicateDeviceEntriesAreAllShown(): void
    {
        $rid='dup'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>'/dev/sdd','metrics'=>['seqread_MBps'=>100,'elapsed_s'=>2]]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>'/dev/sdd','metrics'=>['seqread_MBps'=>200,'elapsed_s'=>1]]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('/dev/sdd', $out);
    }

    public function testLargeDeviceAndFileBackedCombined(): void
    {
        $rid='combo'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-ioping', ['device'=>'/dev/sde','metrics'=>['ioping_avg_ms'=>1.11]]),
            $this->pmssStorageBenchmarkFileEntry($rid, $ts, 'seqread-large', ['read_bw_MBps'=>400,'read_iops'=>100,'read_p95_ms'=>5], ['params'=>['rw'=>'read']]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('seqread-large', $out);
        $this->assertStringContainsString('/dev/sde', $out);
    }

    public function testWeirdSymbolsInNames(): void
    {
        $rid='sym'; $ts=date('c');
        $dev='/dev/disk-[A]{B}(C)';
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>$dev,'metrics'=>['seqread_MBps'=>1.23,'elapsed_s'=>0.5]])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMixedOrderEntriesDoNotBreakGrouping(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='mix'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkFileEntry($rid, $ts, 'randread-small', ['read_bw_MBps'=>10]), $this->pmssStorageBenchmarkPreflightEntry($rid, $ts) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('File-backed tests', $out);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testEqualTimestampsFavorFirstSeen(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $ts='2025-01-05T00:00:00Z';
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry('first', $ts), $this->pmssStorageBenchmarkPreflightEntry('second', $ts) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Run ID: first', $out);
    }

    public function testDeviceEntryWithoutMetricsLineDoesNotCrash(): void
    {
        $rid='devno'; $ts=date('c');
        $log = $this->writeEdgeRunLog($rid, $ts, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>'/dev/sdf'])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testLabelWithAnsiSequencesDoesNotCrash(): void
    {
        $rid='ansi'; $ts=date('c'); $label="\e[31mRED\e[0m";
        $log = $this->writeEdgeRunLog($rid, $ts, [], ['label'=>$label]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }
}
