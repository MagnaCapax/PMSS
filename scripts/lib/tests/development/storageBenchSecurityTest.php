<?php
namespace PMSS\Tests;

class StorageBenchSecurityTest extends TestCase
{
    private function mklog(): string
    {
        return $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
    }

    private function runShow(string $path): string
    {
        if ($this->isSandbox()) {
            throw new SkipTest('skip in sandbox');
        }
        return $this->pmssRunPhpScript(dirname(__DIR__, 3).'/util/storageBenchmark.php', ['--show-last', '--json', $path]);
    }

    private function write(string $path, array $entries): void
    {
        $this->pmssAppendFixtureLines($path, $entries);
    }

    public function testPathTraversalInJsonPathIsRead(): void
    {
        $log = $this->mklog(); @touch($log);
        $up = dirname($log).'/../'.basename(dirname($log)).'/'.basename($log);
        $out = $this->runShow($up);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testSymlinkJsonLogIsHandled(): void
    {
        $log = $this->mklog(); $dir = dirname($log); @mkdir($dir, 0700, true);
        $real = $dir.'/real.jsonl'; @file_put_contents($real, json_encode(['run_id'=>'r','run_ts'=>date('c'),'test'=>'preflight-idle','ok'=>true])."\n");
        $link = $dir.'/link.jsonl'; @symlink($real, $link);
        $out = $this->runShow($link);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testDirectoryAsLogPathPrintsNoLog(): void
    {
        $dir = sys_get_temp_dir().'/pmss-bench-sec-dir-'.bin2hex(random_bytes(2)); @mkdir($dir, 0700, true);
        $out = $this->runShow($dir);
        $this->assertStringContainsString('No log at', $out);
    }

    public function testUnreadableLogDoesNotCrash(): void
    {
        $log = $this->mklog(); @file_put_contents($log, ''); @chmod($log, 0000);
        $out = $this->runShow($log);
        $this->assertStringContainsString('No runs found.', $out);
        @chmod($log, 0600);
    }

    public function testHugeLineDoesNotCrash(): void
    {
        $log = $this->mklog();
        $big = str_repeat('A', 50000);
        file_put_contents($log, '{"run_id":"r","run_ts":"'.date('c').'","test":"preflight-idle","label":"'.$big.'","ok":true}'."\n");
        $out = $this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testNullBytesInLogAreIgnored(): void
    {
        $log = $this->mklog();
        file_put_contents($log, "\0\0\0\n", FILE_APPEND);
        $out = $this->runShow($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testDeviceFieldLooksLikeShellInjection(): void
    {
        $log=$this->mklog(); $rid='inj'; $ts=date('c');
        $this->write($log, [["run_id"=>$rid,"run_ts"=>$ts,"test"=>'preflight-idle','ok'=>true], ["run_id"=>$rid,"run_ts"=>$ts,"device"=>'$(id)','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>1,'elapsed_s'=>1]]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMetricsLookLikeCommands(): void
    {
        $log=$this->mklog(); $rid='cmd'; $ts=date('c');
        $this->write($log, [["run_id"=>$rid,"run_ts"=>$ts,'test'=>'preflight-idle','ok'=>true], ["run_id"=>$rid,"run_ts"=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'], 'metrics'=>['read_bw_MBps'=>'`rm -rf /`','write_bw_MBps'=>0,'read_iops'=>0,'write_iops'=>0,'read_p95_ms'=>0,'write_p95_ms'=>0]]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testWeirdUnicodeInLabel(): void
    {
        $log=$this->mklog();
        $this->write($log, [['run_id'=>'u','run_ts'=>date('c'),'label'=>"Δδοκιμή😀","test"=>'preflight-idle','ok'=>true]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testMalformedUtf8InLabel(): void
    {
        $log=$this->mklog();
        $label = "bad\x80utf8"; // invalid byte sequence
        file_put_contents($log, '{"run_id":"x","run_ts":"'.date('c').'","label":"'.$label.'","test":"preflight-idle","ok":true}'."\n");
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testManyLinesDoNotTimeout(): void
    {
        $log=$this->mklog();
        for($i=0;$i<200;$i++){ $this->write($log, [['run_id'=>'r'.$i,'run_ts'=>gmdate('c', time()-200+$i),'test'=>'preflight-idle','ok'=>true]]); }
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testRunTsExtremelyLongString(): void
    {
        $log=$this->mklog(); $rid='longts'; $ts=str_repeat('2025-01-01T00:00:00Z', 10);
        $this->write($log, [['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Run ID: '.$rid, $out);
    }

    public function testRunIdAsArrayDoesNotCrash(): void
    {
        $log=$this->mklog();
        file_put_contents($log, '{"run_id":["a"],"run_ts":"'.date('c').'","test":"preflight-idle","ok":true}'."\n");
        $out=$this->runShow($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testObjectMetricsDoNotCrash(): void
    {
        $log=$this->mklog(); $rid='obj'; $ts=date('c');
        $this->write($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'],'metrics'=>['nested'=>['x'=>1]]] ]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testLeadingBOMInFileIsTolerated(): void
    {
        $log=$this->mklog();
        file_put_contents($log, "\xEF\xBB\xBF", FILE_APPEND);
        file_put_contents($log, json_encode(['run_id'=>'bom','run_ts'=>date('c'),'test'=>'preflight-idle','ok'=>true])."\n", FILE_APPEND);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testNonexistentPathReportsNicely(): void
    {
        $path = sys_get_temp_dir().'/pmss-bench-missing-'.bin2hex(random_bytes(2)).'/nope.jsonl';
        $out=$this->runShow($path);
        $this->assertStringContainsString('No log at', $out);
    }

    public function testSymlinkToDirectoryIsRejected(): void
    {
        $dir = sys_get_temp_dir().'/pmss-bench-linkdir-'.bin2hex(random_bytes(2));
        @mkdir($dir,0700,true); $link=$dir.'/l.jsonl'; @symlink($dir,$link);
        $out=$this->runShow($link);
        $this->assertStringContainsString('No log at', $out);
    }

    public function testNestedMetricsIgnoreUnknown(): void
    {
        $log=$this->mklog(); $rid='nest'; $ts=date('c');
        $this->write($log,[ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'seqread-large','params'=>['rw'=>'read'],'metrics'=>['deep'=>['a'=>1]]] ]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('seqread-large', $out);
    }

    public function testMalformedThenValidLineWorks(): void
    {
        $log=$this->mklog();
        file_put_contents($log, "{bad}\n", FILE_APPEND);
        $this->write($log, [['run_id'=>'ok','run_ts'=>date('c'),'test'=>'preflight-idle','ok'=>true]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testVeryLargeUnicodeLabel(): void
    {
        $log=$this->mklog(); $label=str_repeat('嗨', 1000);
        $this->write($log, [['run_id'=>'ul','run_ts'=>date('c'),'label'=>$label,'test'=>'preflight-idle','ok'=>true]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testDevicePathTraversalStringDoesNotCrash(): void
    {
        $log=$this->mklog(); $rid='trav'; $ts=date('c');
        $this->write($log, [['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/../../etc/passwd','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>1,'elapsed_s'=>1]]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testVeryOldRunTsStillParses(): void
    {
        $log=$this->mklog();
        $this->write($log,[ ['run_id'=>'old','run_ts'=>'1999-01-01T00:00:00Z','test'=>'preflight-idle','ok'=>true] ]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Run ID: old', $out);
    }

    public function testLabelWithNewlinesIsTolerated(): void
    {
        $log=$this->mklog(); $label="line1\nline2";
        $this->write($log,[ ['run_id'=>'nl','run_ts'=>date('c'),'label'=>$label,'test'=>'preflight-idle','ok'=>true] ]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }
}
