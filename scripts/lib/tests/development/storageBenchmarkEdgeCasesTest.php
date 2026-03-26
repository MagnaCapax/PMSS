<?php
namespace PMSS\Tests;

class StorageBenchmarkEdgeCasesTest extends TestCase
{
    private function logPath(): string
    {
        $dir = sys_get_temp_dir().'/pmss-bench-edge-'.bin2hex(random_bytes(3));
        @mkdir($dir, 0700, true);
        return $dir.'/benchmark-storage.jsonl';
    }

    private function writeLines(string $path, array $lines): void
    {
        foreach ($lines as $line) {
            if (is_array($line)) {
                file_put_contents($path, json_encode($line)."\n", FILE_APPEND);
            } else {
                file_put_contents($path, (string)$line."\n", FILE_APPEND);
            }
        }
    }

    private function runShowLast(string $log): string
    {
        return $this->pmssRunPhpScript(dirname(__DIR__, 3).'/util/storageBenchmark.php', ['--show-last', '--json', $log]);
    }

    public function testEmptyLogShowsNoRuns(): void
    {
        $log = $this->logPath();
        @touch($log);
        $out = $this->runShowLast($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testOnlyMalformedLinesDoesNotCrash(): void
    {
        $log = $this->logPath();
        $this->writeLines($log, ['{broken}', 'not json', '']);
        $out = $this->runShowLast($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testExtremelyLargeNumbersRender(): void
    {
        $log = $this->logPath();
        $rid = 'rid-'.bin2hex(random_bytes(2));
        $ts = date('c');
        $this->writeLines($log, [
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-large','params'=>['rw'=>'randread'],
             'metrics'=>['read_bw_MBps'=>1.0e9,'write_bw_MBps'=>0,'read_iops'=>1.0e7,'write_iops'=>0,'read_p95_ms'=>123456.78,'write_p95_ms'=>0]],
        ]);
        $out = $this->runShowLast($log);
        $this->assertStringContainsString('randread-large', $out);
    }

    public function testNegativeMetricsDoNotCrash(): void
    {
        $log = $this->logPath();
        $rid = 'neg-'.bin2hex(random_bytes(2)); $ts = date('c');
        $this->writeLines($log, [
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'],
             'metrics'=>['read_bw_MBps'=>-1,'write_bw_MBps'=>-2,'read_iops'=>-3,'write_iops'=>-4,'read_p95_ms'=>-5,'write_p95_ms'=>-6]],
        ]);
        $out = $this->runShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testVeryLongRunIdAndLabel(): void
    {
        $log = $this->logPath();
        $rid = str_repeat('R', 256); $ts=date('c'); $label=str_repeat('L', 128);
        $this->writeLines($log, [
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'label'=>$label,'test'=>'preflight-idle','ok'=>true],
        ]);
        $out = $this->runShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testPerDeviceOnlyStillPrintsSection(): void
    {
        $log = $this->logPath();
        $rid='dev-'.bin2hex(random_bytes(2)); $ts=date('c');
        $this->writeLines($log, [
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/sdb','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>123.4,'elapsed_s'=>1.5]],
        ]);
        $out = $this->runShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
        $this->assertStringContainsString('/dev/sdb', $out);
    }

    public function testEmbeddedNewlinesInJsonStringAreIgnoredSafely(): void
    {
        $log = $this->logPath();
        $rid='nl-'.bin2hex(random_bytes(2)); $ts=date('c');
        file_put_contents($log, '{"run_id":"'.$rid.'","run_ts":"'.$ts.'","test":"preflight-idle","ok":true'."\n", FILE_APPEND);
        file_put_contents($log, "}\n", FILE_APPEND);
        $this->writeLines($log, [['run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>1,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]]]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testBinaryInDeviceNameDoesNotCrash(): void
    {
        $log = $this->logPath(); $rid='bin-'.bin2hex(random_bytes(2)); $ts=date('c');
        $dev = "/dev/sd".chr(0)."x";
        $this->writeLines($log, [
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'device'=>$dev,'test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>10,'elapsed_s'=>1]],
        ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMissingMetricsKeysAreTolerated(): void
    {
        $log=$this->logPath(); $rid='miss-'.bin2hex(random_bytes(2)); $ts=date('c');
        $this->writeLines($log, [
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'], 'metrics'=>[]],
        ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testHugeLogSelectsLatestRun(): void
    {
        $log=$this->logPath();
        for ($i=0; $i<50; $i++) {
            $ts = gmdate('c', time()-100+$i);
            $rid = 'rid-'.$i;
            $this->writeLines($log, [
                ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
                ['timestamp'=>$ts,'run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'], 'metrics'=>['read_bw_MBps'=>$i+1,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]],
            ]);
        }
        $out=$this->runShowLast($log);
        $this->assertStringContainsString("\t50.00\t0.00\t1.0\t0.0\t1.00\t0.00", $out);
    }

    public function testRunIdCollisionAcrossRuns(): void
    {
        $log=$this->logPath(); $rid='same';
        $t1 = gmdate('c', time()-10); $t2 = gmdate('c', time());
        $this->writeLines($log, [
            ['timestamp'=>$t1,'run_id'=>$rid,'run_ts'=>$t1,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$t2,'run_id'=>$rid,'run_ts'=>$t2,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$t2,'run_id'=>$rid,'run_ts'=>$t2,'test'=>'randread-small','params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>2,'write_bw_MBps'=>0,'read_iops'=>2,'write_iops'=>0,'read_p95_ms'=>2,'write_p95_ms'=>0]],
        ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString("\t2.00\t0.00\t2.0\t0.0\t2.00\t0.00", $out);
    }

    public function testWhitespaceAroundJsonLinesIsIgnored(): void
    {
        $log=$this->logPath(); $rid='ws'; $ts=date('c');
        $this->writeLines($log, ["  ", ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], "  ",
            ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-large','params'=>['rw'=>'randread'], 'metrics'=>['read_bw_MBps'=>3,'write_bw_MBps'=>0,'read_iops'=>3,'write_iops'=>0,'read_p95_ms'=>3,'write_p95_ms'=>0]]]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('randread-large', $out);
    }

    public function testUnknownTestsAreListed(): void
    {
        $log=$this->logPath(); $rid='unk'; $ts=date('c');
        $this->writeLines($log, [ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'mystery-test','params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>1,'write_bw_MBps'=>1,'read_iops'=>1,'write_iops'=>1,'read_p95_ms'=>1,'write_p95_ms'=>1]] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('mystery-test', $out);
    }

    public function testUnicodeInDeviceName(): void
    {
        $log=$this->logPath(); $rid='uni'; $ts=date('c');
        $dev='/dev/disk-😀';
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'device'=>$dev,'test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>12.34,'elapsed_s'=>1.0]] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testEmptyParamsDoNotCrash(): void
    {
        $log=$this->logPath(); $rid='empty'; $ts=date('c');
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small'] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testMissingRunTsIgnoredForLatestSelection(): void
    {
        $log=$this->logPath();
        $this->writeLines($log,[ ['run_id'=>'a','run_ts'=>'2025-01-01T00:00:00Z','test'=>'preflight-idle','ok'=>true], ['run_id'=>'b','test'=>'preflight-idle','ok'=>true] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Run ID: a', $out);
    }

    public function testDeviceSectionShowsIopingAndFio(): void
    {
        $log=$this->logPath(); $rid='dev2'; $ts=date('c');
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/sdc','test'=>'device-ioping','metrics'=>['ioping_avg_ms'=>2.34]],
            ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/sdc','test'=>'dev-randread-4k','metrics'=>['read_bw_MBps'=>5.67,'read_iops'=>123.4,'read_p95_ms'=>1.23]] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('/dev/sdc', $out);
        $this->assertStringContainsString('dev-randread-4k', $out);
    }

    public function testRidiculouslyLongLabelDoesNotBreakHeader(): void
    {
        $log=$this->logPath(); $rid='longlab'; $ts=date('c');
        $label=str_repeat('long-', 200);
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'label'=>$label,'test'=>'preflight-idle','ok'=>true] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testNonNumericMetricsAreHandled(): void
    {
        $log=$this->logPath(); $rid='nonn'; $ts=date('c');
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>'x','write_bw_MBps'=>'y','read_iops'=>'z','write_iops'=>'w','read_p95_ms'=>'q','write_p95_ms'=>'r']] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testDuplicateDeviceEntriesAreAllShown(): void
    {
        $log=$this->logPath(); $rid='dup'; $ts=date('c');
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/sdd','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>100,'elapsed_s'=>2]],
            ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/sdd','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>200,'elapsed_s'=>1]] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('/dev/sdd', $out);
    }

    public function testLargeDeviceAndFileBackedCombined(): void
    {
        $log=$this->logPath(); $rid='combo'; $ts=date('c');
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true],
            ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/sde','test'=>'device-ioping','metrics'=>['ioping_avg_ms'=>1.11]],
            ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'seqread-large','params'=>['rw'=>'read'],'metrics'=>['read_bw_MBps'=>400,'write_bw_MBps'=>0,'read_iops'=>100,'write_iops'=>0,'read_p95_ms'=>5,'write_p95_ms'=>0]] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('seqread-large', $out);
        $this->assertStringContainsString('/dev/sde', $out);
    }

    public function testWeirdSymbolsInNames(): void
    {
        $log=$this->logPath(); $rid='sym'; $ts=date('c');
        $dev='/dev/disk-[A]{B}(C)';
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'device'=>$dev,'test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>1.23,'elapsed_s'=>0.5]] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMixedOrderEntriesDoNotBreakGrouping(): void
    {
        $log=$this->logPath(); $rid='mix'; $ts=date('c');
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'],'metrics'=>['read_bw_MBps'=>10,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]], ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('File-backed tests', $out);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testEqualTimestampsFavorFirstSeen(): void
    {
        $log=$this->logPath(); $ts='2025-01-05T00:00:00Z';
        $this->writeLines($log,[ ['run_id'=>'first','run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>'second','run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Run ID: first', $out);
    }

    public function testDeviceEntryWithoutMetricsLineDoesNotCrash(): void
    {
        $log=$this->logPath(); $rid='devno'; $ts=date('c');
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/sdf','test'=>'device-seqread-dd'] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testLabelWithAnsiSequencesDoesNotCrash(): void
    {
        $log=$this->logPath(); $rid='ansi'; $ts=date('c'); $label="\e[31mRED\e[0m";
        $this->writeLines($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'label'=>$label,'test'=>'preflight-idle','ok'=>true] ]);
        $out=$this->runShowLast($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }
}
