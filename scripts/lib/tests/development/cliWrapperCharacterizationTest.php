<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class CliWrapperCharacterizationTest extends TestCase
{
    public function testEntrypointIgnoresMalformedScriptFilename(): void
    {
        $runtime = $this->pmssRepoPath('scripts/lib/runtime.php');
        require_once $runtime;
        $original = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $present = array_key_exists('SCRIPT_FILENAME', $_SERVER);
        $called = false;
        try {
            foreach ([null, false, [], "bad\0path", "\0", ''] as $invalid) {
                $_SERVER['SCRIPT_FILENAME'] = $invalid;
                $this->pmssAssertNoPhpWarnings(function () use (&$called): void {
                    \pmssRunCliEntrypoint(__FILE__, static function () use (&$called): int {
                        $called = true;
                        return 0;
                    });
                });
                $this->assertFalse($called);
            }
        } finally {
            if ($present) $_SERVER['SCRIPT_FILENAME'] = $original;
            else unset($_SERVER['SCRIPT_FILENAME']);
        }
    }

    public function testDelegationPreservesArgumentsStreamsAndNonzeroExit(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cli-wrapper-');
        $runtime = var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true);
        $args = ['', 'plain', 'two words', "quote'\"", '$(false); *', "line\nbreak"];
        foreach (['', "#!/usr/bin/env php\n"] as $shebang) {
            // Both include and subprocess delegation must preserve the CLI contract.
            $this->pmssWriteFile($root."/target ' quoted.php", $shebang."<?php declare(strict_types=1);\n"
                .'echo json_encode(array_slice($_SERVER["argv"], 1)); fwrite(STDERR, "child stderr\n"); exit(23);');
            $wrapper = $this->pmssWriteFile($root.'/wrapper.php', "<?php require_once {$runtime};\n"
                .'pmssRequireCliEntrypointScript(__DIR__, '.var_export("target ' quoted.php", true).', false, ["--appended"]); echo "unexpected return";');
            $command = $this->pmssBuildArgvCommand(array_merge([PHP_BINARY, $wrapper], $args));
            ['result' => $result, 'stderrPath' => $stderrPath] = $this->pmssExecShellCommandWithTempStderr($command);
            $this->assertSame(23, $result['rc']);
            $this->assertSame(array_merge($args, ['--appended']), $this->pmssDecodeJsonArray($result['output']));
            $this->assertSame("child stderr\n", file_get_contents($stderrPath));
        }
    }

    public function testPrefixInspectionClosesStreamBeforeDelegatingOrRethrowing(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cli-prefix-');
        $this->pmssWriteFile($root.'/target.php', '<?php $GLOBALS["prefixEvents"][] = "target";');
        $runtime = var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true);
        // Inject only the prefix I/O; resolution and target inclusion use real fixture files.
        $script = <<<'PHP'
namespace CliPrefixFixture;
function fopen($path, $mode) {
    $GLOBALS['prefixEvents'][] = ['open', basename($path), $mode];
    return $GLOBALS['prefixHandle'] = $GLOBALS['prefixCase'] === 'open-failure' ? false : \tmpfile();
}
function fread($handle, $length) {
    $GLOBALS['prefixEvents'][] = ['read', $length];
    if ($GLOBALS['prefixFailure'] !== null) throw $GLOBALS['prefixFailure'];
    return ['ordinary' => '<?', 'empty' => '', 'short' => '<', 'read-failure' => false][$GLOBALS['prefixCase']];
}
function fclose($handle) {
    $GLOBALS['prefixEvents'][] = 'close';
    return \fclose($handle);
}
PHP;
        $script .= "require {$runtime};".$this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/cli.php', 'CliPrefixFixture');
        $script .= <<<'PHP'
$GLOBALS['prefixCase'] = getenv('PMSS_TEST_CLI_PREFIX_CASE');
$GLOBALS['prefixEvents'] = [];
$GLOBALS['prefixFailure'] = $GLOBALS['prefixCase'] === 'exception' ? new \RuntimeException('prefix read failed')
    : ($GLOBALS['prefixCase'] === 'error' ? new \Error('prefix read failed') : null);
$caught = null;
try {
    pmssRequireCliEntrypointScript(getenv('PMSS_TEST_CLI_PREFIX_ROOT'), 'target.php');
} catch (\Throwable $error) {
    $caught = $error;
}
echo json_encode([$GLOBALS['prefixEvents'], is_resource($GLOBALS['prefixHandle']),
    $caught === $GLOBALS['prefixFailure']]);
PHP;
        foreach (['ordinary', 'empty', 'short', 'read-failure', 'open-failure', 'exception', 'error'] as $case) {
            $events = [['open', 'target.php', 'rb']];
            if ($case !== 'open-failure') {
                $events[] = ['read', 2];
                $events[] = 'close';
            }
            if ($case !== 'exception' && $case !== 'error') $events[] = 'target';
            $this->assertSame([$events, false, true], $this->pmssRunInlinePhpJson($script, [
                'PMSS_TEST_CLI_PREFIX_CASE' => $case,
                'PMSS_TEST_CLI_PREFIX_ROOT' => $root,
            ]), $case);
        }
    }

    public function testThinWrappersDelegateDirectlyToUtilScripts(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/systemTest.php' => [
                'required' => ["require_once __DIR__.'/lib/runtime.php';", "pmssRequireCliEntrypointScript(__DIR__, 'util/systemTest.php');"],
                'forbidden' => ['$argv'],
            ],
            'scripts/userDocker.php' => [
                'required' => ["require_once __DIR__.'/lib/runtime.php';", "pmssRequireCliEntrypointScript(__DIR__, 'util/userDocker.php');"],
                'forbidden' => ['$argv'],
            ],
            'scripts/userResourcesList.php' => [
                'required' => ["require_once __DIR__.'/lib/runtime.php';", "pmssRequireCliEntrypointScript(__DIR__, 'util/userResourcesList.php');"],
                'forbidden' => ['$argv'],
            ],
        ]);
    }

    public function testUserDockerKeepsSharedStopAndSocketGuards(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/docker/stop.php' => ['required' => ['userDockerStopScan($user, $uid)']],
            'scripts/lib/user/docker/start.php' => ['required' => [
                '$socketPresent = file_exists($dockerSock);',
                'Docker socket present for {$user}, but process check failed; skipping start',
                'Docker start requested for {$user} via dockerd-rootless.sh',
            ]],
        ]);
    }

    public function testCliEntrypointsUseSharedRuntimeHelpers(): void
    {
        $this->pmssAssertRepoFileContractCases(array_merge(array_fill_keys(
            [
                'scripts/showResources.php',
                'scripts/showTraffic.php',
                'scripts/cron/scheduledConfigBackup.php',
                'scripts/util/dockerInstallLsio.php',
                'scripts/util/portManager.php',
                'scripts/util/userConfigCgroup.php',
                'scripts/util/userConfigLighttpd.php',
            ],
            ['required' => ['pmssRunCliEntrypointWithArgv(__FILE__,'], 'forbidden' => ["PHP_SAPI === 'cli'"]]
        ), array_fill_keys(
            ['scripts/cron/checkDirectories.php', 'scripts/cron/rtorrentPortReservationsReconcile.php'],
            ['required' => ['pmssRunCliEntrypoint(__FILE__,'], 'forbidden' => ["PHP_SAPI === 'cli'"]]
        )));
    }

    public function testLegacyCheckInstancesWrapperDelegatesInProcess(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/checkInstances.php' => [
                'required' => [
                    "\$target = __DIR__.'/checkRtorrent.php';",
                    'missing; cannot run rTorrent watchdog',
                    'require $target;',
                ],
                'forbidden' => ['pmss-check'.'Instances.lock', 'passthru($cmd', 'array_shift($args)', 'escapeshellarg($target)'],
            ],
        ]);
    }
}
