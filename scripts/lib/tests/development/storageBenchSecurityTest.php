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

    private function assertBenchmarkInputGuard(array $arguments, string $expectedMessage): void
    {
        $run = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/util/storageBenchmark.php',
            $arguments
        );

        $this->assertEquals(1, $run['result']['rc']);
        $this->assertEquals('', $run['result']['output']);
        $this->assertEquals($expectedMessage, (string) @file_get_contents($run['stderrPath']));
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

    public function testBenchmarkInputGuardsRejectInvalidScalarOptions(): void
    {
        $sizeError = "Error: --size must be a positive size (examples: 1G, 512M, 1048576).\n";
        $deviceSizeError = "Error: --dd-size must be a positive size (examples: 1G, 512M, 1048576).\n";
        foreach ([
            [['--size=bogus'], $sizeError],
            [['--size=0'], $sizeError],
            [['--devices', '--dd-size=bad'], $deviceSizeError],
            [['--devices', '--dd-size=0'], $deviceSizeError],
            [['--runtime=0'], "Error: --runtime must be a positive integer.\n"],
            [['--runtime=15junk'], "Error: --runtime must be a positive integer.\n"],
            [['--devices', '--device-runtime=0'], "Error: --device-runtime must be a positive integer.\n"],
            [['--idle-util=85percent'], "Error: --idle-util must be a non-negative integer.\n"],
        ] as $case) {
            $this->assertBenchmarkInputGuard($case[0], $case[1]);
        }
    }

    public function testIostatPreflightUsesSafeSerializedArrayReader(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/storageBenchmark.php');

        $this->assertStringContainsString('storageBenchmarkIostatUtilPctRead', $source);
        $this->assertStringContainsString('pmssReadSerializedArrayFile($path)', $source);
        $this->assertStringNotContainsString('unserialize(', $source);
    }

    public function testUnsafeTargetTraversalFailsBeforeBenchmarkWork(): void
    {
        $base = $this->pmssMakeTempDir('pmss-bench-target-', 0700);
        @mkdir($base.'/safe', 0700, true);
        $target = $base.'/safe/../safe';
        $log = $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');

        $this->assertBenchmarkInputGuard(
            ['--target='.$target, '--json='.$log],
            "Error: unsafe target directory: {$target}\n"
        );
    }

    public function testSymlinkTargetFailsBeforeBenchmarkWork(): void
    {
        $real = $this->pmssMakeTempDir('pmss-bench-target-real-', 0700);
        $link = sys_get_temp_dir().'/pmss-bench-target-link-'.bin2hex(random_bytes(2));
        $this->pmssCreateSymlinkOrSkip($real, $link);
        $log = $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');

        $this->assertBenchmarkInputGuard(
            ['--target='.$link, '--json='.$log],
            "Error: unsafe target directory: {$link}\n"
        );
    }

    public function testJsonLogParentFileFailsBeforeBenchmarkWork(): void
    {
        $parent = $this->pmssMakeTempFile('pmss-bench-parent-');
        $this->assertBenchmarkInputGuard(
            ['--json='.$parent.'/benchmark-storage.jsonl'],
            "Error: failed to create JSON log directory: {$parent}\n"
        );
    }

    public function testDirectoryJsonLogPathFailsBeforeBenchmarkWork(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-bench-logdir-', 0700);
        $this->assertBenchmarkInputGuard(
            ['--json='.$dir],
            "Error: unsafe JSON log path: {$dir}\n"
        );
    }

    public function testUnsafeJsonLogTraversalDoesNotCreateParents(): void
    {
        $base = $this->pmssMakeTempDir('pmss-bench-traversal-', 0700);
        $log = $base.'/unsafe/../blocked/benchmark-storage.jsonl';
        $this->assertBenchmarkInputGuard(
            ['--json='.$log],
            "Error: unsafe JSON log path: {$log}\n"
        );
        $this->assertFalse(is_dir($base.'/unsafe'), 'unsafe traversal prefix should not be created');
        $this->assertFalse(is_dir($base.'/blocked'), 'unsafe traversal target should not be created');
    }

    public function testSymlinkJsonLogParentDoesNotCreateBehindLink(): void
    {
        $real = $this->pmssMakeTempDir('pmss-bench-real-', 0700);
        $link = sys_get_temp_dir().'/pmss-bench-link-'.bin2hex(random_bytes(2));
        $this->pmssCreateSymlinkOrSkip($real, $link);

        $log = $link.'/child/benchmark-storage.jsonl';
        $this->assertBenchmarkInputGuard(
            ['--json='.$log],
            "Error: unsafe JSON log path: {$log}\n"
        );
        $this->assertFalse(is_dir($real.'/child'), 'symlinked parent should not receive new benchmark directories');
    }

    public function testDeviceBenchmarksSkipDevicesWithoutPositiveBlockSize(): void
    {
        $target = $this->pmssMakeTempDir('pmss-bench-target-', 0700);
        $jsonLog = $this->pmssMakeJsonLogPath('pmss-bench-device-', 'benchmark-storage.jsonl');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-stubs-', 0700);
        $invocations = $this->pmssMakeTempPath('pmss-bench-invocations-', '.log');
        @file_put_contents($invocations, '');

        $this->pmssWriteExecutableFile($stubDir.'/lsblk', "#!/bin/sh\nprintf '%s\\n' 'null disk 0 NullDevice NULLSER 1B'\n");
        $this->pmssWriteExecutableFile($stubDir.'/blockdev', "#!/bin/sh\nprintf '%s\\n' 'not-a-size'\nexit 1\n");
        $this->pmssWriteExecutableFile($stubDir.'/fallocate', "#!/bin/sh\nexit 0\n");
        $this->pmssWriteExecutableFile($stubDir.'/ioping', "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0/2.0/3.0/0.1 ms'\n");
        $this->pmssWriteExecutableFile($stubDir.'/dd', "#!/bin/sh\nprintf 'DD %s\\n' \"\$*\" >>\"\${PMSS_TEST_INVOCATION_LOG:?}\"\nprintf '%s\\n' '1+0 records in' '1+0 records out' '1048576 bytes copied, 1 s, 1.0 MB/s' >&2\nexit 0\n");
        $this->pmssWriteExecutableFile($stubDir.'/fio', <<<'SH'
#!/bin/sh
out=''
args="$*"
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output)
            shift
            out="${1:-}"
            ;;
        --output=*)
            out="${1#--output=}"
            ;;
    esac
    shift || break
done
printf 'FIO %s\n' "$args" >>"${PMSS_TEST_INVOCATION_LOG:?}"
cat >"$out" <<'JSON'
{"jobs":[{"read":{"bw_bytes":1048576,"iops":1,"clat_ns":{"percentile":{"95.000000":1000000}}},"write":{"bw_bytes":0,"iops":0,"clat_ns":{"percentile":{"95.000000":0}}}}]}
JSON
SH
        );

        $result = $this->pmssRunRepoPhpScriptCommand(
            'scripts/util/storageBenchmark.php',
            ['--target='.$target, '--json='.$jsonLog, '--size=1M', '--runtime=1', '--devices', '--dd-size=1M', '--device-runtime=1'],
            $this->pmssPathPrefixedEnvironment($stubDir, ['PMSS_TEST_INVOCATION_LOG' => $invocations])
        );

        $this->assertSame(0, $result['rc']);
        $log = (string) @file_get_contents($jsonLog);
        $this->assertStringContainsString('"device":"/dev/null"', $log);
        $this->assertStringContainsString('"test":"device-preflight"', $log);
        $this->assertStringContainsString('"error":"unable to determine block device size"', $log);
        $this->assertStringNotContainsString('DD if=/dev/null', (string) @file_get_contents($invocations));
        $this->assertStringNotContainsString('--filename=/dev/null', (string) @file_get_contents($invocations));
    }
}
