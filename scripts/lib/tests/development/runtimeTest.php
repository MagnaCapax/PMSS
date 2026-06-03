<?php
namespace PMSS\Tests;

// Tests for runtime helpers (loaded via scripts/lib/update.php)
require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/log.php';

class RuntimeTest extends TestCase
{
    public function testRuntimeRequireOnceOwnsDefinitionContract(): void
    {
        $runtime = var_export(dirname(__DIR__, 3).'/lib/runtime.php', true);
        $script = "require_once {$runtime}; require_once {$runtime}; echo json_encode([".
            "'runCommand'=>function_exists('runCommand'),".
            "'capture'=>function_exists('pmssCommandCapture'),".
            "'snapshot'=>function_exists('pmssRunSnapshotLogTask')".
            "]);";

        $this->assertSame([
            'runCommand' => true,
            'capture'    => true,
            'snapshot'   => true,
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testRuntimeFacadeExportsDecomposedHelperSurface(): void
    {
        $runtime = var_export(dirname(__DIR__, 3).'/lib/runtime.php', true);
        $script = "require_once {$runtime}; echo json_encode([".
            "'time_keys'=>array_keys(pmssStatsCompareTimesBuild(0)),".
            "'size_bytes'=>(int) pmssParseSizeToBytes('1G', true, true),".
            "'argv_quote'=>pmssCommandArgvShellQuote(['alpha beta', 'gamma']),".
            "'config_columns'=>pmssConfigLineColumns('alpha beta', 2),".
            "'port'=>pmssNetworkPortParseDigits('8443', 1024, 65535),".
            "'lock'=>basename(pmssRuntimeLockPath('pmss-runtime-facade.lock')),".
            "'tty_default'=>pmssStreamIsTty(null, true),".
            "'systemd_action'=>pmssSystemdUnitActionNameIsSafe('restart'),".
            "'hostname'=>pmssHostnameIsValid('example.com'),".
            "'capture'=>function_exists('pmssCommandCapture')".
            "]);";

        $this->assertSame([
            'time_keys'      => ['month', 'week', 'day', 'hour', '15min'],
            'size_bytes'     => 1073741824,
            'argv_quote'     => "'alpha beta' 'gamma'",
            'config_columns' => ['alpha', 'beta'],
            'port'           => 8443,
            'lock'           => 'pmss-runtime-facade.lock',
            'tty_default'    => true,
            'systemd_action' => true,
            'hostname'       => true,
            'capture'        => true,
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testRuntimeKeepsCliGuardStubCompatibility(): void
    {
        $runtime = var_export(dirname(__DIR__, 3).'/lib/runtime.php', true);
        $script = "function pmssRequireCli(string \$message = '', ?int \$failureCode = 1): bool { return false; } ".
            "require_once {$runtime}; echo json_encode([".
            "'cli'=>pmssRequireCli(),".
            "'entrypoint'=>function_exists('pmssPrepareCliEntrypoint')".
            "]);";

        $this->assertSame([
            'cli'        => false,
            'entrypoint' => true,
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testPmssPrepareCliEntrypointAppendsArgumentsToGlobalArgv(): void
    {
        $this->withRuntimeArgv(['wrapper.php'], function (): void {
            \pmssPrepareCliEntrypoint(false, ['--quiet']);
            $this->assertEquals(['wrapper.php', '--quiet'], $GLOBALS['argv']);
            $this->assertEquals($GLOBALS['argv'], $_SERVER['argv']);
        });
    }

    public function testPmssRequireCliEntrypointScriptLoadsTargetWithAdjustedArgv(): void
    {
        $originalCapture = $GLOBALS['PMSS_RUNTIME_TEST_ENTRYPOINT'] ?? null;
        $tempDir = $this->pmssMakeTempDir('pmss-runtime-entrypoint-');
        $scriptPath = $tempDir.'/capture.php';
        $this->pmssWriteFile(
            $scriptPath,
            "<?php\n".
            "\$GLOBALS['PMSS_RUNTIME_TEST_ENTRYPOINT'] = [\n".
            "    'argv' => \$GLOBALS['argv'] ?? [],\n".
            "    'serverArgv' => \$_SERVER['argv'] ?? [],\n".
            "];\n"
        );

        try {
            $this->withRuntimeArgv(['wrapper.php'], function () use ($tempDir): void {
                \pmssRequireCliEntrypointScript($tempDir, 'capture.php', false, ['--json']);
                $capture = $GLOBALS['PMSS_RUNTIME_TEST_ENTRYPOINT'] ?? null;
                $this->assertTrue(is_array($capture), 'Expected delegated entrypoint capture');
                $this->assertEquals(['wrapper.php', '--json'], $capture['argv']);
                $this->assertEquals($capture['argv'], $capture['serverArgv']);
            });
        } finally {
            if ($originalCapture === null) {
                unset($GLOBALS['PMSS_RUNTIME_TEST_ENTRYPOINT']);
            } else {
                $GLOBALS['PMSS_RUNTIME_TEST_ENTRYPOINT'] = $originalCapture;
            }
        }
    }

    public function testCliEntrypointRelativePathSafetyMatrix(): void
    {
        foreach ([
            'capture.php' => true,
            'util/systemTest.php' => true,
            'util/nested-name_1.php' => true,
            '' => false,
            '/absolute.php' => false,
            '../outside.php' => false,
            'util/../outside.php' => false,
            'util//systemTest.php' => false,
            "util/bad\npath.php" => false,
            'util\\systemTest.php' => false,
        ] as $relativePath => $expected) {
            $this->assertSame($expected, \pmssCliEntrypointRelativePathIsSafe($relativePath), 'Unexpected CLI entrypoint path safety result for '.var_export($relativePath, true));
        }
    }

    public function testRequireCliEntrypointScriptRejectsTraversalBeforeRequire(): void
    {
        $tempDir = $this->pmssMakeTempDir('pmss-runtime-entrypoint-safe-');
        $baseDir = $tempDir.'/base';
        $outsidePath = $tempDir.'/outside.php';
        @mkdir($baseDir, 0755, true);
        $this->pmssWriteFile(
            $outsidePath,
            "<?php\n\$GLOBALS['PMSS_RUNTIME_TEST_OUTSIDE_REQUIRE'] = true;\n"
        );
        unset($GLOBALS['PMSS_RUNTIME_TEST_OUTSIDE_REQUIRE']);

        $this->assertThrowsRuntime(static function () use ($baseDir): void {
            \pmssRequireCliEntrypointScript($baseDir, '../outside.php');
        }, 'Unsafe CLI entrypoint relative path');
        $this->assertFalse(isset($GLOBALS['PMSS_RUNTIME_TEST_OUTSIDE_REQUIRE']), 'Unsafe relative path must not be required');
    }

    public function testDefaultCommandTimeoutIs1200Seconds(): void
    {
        $this->assertTrue(defined('PMSS_COMMAND_TIMEOUT_DEFAULT'));
        $this->assertEquals(1200, constant('PMSS_COMMAND_TIMEOUT_DEFAULT'));
    }

    public function testRunCommandEchoSuccessCapturesStdout(): void
    {
        $captured = [];
        $rc = \runCommand('echo HELLO_RUNTIME', false, function (string $m) use (&$captured): void { $captured[] = $m; });
        $this->assertEquals(0, $rc);
        $out = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '';
        $this->assertTrue(strpos($out, 'HELLO_RUNTIME') !== false);
    }

    public function testRunCommandFailureCapturesStderrAndNonZero(): void
    {
        $rc = \runCommand('ls /definitely-not-a-real-path-xyz 2>/dev/null; exit 2', false, function (string $m): void {});
        $this->assertTrue($rc !== 0);
        $err = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr'] ?? '';
        $this->assertTrue(is_string($err));
    }

    public function testRunCommandFailureLogKeepsStderrExcerpt(): void
    {
        $logs = [];
        $rc = \runCommand(
            'php -r '.escapeshellarg('fwrite(STDERR, "RUNTIME_ERR_MARKER\n"); exit(3);'),
            false,
            function (string $m) use (&$logs): void { $logs[] = $m; }
        );

        $this->assertEquals(3, $rc);
        $this->assertTrue($this->pmssMessagesContain($logs, 'Command failed (rc=3)'));
        $this->assertTrue($this->pmssMessagesContain($logs, 'RUNTIME_ERR_MARKER'));
    }

    public function testCommandCaptureKeepsStdoutStderrAndRc(): void
    {
        $result = \pmssCommandCapture(
            'php -r '.escapeshellarg('fwrite(STDOUT, "CAPTURE_OUT\n"); fwrite(STDERR, "CAPTURE_ERR\n"); exit(7);')
        );

        $this->assertEquals(7, $result['rc']);
        $this->assertEquals("CAPTURE_OUT\n", $result['stdout']);
        $this->assertEquals("CAPTURE_ERR\n", $result['stderr']);
    }

    public function testPipedCaptureKeepsStdoutStderrRcShape(): void
    {
        $bash = '/bin/bash -lc '.escapeshellarg('printf PIPE_OUT; printf PIPE_ERR >&2; exit 6');
        $result = \pmssCommandPipedCapture($bash, 'pipe-shape-test', 0);

        $this->assertEquals(6, $result['rc']);
        $this->assertEquals('PIPE_OUT', $result['stdout']);
        $this->assertEquals('PIPE_ERR', $result['stderr']);
        $this->assertFalse($result['timed_out']);
        $this->assertFalse($result['launch_failed']);
        $this->assertFalse($result['pipe_failed']);
    }

    public function testPipedCaptureKeepsTailBoundedOutputSnapshot(): void
    {
        $code = 'fwrite(STDOUT, "1234567890"); fwrite(STDERR, "abcdefghij");';
        $bash = '/bin/bash -lc '.escapeshellarg(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
        $result = \pmssCommandPipedCapture($bash, 'pipe-tail-test', 0, 4);

        $this->assertSame(['rc' => 0, 'stdout' => '7890', 'stderr' => 'ghij', 'timed_out' => false, 'launch_failed' => false, 'pipe_failed' => false], $result);
    }

    public function testPipedCaptureHonorsCwdAndEnvironment(): void
    {
        $cwd = $this->pmssMakeTempDir('pmss-piped-cwd-');
        $code = 'echo basename(getcwd()).":".getenv("PMSS_PIPE_ENV");';
        $bash = '/bin/bash -lc '.escapeshellarg(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
        $result = \pmssCommandPipedCapture($bash, 'pipe-env-test', 0, 0, false, 'proc_open failed', 1, false, 'stream_select failed', $cwd, ['PMSS_PIPE_ENV' => 'ok']);

        $this->assertSame(0, $result['rc']);
        $this->assertSame(basename($cwd).':ok', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testProcessCloseExitCodeUsesObservedStatusAfterPolling(): void
    {
        $process = proc_open(
            '/bin/bash -lc '.escapeshellarg('exit 7'),
            \pmssProcessPipeDescriptorSpec(),
            $pipes
        );
        $this->assertTrue(is_resource($process), 'expected proc_open handle');

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = null;
        $deadline = microtime(true) + 5.0;
        do {
            $status = proc_get_status($process);
            if (!is_array($status) || empty($status['running'])) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->assertEquals(7, \pmssProcessCloseExitCode($process, $status));
    }

    public function testRunCommandInheritTtyModeDoesNotBreakCallers(): void
    {
        $rc = \runCommand('true', false, function (string $m): void {}, true);
        $this->assertEquals(0, $rc);
        $output = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? null;
        $this->assertTrue(is_array($output));
        $this->assertTrue(array_key_exists('stdout', $output));
        $this->assertTrue(array_key_exists('stderr', $output));
    }

    public function testRunCommandAptTimeoutFloorIgnoresLowerEnvTimeout(): void
    {
        $rc = null;
        $this->pmssWithEnv(['PMSS_COMMAND_TIMEOUT' => '1'], function () use (&$rc): void {
            $rc = \runCommand('echo apt-get; sleep 2', false, function (string $m): void {});
        });
        $this->assertEquals(0, $rc);
    }

    public function testRunCommandAptExecHandlesLeadingEnvAssignments(): void
    {
        $rc = \runCommand(
            'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none echo apt-get',
            false,
            function (string $m): void {}
        );
        $this->assertEquals(0, $rc);
        $out = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '';
        $this->assertTrue(strpos($out, 'apt-get') !== false);
    }

    public function testCommandBashInvocationPreservesAptEnvExecContract(): void
    {
        $this->pmssWithEnv(['PATH' => '/tmp/pmss-test-bin'], function (): void {
            $bash = \pmssCommandBashInvocation('DEBIAN_FRONTEND=noninteractive apt-get update');

            $this->assertStringContainsString('/tmp/pmss-test-bin', $bash);
            $this->assertStringContainsString('export PATH=', $bash);
            $this->assertStringContainsString('APT_LISTCHANGES_FRONTEND=none', $bash);
            $this->assertStringContainsString('UCF_FORCE_CONFOLD=1', $bash);
            $this->assertStringContainsString('NEEDRESTART_MODE=a', $bash);
            $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive exec apt-get update', $bash);
        });
    }

    public function testCommandBashInvocationExportsEnvForBareDpkgRecovery(): void
    {
        $bash = \pmssCommandBashInvocation('dpkg --configure -a');

        $this->assertStringContainsString('export ', $bash);
        $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $bash);
        $this->assertStringContainsString('APT_LISTCHANGES_FRONTEND=none', $bash);
        $this->assertStringContainsString('UCF_FORCE_CONFDEF=1', $bash);
        $this->assertStringContainsString('UCF_FORCE_CONFOLD=1', $bash);
        $this->assertStringContainsString('NEEDRESTART_MODE=a', $bash);
        $this->assertStringContainsString('exec dpkg --configure -a', $bash);
    }

    public function testRunCommandTimeoutWritesStructuredTimeoutFireLog(): void
    {
        $timeoutLog = $this->pmssMakeTempFile('pmss-timeout-fire-');
        $rc = null;
        $previousCorrelationId = $GLOBALS['PMSS_CORRELATION_ID_CACHE'] ?? null;

        try {
            $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = null;
            $this->pmssWithEnv([
                'PMSS_COMMAND_TIMEOUT' => '1',
                'PMSS_TIMEOUT_FIRE_LOG' => $timeoutLog,
                'PMSS_CORRELATION_ID' => 'runtime-timeout-test',
            ], function () use (&$rc): void {
                $rc = \runCommand('php -r '.escapeshellarg('sleep(2);'), false, function (string $m): void {});
            });
        } finally {
            $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = $previousCorrelationId;
        }

        $this->assertEquals(124, $rc);
        $data = pmssJsonLineFileLast($timeoutLog);
        $this->assertTrue(is_array($data), 'expected timeout-fire JSON payload');
        $this->assertEquals('timeout_fired', $data['event'] ?? '');
        $this->assertStringContainsString('php -r', $data['command'] ?? '');
        $this->assertEquals(1, $data['intended_seconds'] ?? 0);
        $this->assertEquals(124, $data['exit_status'] ?? 0);
        $this->assertEquals('SIGTERM', $data['signal'] ?? '');
        $this->assertEquals('runtime-timeout-test', $data['correlation_id'] ?? '');
        $this->assertTrue(($data['actual_seconds'] ?? 0) >= 1.0);
    }

    public function testRuntimeLockPathRejectsTraversalBasenames(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssRuntimeLockPath('../escape.lock');
        }, 'Unsafe runtime lock basename');
        $this->assertThrowsRuntime(static function (): void {
            \pmssRuntimeLockPath("pmss-bad\nlock");
        }, 'Unsafe runtime lock basename');
    }

    public function testRuntimeLockPathKeepsValidBasename(): void
    {
        $path = \pmssRuntimeLockPath('pmss-runtime-test.lock');
        $this->assertTrue($path === '/run/lock/pmss-runtime-test.lock' || $path === '/tmp/pmss-runtime-test.lock');
    }

    public function testReadRegularFileIntReturnsParsedDigits(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-runtime-int-').'/port', "123\n");

        $this->assertEquals(123, \pmssReadRegularFileInt($path, 99));
    }

    public function testReadRegularFileIntFallsBackForNonDigitContent(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-runtime-int-').'/port', "123oops\n");

        $this->assertEquals(99, \pmssReadRegularFileInt($path, 99));
    }

    public function testReadRegularFileIntFallsBackForSymlinkedFile(): void
    {
        $tempDir = $this->pmssMakeTempDir('pmss-runtime-int-');
        $path = $tempDir.'/port';
        $this->pmssCreateSymlinkOrSkip($this->pmssWriteFile($tempDir.'/target', "123\n"), $path);

        $this->assertEquals(99, \pmssReadRegularFileInt($path, 99));
    }

    public function testSnapshotLogTaskKeepsLifecycleContract(): void
    {
        $logPath = $this->pmssMakeTempPath('pmss-runtime-snapshot-', '.log');
        $runtime = var_export(dirname(__DIR__, 3).'/lib/runtime.php', true);
        $script = "require {$runtime}; \$logPath=getenv('PMSS_TEST_SNAPSHOT_LOG'); \$rc=pmssRunSnapshotLogTask('snapshot-test.php','PMSS_TEST_SNAPSHOT_LOG','/tmp/unused.log',static function(\$handle,string \$timestamp): int { pmssSnapshotWriteLine(\$handle,\$timestamp.' SNAPSHOT_BEGIN'); pmssSnapshotWriteWarn(\$handle,\$timestamp,'sample_warn',['rc'=>2],['alpha','beta']); return 7; }); echo json_encode(['rc'=>\$rc,'exists'=>is_file(\$logPath),'mode'=>is_file(\$logPath)?sprintf('%04o',fileperms(\$logPath)&0777):'','body'=>is_file(\$logPath)?file_get_contents(\$logPath):'']);";
        $result = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_SNAPSHOT_LOG' => $logPath]);
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->assertEquals(['rc' => 1, 'exists' => false, 'mode' => '', 'body' => ''], $result);
            return;
        }
        $this->assertEquals([7, true, '0600'], [$result['rc'], $result['exists'], $result['mode']]);
        $this->assertStringContainsString(' SNAPSHOT_BEGIN', $result['body']);
        $this->assertStringContainsString(' WARN sample_warn rc=2 msg=alpha beta', $result['body']);
    }

    public function testSnapshotWarnNormalizesControlCharacters(): void
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertTrue(is_resource($handle), 'expected temp stream');

        try {
            \pmssSnapshotWriteWarn($handle, '2026-05-24T00:00:00', "bad\ncode", [
                "bad\nkey" => "alpha\nbeta\tgamma",
                'empty' => '',
            ]);
            rewind($handle);
            $body = stream_get_contents($handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->assertSame("2026-05-24T00:00:00 WARN bad_code bad_key=alpha beta gamma\n", $body);
    }

    // Note: logMessage() in lib/update.php targets a fixed log location; avoid writing system logs here.

    private function withRuntimeArgv(array $argv, callable $callback): void
    {
        $originalGlobalArgv = $GLOBALS['argv'] ?? null;
        $originalServerArgv = $_SERVER['argv'] ?? null;
        $GLOBALS['argv'] = $argv;
        $_SERVER['argv'] = $argv;

        try {
            $callback();
        } finally {
            if ($originalGlobalArgv === null) {
                unset($GLOBALS['argv']);
            } else {
                $GLOBALS['argv'] = $originalGlobalArgv;
            }

            if ($originalServerArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalServerArgv;
            }
        }
    }
}
