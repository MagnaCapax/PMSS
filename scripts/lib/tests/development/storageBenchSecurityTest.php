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

        $this->pmssAssertCommandFailsToStderr($run['result'], $run['stderrPath'], $expectedMessage);
    }

    private function assertShowPathContains(string $path, string $expected, string $case = ''): void
    {
        $this->assertStringContainsString($expected, $this->runShow($path), $case);
    }

    private function assertSecurityLogContains(array $entries, string $expected, string $case = ''): void
    {
        $this->assertShowPathContains($this->pmssWriteStorageBenchmarkLog($entries, 'pmss-bench-sec-'), $expected, $case);
    }

    private function assertSecurityRunContains(
        string $runId,
        string $expected,
        array $preflightExtra = [],
        array $entries = [],
        ?string $runTs = null,
        string $case = ''
    ): void {
        $runTs = $runTs ?? date('c');
        $log = $this->pmssWriteStorageBenchmarkRunLog($runId, $runTs, $entries, $preflightExtra, 'pmss-bench-sec-');
        $this->assertShowPathContains($log, $expected, $case);
    }

    private function benchmarkSecurityLogPath(): string
    {
        return $this->pmssMakeJsonLogPath('pmss-bench-sec-', 'benchmark-storage.jsonl');
    }

    private function writeBenchmarkSecurityLog(string $content, int $flags = 0): string
    {
        $log = $this->benchmarkSecurityLogPath();
        file_put_contents($log, $content, $flags);
        return $log;
    }

    public function testPathTraversalInJsonPathIsRead(): void
    {
        $log = $this->benchmarkSecurityLogPath(); @touch($log);
        $up = dirname($log).'/../'.basename(dirname($log)).'/'.basename($log);
        $this->assertShowPathContains($up, 'No runs found.');
    }

    public function testSymlinkJsonLogIsHandled(): void
    {
        $log = $this->benchmarkSecurityLogPath(); $dir = dirname($log); @mkdir($dir, 0700, true);
        $real = $dir.'/real.jsonl'; @file_put_contents($real, json_encode($this->pmssStorageBenchmarkPreflightEntry('r', date('c')))."\n");
        $link = $dir.'/link.jsonl'; @symlink($real, $link);
        $this->assertShowPathContains($link, 'Storage benchmark (last run)');
    }

    public function testDirectoryAsLogPathPrintsNoLog(): void
    {
        $this->assertShowPathContains($this->pmssMakeTempDir('pmss-bench-sec-dir-', 0700), 'No log at');
    }

    public function testUnreadableLogDoesNotCrash(): void
    {
        $log = $this->writeBenchmarkSecurityLog(''); @chmod($log, 0000);
        $this->assertShowPathContains($log, 'No runs found.');
        @chmod($log, 0600);
    }

    public function testHugeLineDoesNotCrash(): void
    {
        $big = str_repeat('A', 50000);
        $log = $this->writeBenchmarkSecurityLog('{"run_id":"r","run_ts":"'.date('c').'","test":"preflight-idle","label":"'.$big.'","ok":true}'."\n");
        $this->assertShowPathContains($log, 'Storage benchmark (last run)');
    }

    public function testNullBytesInLogAreIgnored(): void
    {
        $this->assertShowPathContains($this->writeBenchmarkSecurityLog("\0\0\0\n", FILE_APPEND), 'No runs found.');
    }

    public function testUntrustedMetricAndDeviceFieldsAreTolerated(): void
    {
        $ts = date('c');
        foreach ([
            ['device shell text', 'inj', $this->pmssStorageBenchmarkEntry('inj', $ts, 'device-seqread-dd', ['device' => '$(id)', 'metrics' => ['seqread_MBps' => 1, 'elapsed_s' => 1]]), 'Per-device tests'],
            ['metric command text', 'cmd', $this->pmssStorageBenchmarkFileEntry('cmd', $ts, 'randread-small', ['read_bw_MBps' => '`rm -rf /`', 'read_iops' => 0, 'read_p95_ms' => 0]), 'randread-small'],
            ['nested object metric', 'obj', $this->pmssStorageBenchmarkEntry('obj', $ts, 'randread-small', ['params' => ['rw' => 'randread'], 'metrics' => ['nested' => ['x' => 1]]]), 'randread-small'],
            ['unknown nested metric', 'nest', $this->pmssStorageBenchmarkEntry('nest', $ts, 'seqread-large', ['params' => ['rw' => 'read'], 'metrics' => ['deep' => ['a' => 1]]]), 'seqread-large'],
            ['device traversal text', 'trav', $this->pmssStorageBenchmarkEntry('trav', $ts, 'device-seqread-dd', ['device' => '/dev/../../etc/passwd', 'metrics' => ['seqread_MBps' => 1, 'elapsed_s' => 1]]), 'Per-device tests'],
        ] as $case) {
            $this->assertSecurityRunContains($case[1], $case[3], [], [$case[2]], $ts, $case[0].': ');
        }
    }

    public function testMalformedUtf8InLabel(): void
    {
        $label = "bad\x80utf8"; // invalid byte sequence
        $log = $this->writeBenchmarkSecurityLog('{"run_id":"x","run_ts":"'.date('c').'","label":"'.$label.'","test":"preflight-idle","ok":true}'."\n");
        $this->assertShowPathContains($log, 'Storage benchmark (last run)');
    }

    public function testManyLinesDoNotTimeout(): void
    {
        $log = $this->benchmarkSecurityLogPath();
        for($i=0;$i<200;$i++){ $ts = gmdate('c', time()-200+$i); $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkPreflightEntry('r'.$i, $ts)]); }
        $this->assertShowPathContains($log, 'Storage benchmark (last run)');
    }

    public function testRunIdAsArrayDoesNotCrash(): void
    {
        $this->assertShowPathContains($this->writeBenchmarkSecurityLog('{"run_id":["a"],"run_ts":"'.date('c').'","test":"preflight-idle","ok":true}'."\n"), 'No runs found.');
    }

    public function testLeadingBOMInFileIsTolerated(): void
    {
        $log = $this->benchmarkSecurityLogPath();
        file_put_contents($log, "\xEF\xBB\xBF", FILE_APPEND);
        file_put_contents($log, json_encode($this->pmssStorageBenchmarkPreflightEntry('bom', date('c')))."\n", FILE_APPEND);
        $this->assertShowPathContains($log, 'Storage benchmark (last run)');
    }

    public function testNonexistentPathReportsNicely(): void
    {
        $path = sys_get_temp_dir().'/pmss-bench-missing-'.bin2hex(random_bytes(2)).'/nope.jsonl';
        $this->assertShowPathContains($path, 'No log at');
    }

    public function testSymlinkToDirectoryIsRejected(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-bench-linkdir-', 0700);
        $link = $dir.'/l.jsonl';
        @symlink($dir, $link);
        $this->assertShowPathContains($link, 'No log at');
    }

    public function testMalformedThenValidLineWorks(): void
    {
        $log = $this->benchmarkSecurityLogPath();
        file_put_contents($log, "{bad}\n", FILE_APPEND);
        $this->pmssAppendFixtureLines($log, [$this->pmssStorageBenchmarkPreflightEntry('ok', date('c'))]);
        $this->assertShowPathContains($log, 'Storage benchmark (last run)');
    }

    public function testUntrustedPreflightDisplayValuesAreTolerated(): void
    {
        foreach ([
            ['unicode label', 'u', date('c'), ['label' => "Δδοκιμή😀"], 'Storage benchmark (last run)'],
            ['large unicode label', 'ul', date('c'), ['label' => str_repeat('嗨', 1000)], 'Storage benchmark (last run)'],
            ['label newline', 'nl', date('c'), ['label' => "line1\nline2"], 'Storage benchmark (last run)'],
            ['very old timestamp', 'old', '1999-01-01T00:00:00Z', [], 'Run ID: old'],
            ['long timestamp', 'longts', str_repeat('2025-01-01T00:00:00Z', 10), [], 'Run ID: longts'],
        ] as $case) {
            $this->assertSecurityRunContains($case[1], $case[4], $case[3], [], $case[2], $case[0].': ');
        }
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
            [['--devices', '--dd-size=512K'], "Error: --dd-size must be at least 1 MiB.\n"],
        ] as $case) {
            $this->assertBenchmarkInputGuard($case[0], $case[1]);
        }
    }

    public function testSharedBenchmarkEntryHelpersPreserveExecutionShape(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/storageBenchmark.php');

        $success = \storageBenchmarkApplyRunResult(['test' => 'randread', 'ok' => true], ['ok' => true, 'result' => ['read_bw_MBps' => 12.5]]);
        $failure = \storageBenchmarkApplyRunResult(['test' => 'randwrite', 'ok' => false], ['ok' => false], 'fio failed');
        $log = $this->pmssMakeJsonLogPath('pmss-bench-shared-', 'benchmark-storage.jsonl');
        [$unused, $output] = $this->pmssCaptureStdout(static function () use ($log): void {
            \storageBenchmarkDevicePreflightSkip($log, \storageBenchmarkEntryBase('2025-01-01T00:00:00Z', '', 'run'), ['model' => 'M', 'serial' => 'S', 'rota' => 1, 'size' => '1T'], '/dev/null', 'not a readable block device');
        });
        $entries = \pmssJsonLineFileRead($log);

        $this->assertSame(['test' => 'randread', 'ok' => true, 'metrics' => ['read_bw_MBps' => 12.5]], $success);
        $this->assertSame(['test' => 'randwrite', 'ok' => false, 'error' => 'fio failed'], $failure);
        $this->assertSame(15.0, \storageBenchmarkPeerMedian([['dd_mb' => null], ['dd_mb' => 20], ['dd_mb' => 10]], 'dd_mb'));
        $this->assertStringContainsString("/dev/null\tskipped: not a readable block device", $output);
        $this->assertSame('device-preflight', $entries[0]['test'] ?? '');
        $this->assertSame('not a readable block device', $entries[0]['error'] ?? '');
    }

    public function testStorageBenchmarkSourceKeepsSafetyHelpers(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/storageBenchmark.php');

        foreach ([
            'iostat serialized array reader' => [['storageBenchmarkIostatUtilPctRead', 'pmssReadSerializedArrayFile($path)'], ['unserialize(']],
            'file-backed checked capture' => [['storageBenchmarkRequireCommandField', 'pmssCommandCapture($command, 30)', "'free space', true"], ["\$free=(int)trim((string) shell_exec('df -PB1 ", 'storageBenchmarkRequirePositive'.'IntCommandField']],
            'device checked capture' => [['pmssStorageHealthDiskInventoryRead()', 'storageBenchmarkDeviceSizeBytesRead', "pmssCommandCapture('blockdev --getsize64 "], ["shell_exec('lsblk -dn", "shell_exec('blockdev --getsize64 "]],
        ] as $label => $case) {
            $this->assertStringContainsAndOmitsStrings($case[0], $case[1], $source, $label.': ');
        }
    }

    public function testDiskInventoryRejectsNonzeroCommandOutput(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/storageHealth/common.php');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-lsblk-', 0700);
        $this->pmssWriteExecutableFiles($stubDir, [
            'lsblk' => "#!/bin/sh\nprintf '%s\\n' 'pmssfake0 disk 1 PMSSSERIAL 1T'\nexit 1\n",
        ]);

        $this->pmssWithPathPrefix($stubDir, function (): void {
            $this->assertSame([], \pmssStorageHealthDiskInventoryRead());
        });
    }

    public function testBlockDeviceSizeRejectsNonzeroCommandOutput(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/storageBenchmark.php');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-blockdev-', 0700);
        $this->pmssWriteExecutableFiles($stubDir, [
            'blockdev' => "#!/bin/sh\nprintf '%s\\n' '1048576'\nexit 1\n",
        ]);

        $this->pmssWithPathPrefix($stubDir, function (): void {
            $this->assertSame(null, \storageBenchmarkDeviceSizeBytesRead('/dev/pmss-test'));
        });
    }

    public function testBlockDeviceSizeRejectsUnsafePathBeforeCommand(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/storageBenchmark.php');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-blockdev-', 0700);
        $invocations = $this->pmssMakeTempPath('pmss-bench-blockdev-', '.log');
        @file_put_contents($invocations, '');
        $this->pmssWriteExecutableFiles($stubDir, [
            'blockdev' => "#!/bin/sh\nprintf '%s\\n' \"\$*\" >>\"\${PMSS_TEST_INVOCATION_LOG:?}\"\nprintf '%s\\n' '1048576'\n",
        ]);

        $this->pmssWithPathPrefixedEnv($stubDir, ['PMSS_TEST_INVOCATION_LOG' => $invocations], function () use ($invocations): void {
            $this->assertSame(null, \storageBenchmarkDeviceSizeBytesRead('/tmp/pmss-test'));
            $this->assertSame('', (string) @file_get_contents($invocations));
        });
    }

    public function testFileBackedTempFileIsCleanedAfterLateAppendFailure(): void
    {
        $target = $this->pmssMakeTempDir('pmss-bench-target-', 0700);
        $jsonLog = $this->pmssMakeJsonLogPath('pmss-bench-cleanup-', 'benchmark-storage.jsonl');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-stubs-', 0700);

        $this->pmssWriteExecutableFiles($stubDir, [
            'stat' => "#!/bin/sh\nprintf '%s\\n' 'ext2/ext3'\n",
            'df' => <<<'SH'
#!/bin/sh
if [ "${1:-}" = "-PB1" ]; then
    printf '%s\n' 'Filesystem 1B-blocks Used Available Use% Mounted on'
    printf '%s\n' 'pmssfs 10485760 0 10485760 1% /tmp'
    exit 0
fi
printf '%s\n' 'Filesystem 1024-blocks Used Available Capacity Mounted on'
printf '%s\n' 'pmssfs 10240 0 10240 1% /tmp'
SH,
            'fallocate' => <<<'SH'
#!/bin/sh
last=''
for arg in "$@"; do
    last="$arg"
done
: >"$last"
SH,
            'ioping' => "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0/2.0/3.0/0.1 ms'\n",
            'fio' => <<<'SH'
#!/bin/sh
out=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output)
            shift
            out="${1:-}"
            ;;
    esac
    shift || break
done
cat >"$out" <<'JSON'
{"jobs":[{"read":{"bw_bytes":1048576,"iops":1,"clat_ns":{"percentile":{"95.000000":1000000}}},"write":{"bw_bytes":0,"iops":0,"clat_ns":{"percentile":{"95.000000":0}}}}]}
JSON
rm -f "${PMSS_TEST_JSON_LOG:?}"
mkdir "${PMSS_TEST_JSON_LOG:?}"
SH
        ]);

        $run = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/util/storageBenchmark.php',
            ['--target='.$target, '--json='.$jsonLog, '--size=1M', '--runtime=1'],
            $this->pmssPathPrefixedEnvironment($stubDir, ['PMSS_TEST_JSON_LOG' => $jsonLog])
        );

        $this->assertSame(1, $run['result']['rc']);
        $this->assertSame("Error: failed to append JSON log entry: {$jsonLog}\n", (string) @file_get_contents($run['stderrPath']));
        $this->assertSame([], glob($target.'/pmss-fio-*.dat'));
    }

    public function testFileBackedPreallocationFailureFailsBeforeFioWork(): void
    {
        $target = $this->pmssMakeTempDir('pmss-bench-target-', 0700);
        $jsonLog = $this->pmssMakeJsonLogPath('pmss-bench-fallocate-', 'benchmark-storage.jsonl');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-stubs-', 0700);
        $invocations = $this->pmssMakeTempPath('pmss-bench-invocations-', '.log');
        @file_put_contents($invocations, '');

        $this->pmssWriteExecutableFiles($stubDir, [
            'stat' => "#!/bin/sh\nprintf '%s\\n' 'ext2/ext3'\n",
            'df' => <<<'SH'
#!/bin/sh
if [ "${1:-}" = "-PB1" ]; then
    printf '%s\n' 'Filesystem 1B-blocks Used Available Use% Mounted on'
    printf '%s\n' 'pmssfs 10485760 0 10485760 1% /tmp'
    exit 0
fi
printf '%s\n' 'Filesystem 1024-blocks Used Available Capacity Mounted on'
printf '%s\n' 'pmssfs 10240 0 10240 1% /tmp'
SH,
            'fallocate' => "#!/bin/sh\nlast=''\nfor arg in \"\$@\"; do last=\"\$arg\"; done\n: >\"\$last\"\nprintf 'FALLOCATE %s\\n' \"\$*\" >>\"\${PMSS_TEST_INVOCATION_LOG:?}\"\nexit 1\n",
            'ioping' => "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0/2.0/3.0/0.1 ms'\n",
            'fio' => "#!/bin/sh\nprintf 'FIO %s\\n' \"\$*\" >>\"\${PMSS_TEST_INVOCATION_LOG:?}\"\nexit 0\n",
        ]);

        $run = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/util/storageBenchmark.php',
            ['--target='.$target, '--json='.$jsonLog, '--size=1M', '--runtime=1'],
            $this->pmssPathPrefixedEnvironment($stubDir, ['PMSS_TEST_INVOCATION_LOG' => $invocations])
        );

        $this->assertSame(1, $run['result']['rc']);
        $this->assertStringContainsString("Error: failed to preallocate benchmark file.\n", (string) @file_get_contents($run['stderrPath']));
        $this->assertStringContainsString('FALLOCATE ', (string) @file_get_contents($invocations));
        $this->assertStringNotContainsString('FIO ', (string) @file_get_contents($invocations));
        $this->assertSame([], glob($target.'/pmss-fio-*.dat'));
    }

    public function testBenchmarkPathGuardsFailBeforeBenchmarkWork(): void
    {
        $targetBase = $this->pmssMakeTempDir('pmss-bench-target-', 0700);
        @mkdir($targetBase.'/safe', 0700, true);
        $target = $targetBase.'/safe/../safe';
        $parent = $this->pmssMakeTempFile('pmss-bench-parent-');
        $dir = $this->pmssMakeTempDir('pmss-bench-logdir-', 0700);
        $base = $this->pmssMakeTempDir('pmss-bench-traversal-', 0700);
        $log = $base.'/unsafe/../blocked/benchmark-storage.jsonl';

        foreach ([
            'target traversal' => [['--target='.$target, '--json='.$this->benchmarkSecurityLogPath()], "Error: unsafe target directory: {$target}\n"],
            'JSON log parent file' => [['--json='.$parent.'/benchmark-storage.jsonl'], "Error: failed to create JSON log directory: {$parent}\n"],
            'directory JSON log path' => [['--json='.$dir], "Error: unsafe JSON log path: {$dir}\n"],
            'JSON log traversal' => [['--json='.$log], "Error: unsafe JSON log path: {$log}\n"],
        ] as $case) {
            $this->assertBenchmarkInputGuard($case[0], $case[1]);
        }

        $this->assertFalse(is_dir($base.'/unsafe'), 'unsafe traversal prefix should not be created');
        $this->assertFalse(is_dir($base.'/blocked'), 'unsafe traversal target should not be created');
    }

    public function testBenchmarkPathGuardsRejectSymlinksBeforeBenchmarkWork(): void
    {
        $targetReal = $this->pmssMakeTempDir('pmss-bench-target-real-', 0700);
        $targetLink = sys_get_temp_dir().'/pmss-bench-target-link-'.bin2hex(random_bytes(2));
        $this->pmssCreateSymlinkOrSkip($targetReal, $targetLink);

        $this->assertBenchmarkInputGuard(
            ['--target='.$targetLink, '--json='.$this->benchmarkSecurityLogPath()],
            "Error: unsafe target directory: {$targetLink}\n"
        );

        $logReal = $this->pmssMakeTempDir('pmss-bench-real-', 0700);
        $logLink = sys_get_temp_dir().'/pmss-bench-link-'.bin2hex(random_bytes(2));
        $this->pmssCreateSymlinkOrSkip($logReal, $logLink);

        $log = $logLink.'/child/benchmark-storage.jsonl';
        $this->assertBenchmarkInputGuard(
            ['--json='.$log],
            "Error: unsafe JSON log path: {$log}\n"
        );
        $this->assertFalse(is_dir($logReal.'/child'), 'symlinked parent should not receive new benchmark directories');
    }

    public function testDeviceBenchmarksSkipNonBlockDevicesBeforeRawReads(): void
    {
        $target = $this->pmssMakeTempDir('pmss-bench-target-', 0700);
        $jsonLog = $this->pmssMakeJsonLogPath('pmss-bench-device-', 'benchmark-storage.jsonl');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-stubs-', 0700);
        $invocations = $this->pmssMakeTempPath('pmss-bench-invocations-', '.log');
        @file_put_contents($invocations, '');

        $this->pmssWriteExecutableFiles($stubDir, [
            'lsblk' => "#!/bin/sh\nprintf '%s\\n' 'null disk 0 NullDevice NULLSER 1B'\n",
            'blockdev' => "#!/bin/sh\nprintf '%s\\n' 'not-a-size'\nexit 1\n",
            'fallocate' => "#!/bin/sh\nexit 0\n",
            'ioping' => "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0/2.0/3.0/0.1 ms'\n",
            'dd' => "#!/bin/sh\nprintf 'DD %s\\n' \"\$*\" >>\"\${PMSS_TEST_INVOCATION_LOG:?}\"\nprintf '%s\\n' '1+0 records in' '1+0 records out' '1048576 bytes copied, 1 s, 1.0 MB/s' >&2\nexit 0\n",
            'fio' => <<<'SH'
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
        ]);

        $result = $this->pmssRunRepoPhpScriptCommand(
            'scripts/util/storageBenchmark.php',
            ['--target='.$target, '--json='.$jsonLog, '--size=1M', '--runtime=1', '--devices', '--dd-size=1M', '--device-runtime=1'],
            $this->pmssPathPrefixedEnvironment($stubDir, ['PMSS_TEST_INVOCATION_LOG' => $invocations])
        );

        $this->assertSame(0, $result['rc']);
        $log = (string) @file_get_contents($jsonLog);
        $this->assertStringContainsAllStrings(['"device":"/dev/null"', '"test":"device-preflight"', '"error":"not a readable block device"'], $log);
        $this->assertStringContainsAndOmitsStrings([], ['DD if=/dev/null', '--filename=/dev/null'], (string) @file_get_contents($invocations));
    }

    public function testInvalidFreeSpaceProbeFailsBeforeFileBackedBenchmarkWork(): void
    {
        $target = $this->pmssMakeTempDir('pmss-bench-target-', 0700);
        $jsonLog = $this->pmssMakeJsonLogPath('pmss-bench-df-', 'benchmark-storage.jsonl');
        $stubDir = $this->pmssMakeTempDir('pmss-bench-stubs-', 0700);
        $invocations = $this->pmssMakeTempPath('pmss-bench-invocations-', '.log');
        @file_put_contents($invocations, '');

        $this->pmssWriteExecutableFiles($stubDir, [
            'stat' => "#!/bin/sh\nprintf '%s\\n' 'ext2/ext3'\n",
            'df' => <<<'SH'
#!/bin/sh
if [ "${1:-}" = "-PB1" ]; then
    printf '%s\n' 'Filesystem 1B-blocks Used Available Use% Mounted on'
    printf '%s\n' 'pmssfs 100 1 notbytes 1% /tmp'
    exit 0
fi
printf '%s\n' 'Filesystem 1024-blocks Used Available Capacity Mounted on'
printf '%s\n' 'pmssfs 100 1 99 1% /tmp'
SH,
            'fallocate' => "#!/bin/sh\nprintf 'FALLOCATE %s\\n' \"\$*\" >>\"\${PMSS_TEST_INVOCATION_LOG:?}\"\nexit 0\n",
            'ioping' => "#!/bin/sh\nprintf '%s\\n' 'min/avg/max/mdev = 1.0/2.0/3.0/0.1 ms'\n",
            'fio' => "#!/bin/sh\nprintf 'FIO %s\\n' \"\$*\" >>\"\${PMSS_TEST_INVOCATION_LOG:?}\"\nexit 0\n",
        ]);

        $run = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/util/storageBenchmark.php',
            ['--target='.$target, '--json='.$jsonLog, '--size=1M', '--runtime=1'],
            $this->pmssPathPrefixedEnvironment($stubDir, ['PMSS_TEST_INVOCATION_LOG' => $invocations])
        );

        $this->pmssAssertCommandFailsToStderr($run['result'], $run['stderrPath'], "Error: failed to read free space.\n");
        $this->assertStringContainsAndOmitsStrings([], ['FALLOCATE ', 'FIO '], (string) @file_get_contents($invocations));
    }
}
