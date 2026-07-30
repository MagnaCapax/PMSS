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
            "'build'=>function_exists('pmssBuildCommand'),".
            "'snapshot'=>function_exists('pmssRunSnapshotLogTask')".
            "]);";

        $this->assertSame([
            'runCommand' => true,
            'capture'    => true,
            'build'      => true,
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
            "'build_command'=>pmssBuildCommand('printf', ['alpha beta', 'gamma']),".
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
            'build_command'  => "printf 'alpha beta' 'gamma'",
            'config_columns' => ['alpha', 'beta'],
            'port'           => 8443,
            'lock'           => 'pmss-runtime-facade.lock',
            'tty_default'    => true,
            'systemd_action' => true,
            'hostname'       => true,
            'capture'        => true,
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testCreatePrivateTempDirKeepsPrivateScopeAndMode(): void
    {
        $path = \pmssCreatePrivateTempDir('pmss-runtime-private-');
        $this->assertTrue(is_string($path) && is_dir($path), 'Expected private temp directory');

        try {
            $this->assertSame($path, \pmssPrivateTempDirRealpath($path, 'pmss-runtime-private-'));
            $this->assertSame('0700', sprintf('%04o', fileperms($path) & 0777));
        } finally {
            if (is_string($path) && is_dir($path)) {
                @rmdir($path);
            }
        }
    }

    public function testPrivateTempBaseDirRejectsUnsafeBoundaryMatrix(): void
    {
        $file = $this->pmssMakeTempFile('pmss-temp-base-file-');
        foreach (['', '/', '/definitely-not-a-real-pmss-temp-dir', $file, "/tmp/pmss\0bad"] as $path) {
            $this->assertSame(null, \pmssPrivateTempBaseDirRealpath($path), 'Unexpected temp base result for '.var_export($path, true));
        }
    }

    public function testPrivateTempBaseDirAcceptsWritableDirectory(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-temp-base-ok-');
        $this->assertSame(realpath($dir), \pmssPrivateTempBaseDirRealpath($dir));
    }

    public function testCreatePrivateTempFileKeepsPrivateScope(): void
    {
        $prefix = 'pmss-runtime-file-';
        $path = \pmssCreatePrivateTempFile($prefix);
        $base = \pmssPrivateTempBaseDirRealpath();
        $this->assertTrue(is_string($path) && is_file($path), 'Expected private temp file');

        try {
            $real = realpath((string) $path);
            $this->assertTrue(is_string($base), 'Expected usable system temp base');
            $this->assertTrue(is_string($real) && strpos($real, $base.DIRECTORY_SEPARATOR) === 0, 'Temp file escaped system temp base');
            $this->assertTrue(strpos(basename((string) $real), $prefix) === 0, 'Temp file prefix mismatch');
        } finally {
            if (is_string($path) && is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
    }

    public function testCreatePrivateTempFileRejectsUnsafePrefix(): void
    {
        $this->assertSame(null, \pmssCreatePrivateTempFile('../pmss-runtime-private'));
    }

    public function testCreatePrivateTempDirRejectsUnsafePrefix(): void
    {
        ob_start();
        try {
            $this->assertSame(null, \pmssCreatePrivateTempDir('../pmss-runtime-private'));
        } finally {
            ob_end_clean();
        }
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

    public function testPmssRequireCliEntrypointScriptRunsShebangTargetAsMainScript(): void
    {
        $runtime = var_export(dirname(__DIR__, 3).'/lib/runtime.php', true);
        $tempDir = $this->pmssMakeTempDir('pmss-runtime-entrypoint-shebang-');
        $baseDir = $tempDir.'/base';
        $targetPath = $baseDir.'/util/shebangTarget.php';
        $wrapperPath = $baseDir.'/wrapper.php';

        $this->pmssWriteExecutableFile(
            $targetPath,
            "#!/usr/bin/env php\n".
            "<?php\n".
            "declare(strict_types=1);\n".
            "require_once {$runtime};\n".
            "function pmssRuntimeTestShebangMain(array \$argv): int {\n".
            "    echo json_encode(['script' => basename((string) (\$_SERVER['SCRIPT_FILENAME'] ?? '')), 'argv' => array_map('basename', \$argv)]);\n".
            "    return 0;\n".
            "}\n".
            "pmssRunCliEntrypointWithArgv(__FILE__, 'pmssRuntimeTestShebangMain');\n"
        );
        $this->pmssWriteFile(
            $wrapperPath,
            "<?php\n".
            "require_once {$runtime};\n".
            "pmssRequireCliEntrypointScript(__DIR__, 'util/shebangTarget.php', false, ['--appended']);\n"
        );

        $result = $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' '.escapeshellarg($wrapperPath).' --json', [], '2>&1');
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->pmssAssertStringNotContainsString('#!/usr/bin/env php', $result['output']);
        $this->assertSame([
            'script' => 'shebangTarget.php',
            'argv' => ['shebangTarget.php', '--json', '--appended'],
        ], $this->pmssDecodeJsonArray($result['output']));
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

    public function testCliEntrypointScriptResolveReturnsCanonicalTarget(): void
    {
        $tempDir = $this->pmssMakeTempDir('pmss-runtime-entrypoint-resolve-');
        $baseDir = $tempDir.'/base';
        $scriptPath = $this->pmssWriteFile($baseDir.'/util/target.php', "<?php\n");

        $this->assertSame(realpath($scriptPath), \pmssCliEntrypointScriptResolve($baseDir, 'util/target.php'));
    }

    public function testCliEntrypointScriptResolveRejectsSymlinkEscape(): void
    {
        $tempDir = $this->pmssMakeTempDir('pmss-runtime-entrypoint-symlink-');
        $baseDir = $tempDir.'/base';
        $outsidePath = $this->pmssWriteFile($tempDir.'/outside.php', "<?php\n");
        @mkdir($baseDir.'/util', 0755, true);
        $this->pmssCreateSymlinkOrSkip($outsidePath, $baseDir.'/util/escaped.php');

        $this->assertThrowsRuntime(static function () use ($baseDir): void {
            \pmssCliEntrypointScriptResolve($baseDir, 'util/escaped.php');
        }, 'Unsafe CLI entrypoint script path');
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

    public function testPipedCaptureRejectsUnsafeCwdBeforeLaunch(): void
    {
        $filePath = $this->pmssMakeTempFile('pmss-piped-cwd-file-');
        $bash = '/bin/bash -lc '.escapeshellarg('printf SHOULD_NOT_RUN');

        foreach (['', $filePath, '/definitely-not-a-real-pmss-cwd', "/tmp/pmss\0bad"] as $cwd) {
            $result = \pmssCommandPipedCapture($bash, 'pipe-cwd-safety-test', 0, 0, false, 'proc_open failed', 17, false, 'stream_select failed', $cwd);

            $this->assertPipedCaptureLaunchFailure($result, 17, 'unsafe proc_open cwd', 'cwd '.str_replace("\0", '\\0', $cwd));
        }
    }

    public function testPipedCaptureRejectsUnsafeEnvironmentBeforeLaunch(): void
    {
        $cwd = $this->pmssMakeTempDir('pmss-piped-env-');
        $bash = '/bin/bash -lc '.escapeshellarg('printf SHOULD_NOT_RUN');

        foreach ([
            ['' => 'empty-key'],
            ['BAD=KEY' => 'value'],
            ["BAD\nKEY" => 'value'],
            ['PMSS_PIPE_ENV' => "bad\0value"],
            ['PMSS_PIPE_ENV' => ['not' => 'scalar']],
        ] as $env) {
            $result = \pmssCommandPipedCapture($bash, 'pipe-env-safety-test', 0, 0, false, 'proc_open failed', 19, false, 'stream_select failed', $cwd, $env);

            $this->assertPipedCaptureLaunchFailure($result, 19, 'unsafe proc_open environment');
        }
    }

    public function testInheritedTtyCaptureKeepsResultShape(): void
    {
        $result = \pmssCommandInheritedTtyCapture('/bin/bash -lc '.escapeshellarg('exit 5'), 'tty-shape-test', 0);

        $this->assertSame(['rc' => 5, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'launch_failed' => false, 'pipe_failed' => false], $result);
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

    // The apt/dpkg timeout floor this used to characterize is deleted: it computed
    // max(1200, 1200) and only acted under an env override that LOWERED the deadline.
    // Replaced by CommandTimeoutProcessGroupTest::testNoCommandClassCarriesABespokeTimeout,
    // which asserts the invariant (no class-specific deadline) instead of the carve-out.

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

            $this->assertStringContainsAllStrings(['/tmp/pmss-test-bin', 'export PATH=', 'APT_LISTCHANGES_FRONTEND=none', 'UCF_FORCE_CONFOLD=1', 'NEEDRESTART_MODE=a', 'DEBIAN_FRONTEND=noninteractive exec apt-get update'], $bash);
        });
    }

    public function testAptDpkgEnvAssignmentsRejectShellShapedOverrides(): void
    {
        $assignments = \pmssAptDpkgEnvAssignments([
            'DEBIAN_FRONTEND' => 'readline',
            'BAD;KEY' => 'x',
            "BAD\nKEY" => 'x',
            'PMSS_BAD_VALUE' => 'value; reboot',
            'PMSS_SPACE_VALUE' => 'two words',
            'PMSS_ARRAY_VALUE' => ['not' => 'scalar'],
        ]);

        $this->assertSame('readline', $assignments['DEBIAN_FRONTEND']);
        foreach (['BAD;KEY', "BAD\nKEY", 'PMSS_BAD_VALUE', 'PMSS_SPACE_VALUE', 'PMSS_ARRAY_VALUE'] as $key) {
            $this->assertFalse(array_key_exists($key, $assignments), 'Unsafe env override survived: '.str_replace("\n", '\\n', $key));
        }

        $prefix = \pmssAptDpkgEnvPrefix([
            'DEBIAN_FRONTEND' => 'readline',
            'PMSS_BAD_VALUE' => '$(reboot)',
        ]);
        $this->assertStringContainsString('DEBIAN_FRONTEND=readline', $prefix);
        $this->assertStringNotContainsString('$(reboot)', $prefix);
    }

    public function testCommandBashInvocationExportsEnvForBareDpkgRecovery(): void
    {
        $bash = \pmssCommandBashInvocation('dpkg --configure -a');

        $this->assertStringContainsAllStrings(['export ', 'DEBIAN_FRONTEND=noninteractive', 'APT_LISTCHANGES_FRONTEND=none', 'UCF_FORCE_CONFDEF=1', 'UCF_FORCE_CONFOLD=1', 'NEEDRESTART_MODE=a', 'exec dpkg --configure -a'], $bash);
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

    public function testRuntimeLockAcquireRejectsNulBytePathFailSoft(): void
    {
        $busy = null;
        $path = $this->pmssMakeTempDir('pmss-runtime-lock-').'/lock';

        $this->assertFalse(\pmssLockFileAcquire($path."\0suffix", true, 'c', true, true, $busy));
        $this->assertFalse($busy);
    }

    public function testRuntimeLockAcquireRejectsSymlinkedLockPathFailSoft(): void
    {
        $tempDir = $this->pmssMakeTempDir('pmss-runtime-lock-');
        $target = $this->pmssWriteFile($tempDir.'/target', '');
        $lockPath = $tempDir.'/lock';
        $this->pmssCreateSymlinkOrSkip($target, $lockPath);

        $this->assertFalse(\pmssLockFileAcquire($lockPath, true, 'c', false, true));
    }

    public function testRuntimeLockHandleWritePidReportsWriteResult(): void
    {
        $path = $this->pmssMakeTempDir('pmss-runtime-lock-pid-').'/lock';
        $handle = fopen($path, 'c+');
        $this->assertTrue(is_resource($handle));

        try {
            $this->assertTrue(\pmssLockHandleWritePid($handle));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->assertSame((string) getmypid(), (string) file_get_contents($path));
        $this->assertFalse(\pmssLockHandleWritePid(false));
    }

    public function testReadRegularFileIntParsesDigitsAndRejectsUnsafeInputs(): void
    {
        $symlinkDir = $this->pmssMakeTempDir('pmss-runtime-int-');
        $symlinkPath = $symlinkDir.'/port';
        $this->pmssCreateSymlinkOrSkip($this->pmssWriteFile($symlinkDir.'/target', "123\n"), $symlinkPath);

        foreach ([
            'digits' => [$this->pmssWriteFile($this->pmssMakeTempDir('pmss-runtime-int-').'/port', "123\n"), 123],
            'non-digit content' => [$this->pmssWriteFile($this->pmssMakeTempDir('pmss-runtime-int-').'/port', "123oops\n"), 99],
            'symlinked file' => [$symlinkPath, 99],
        ] as $label => [$path, $expected]) {
            $this->assertEquals($expected, \pmssReadRegularFileInt($path, 99), $label);
        }
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
        $this->assertStringContainsAllStrings([' SNAPSHOT_BEGIN', ' WARN sample_warn rc=2 msg=alpha beta'], $result['body']);
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

    /** Assert the common launch-failure shape for piped command capture. */
    private function assertPipedCaptureLaunchFailure(array $result, int $rc, string $stderr, string $label = ''): void
    {
        $prefix = $label !== '' ? $label.': ' : '';
        $this->assertSame($rc, $result['rc'], $prefix.'rc');
        $this->assertSame('', $result['stdout'], $prefix.'stdout');
        $this->assertSame($stderr, $result['stderr'], $prefix.'stderr');
        $this->assertFalse($result['timed_out'], $prefix.'timed_out');
        $this->assertTrue($result['launch_failed'], $prefix.'launch_failed');
        $this->assertFalse($result['pipe_failed'], $prefix.'pipe_failed');
    }

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
