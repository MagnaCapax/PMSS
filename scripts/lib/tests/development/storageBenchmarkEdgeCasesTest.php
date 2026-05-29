<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkEdgeCasesTest extends TestCase
{
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
        $log = $this->pmssWriteStorageBenchmarkLog([
            $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp' => $ts]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-large', ['timestamp'=>$ts,'params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>1.0e9,'write_bw_MBps'=>0,'read_iops'=>1.0e7,'write_iops'=>0,'read_p95_ms'=>123456.78,'write_p95_ms'=>0]]),
        ], 'pmss-bench-edge-');
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-large', $out);
    }

    public function testNegativeMetricsDoNotCrash(): void
    {
        $rid = 'neg-'.bin2hex(random_bytes(2)); $ts = date('c');
        $log = $this->pmssWriteStorageBenchmarkLog([
            $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp' => $ts]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small', ['timestamp'=>$ts,'params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>-1,'write_bw_MBps'=>-2,'read_iops'=>-3,'write_iops'=>-4,'read_p95_ms'=>-5,'write_p95_ms'=>-6]]),
        ], 'pmss-bench-edge-');
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testVeryLongRunIdAndLabel(): void
    {
        $rid = str_repeat('R', 256); $ts=date('c'); $label=str_repeat('L', 128);
        $log = $this->pmssWriteStorageBenchmarkLog([
            $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp'=>$ts,'label'=>$label]),
        ], 'pmss-bench-edge-');
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testPerDeviceOnlyStillPrintsSection(): void
    {
        $rid='dev-'.bin2hex(random_bytes(2)); $ts=date('c');
        $log = $this->pmssWriteStorageBenchmarkLog([
            $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp' => $ts]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['timestamp'=>$ts,'device'=>'/dev/sdb','metrics'=>['seqread_MBps'=>123.4,'elapsed_s'=>1.5]]),
        ], 'pmss-bench-edge-');
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
        $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small', ['params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>1,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testBinaryInDeviceNameDoesNotCrash(): void
    {
        $rid='bin-'.bin2hex(random_bytes(2)); $ts=date('c');
        $dev = "/dev/sd".chr(0)."x";
        $log = $this->pmssWriteStorageBenchmarkLog([
            $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp' => $ts]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['timestamp'=>$ts,'device'=>$dev,'metrics'=>['seqread_MBps'=>10,'elapsed_s'=>1]]),
        ], 'pmss-bench-edge-');
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMissingMetricsKeysAreTolerated(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='miss-'.bin2hex(random_bytes(2)); $ts=date('c');
        $this->pmssAppendFixtureLines($log, [
            $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['timestamp' => $ts]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small', ['timestamp'=>$ts,'params'=>['rw'=>'randread'], 'metrics'=>[]]),
        ]);
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
                $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small', ['timestamp'=>$ts,'params'=>['rw'=>'randread'], 'metrics'=>['read_bw_MBps'=>$i+1,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]]),
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
            $this->pmssStorageBenchmarkEntry($rid, $t2, 'randread-small', ['timestamp'=>$t2,'params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>2,'write_bw_MBps'=>0,'read_iops'=>2,'write_iops'=>0,'read_p95_ms'=>2,'write_p95_ms'=>0]]),
        ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString("\t2.00\t0.00\t2.0\t0.0\t2.00\t0.00", $out);
    }

    public function testWhitespaceAroundJsonLinesIsIgnored(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='ws'; $ts=date('c');
        $this->pmssAppendFixtureLines($log, ["  ", $this->pmssStorageBenchmarkPreflightEntry($rid, $ts), "  ",
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-large', ['params'=>['rw'=>'randread'], 'metrics'=>['read_bw_MBps'=>3,'write_bw_MBps'=>0,'read_iops'=>3,'write_iops'=>0,'read_p95_ms'=>3,'write_p95_ms'=>0]])]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-large', $out);
    }

    public function testUnknownTestsAreListed(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='unk'; $ts=date('c');
        $this->pmssAppendFixtureLines($log, [ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'mystery-test', ['params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>1,'write_bw_MBps'=>1,'read_iops'=>1,'write_iops'=>1,'read_p95_ms'=>1,'write_p95_ms'=>1]]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('mystery-test', $out);
    }

    public function testUnicodeInDeviceName(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='uni'; $ts=date('c');
        $dev='/dev/disk-😀';
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts), $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>$dev,'metrics'=>['seqread_MBps'=>12.34,'elapsed_s'=>1.0]]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testEmptyParamsDoNotCrash(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='empty'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts), $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small') ]);
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
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='dev2'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-ioping', ['device'=>'/dev/sdc','metrics'=>['ioping_avg_ms'=>2.34]]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'dev-randread-4k', ['device'=>'/dev/sdc','metrics'=>['read_bw_MBps'=>5.67,'read_iops'=>123.4,'read_p95_ms'=>1.23]]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('/dev/sdc', $out);
        $this->assertStringContainsString('dev-randread-4k', $out);
    }

    public function testRidiculouslyLongLabelDoesNotBreakHeader(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='longlab'; $ts=date('c');
        $label=str_repeat('long-', 200);
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['label'=>$label]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testNonNumericMetricsAreHandled(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='nonn'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small', ['params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>'x','write_bw_MBps'=>'y','read_iops'=>'z','write_iops'=>'w','read_p95_ms'=>'q','write_p95_ms'=>'r']]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testDuplicateDeviceEntriesAreAllShown(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='dup'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>'/dev/sdd','metrics'=>['seqread_MBps'=>100,'elapsed_s'=>2]]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>'/dev/sdd','metrics'=>['seqread_MBps'=>200,'elapsed_s'=>1]]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('/dev/sdd', $out);
    }

    public function testLargeDeviceAndFileBackedCombined(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='combo'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-ioping', ['device'=>'/dev/sde','metrics'=>['ioping_avg_ms'=>1.11]]),
            $this->pmssStorageBenchmarkEntry($rid, $ts, 'seqread-large', ['params'=>['rw'=>'read'],'metrics'=>['read_bw_MBps'=>400,'write_bw_MBps'=>0,'read_iops'=>100,'write_iops'=>0,'read_p95_ms'=>5,'write_p95_ms'=>0]]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('seqread-large', $out);
        $this->assertStringContainsString('/dev/sde', $out);
    }

    public function testWeirdSymbolsInNames(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='sym'; $ts=date('c');
        $dev='/dev/disk-[A]{B}(C)';
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts), $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>$dev,'metrics'=>['seqread_MBps'=>1.23,'elapsed_s'=>0.5]]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMixedOrderEntriesDoNotBreakGrouping(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='mix'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkEntry($rid, $ts, 'randread-small', ['params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>10,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]]), $this->pmssStorageBenchmarkPreflightEntry($rid, $ts) ]);
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
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='devno'; $ts=date('c');
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts), $this->pmssStorageBenchmarkEntry($rid, $ts, 'device-seqread-dd', ['device'=>'/dev/sdf']) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testLabelWithAnsiSequencesDoesNotCrash(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-edge-', 'benchmark-storage.jsonl'); $rid='ansi'; $ts=date('c'); $label="\e[31mRED\e[0m";
        $this->pmssAppendFixtureLines($log,[ $this->pmssStorageBenchmarkPreflightEntry($rid, $ts, ['label'=>$label]) ]);
        $out = $this->pmssRunStorageBenchmarkShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }
}
