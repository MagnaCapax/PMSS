<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class CliWrapperCharacterizationTest extends TestCase
{
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
            $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($wrapper).' '.implode(' ', array_map('escapeshellarg', $args));
            ['result' => $result, 'stderrPath' => $stderrPath] = $this->pmssExecShellCommandWithTempStderr($command);
            $this->assertSame(23, $result['rc']);
            $this->assertSame(array_merge($args, ['--appended']), $this->pmssDecodeJsonArray($result['output']));
            $this->assertSame("child stderr\n", file_get_contents($stderrPath));
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

    public function testUserDockerKeepsSharedStopAndSocketGuardsInline(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userDocker.php' => ['required' => [
                '$dockerStopCmd =',
                '$socketPresent = file_exists($dockerSock);',
                'Docker socket present for {$user}, but process check failed; skipping start',
                'Docker start requested for {$user} via dockerd-rootless.sh',
            ]],
        ]);
    }

    public function testArgvCliEntrypointsUseSharedRuntimeHelper(): void
    {
        $this->pmssAssertRepoFileContractCases(array_fill_keys(
            [
                'scripts/showResources.php',
                'scripts/showTraffic.php',
                'scripts/util/dockerInstallLsio.php',
                'scripts/util/portManager.php',
                'scripts/util/userConfigCgroup.php',
                'scripts/util/userConfigLighttpd.php',
            ],
            ['required' => ['pmssRunCliEntrypointWithArgv(__FILE__,']]
        ));
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
