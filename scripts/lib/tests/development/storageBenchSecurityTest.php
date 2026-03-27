<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageBenchSecurityTest extends TestCase
{
    private function runShow(string $path): string
    {
        if ($this->isSandbox()) {
            throw new SkipTest('skip in sandbox');
        }
        return $this->pmssRunStorageBenchmarkShowLast($path);
    }

    public function testPathTraversalInJsonPathIsRead(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl'); @touch($log);
        $up = dirname($log).'/../'.basename(dirname($log)).'/'.basename($log);
        $out = $this->runShow($up);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testSymlinkJsonLogIsHandled(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl'); $dir = dirname($log); @mkdir($dir, 0700, true);
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
        $log = $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl'); @file_put_contents($log, ''); @chmod($log, 0000);
        $out = $this->runShow($log);
        $this->assertStringContainsString('No runs found.', $out);
        @chmod($log, 0600);
    }

    public function testHugeLineDoesNotCrash(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
        $big = str_repeat('A', 50000);
        file_put_contents($log, '{"run_id":"r","run_ts":"'.date('c').'","test":"preflight-idle","label":"'.$big.'","ok":true}'."\n");
        $out = $this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testNullBytesInLogAreIgnored(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
        file_put_contents($log, "\0\0\0\n", FILE_APPEND);
        $out = $this->runShow($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testDeviceFieldLooksLikeShellInjection(): void
    {
        $rid='inj'; $ts=date('c');
        $log = $this->pmssWriteStorageBenchmarkLog([["run_id"=>$rid,"run_ts"=>$ts,"test"=>'preflight-idle','ok'=>true], ["run_id"=>$rid,"run_ts"=>$ts,"device"=>'$(id)','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>1,'elapsed_s'=>1]]], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testMetricsLookLikeCommands(): void
    {
        $rid='cmd'; $ts=date('c');
        $log = $this->pmssWriteStorageBenchmarkLog([["run_id"=>$rid,"run_ts"=>$ts,'test'=>'preflight-idle','ok'=>true], ["run_id"=>$rid,"run_ts"=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'], 'metrics'=>['read_bw_MBps'=>'`rm -rf /`','write_bw_MBps'=>0,'read_iops'=>0,'write_iops'=>0,'read_p95_ms'=>0,'write_p95_ms'=>0]]], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testWeirdUnicodeInLabel(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
        $this->pmssAppendFixtureLines($log, [['run_id'=>'u','run_ts'=>date('c'),'label'=>"Δδοκιμή😀","test"=>'preflight-idle','ok'=>true]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testMalformedUtf8InLabel(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
        $label = "bad\x80utf8"; // invalid byte sequence
        file_put_contents($log, '{"run_id":"x","run_ts":"'.date('c').'","label":"'.$label.'","test":"preflight-idle","ok":true}'."\n");
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testManyLinesDoNotTimeout(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
        for($i=0;$i<200;$i++){ $this->pmssAppendFixtureLines($log, [['run_id'=>'r'.$i,'run_ts'=>gmdate('c', time()-200+$i),'test'=>'preflight-idle','ok'=>true]]); }
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testRunTsExtremelyLongString(): void
    {
        $rid='longts'; $ts=str_repeat('2025-01-01T00:00:00Z', 10);
        $log = $this->pmssWriteStorageBenchmarkLog([['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true]], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('Run ID: '.$rid, $out);
    }

    public function testRunIdAsArrayDoesNotCrash(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
        file_put_contents($log, '{"run_id":["a"],"run_ts":"'.date('c').'","test":"preflight-idle","ok":true}'."\n");
        $out=$this->runShow($log);
        $this->assertStringContainsString('No runs found.', $out);
    }

    public function testObjectMetricsDoNotCrash(): void
    {
        $rid='obj'; $ts=date('c');
        $log = $this->pmssWriteStorageBenchmarkLog([ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'randread-small','params'=>['rw'=>'randread'],'metrics'=>['nested'=>['x'=>1]]] ], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testLeadingBOMInFileIsTolerated(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
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
        $rid='nest'; $ts=date('c');
        $log = $this->pmssWriteStorageBenchmarkLog([ ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'test'=>'seqread-large','params'=>['rw'=>'read'],'metrics'=>['deep'=>['a'=>1]]] ], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('seqread-large', $out);
    }

    public function testMalformedThenValidLineWorks(): void
    {
        $log=$this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
        file_put_contents($log, "{bad}\n", FILE_APPEND);
        $this->pmssAppendFixtureLines($log, [['run_id'=>'ok','run_ts'=>date('c'),'test'=>'preflight-idle','ok'=>true]]);
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testVeryLargeUnicodeLabel(): void
    {
        $label=str_repeat('嗨', 1000);
        $log = $this->pmssWriteStorageBenchmarkLog([['run_id'=>'ul','run_ts'=>date('c'),'label'=>$label,'test'=>'preflight-idle','ok'=>true]], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }

    public function testDevicePathTraversalStringDoesNotCrash(): void
    {
        $rid='trav'; $ts=date('c');
        $log = $this->pmssWriteStorageBenchmarkLog([['run_id'=>$rid,'run_ts'=>$ts,'test'=>'preflight-idle','ok'=>true], ['run_id'=>$rid,'run_ts'=>$ts,'device'=>'/dev/../../etc/passwd','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>1,'elapsed_s'=>1]]], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('Per-device tests', $out);
    }

    public function testVeryOldRunTsStillParses(): void
    {
        $log = $this->pmssWriteStorageBenchmarkLog([['run_id'=>'old','run_ts'=>'1999-01-01T00:00:00Z','test'=>'preflight-idle','ok'=>true]], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('Run ID: old', $out);
    }

    public function testLabelWithNewlinesIsTolerated(): void
    {
        $label="line1\nline2";
        $log = $this->pmssWriteStorageBenchmarkLog([['run_id'=>'nl','run_ts'=>date('c'),'label'=>$label,'test'=>'preflight-idle','ok'=>true]], 'pmss-bench-sec-');
        $out=$this->runShow($log);
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
    }
}
