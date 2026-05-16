<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class CliWrapperCharacterizationTest extends TestCase
{
    public function testThinWrappersDelegateDirectlyToUtilScripts(): void
    {
        $cases = [
            'scripts/systemTest.php' => 'util/systemTest.php',
            'scripts/userDocker.php' => 'util/userDocker.php',
            'scripts/userResourcesList.php' => 'util/userResourcesList.php',
        ];

        foreach ($cases as $path => $target) {
            $this->pmssAssertRepoFileContainsAllStrings(
                $path,
                [
                    "require_once __DIR__.'/lib/runtime.php';",
                    "pmssRequireCliEntrypointScript(__DIR__, '{$target}');",
                ]
            );
            $this->pmssAssertRepoFileNotContainsStrings($path, ['$argv'], $path.' should stay a thin wrapper');
        }
    }

    public function testUserDockerKeepsSharedStopAndSocketGuardsInline(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/userDocker.php',
            [
                '$dockerStopCmd =',
                '$socketPresent = file_exists($dockerSock);',
                'Docker socket present for {$user}, but process check failed; skipping start',
                'Docker start requested for {$user} via dockerd-rootless.sh',
            ]
        );
    }

    public function testArgvCliEntrypointsUseSharedRuntimeHelper(): void
    {
        foreach (['scripts/showResources.php', 'scripts/showTraffic.php', 'scripts/util/dockerInstallLsio.php', 'scripts/util/portManager.php', 'scripts/util/userConfigCgroup.php', 'scripts/util/userConfigLighttpd.php'] as $path) {
            $this->pmssAssertRepoFileContainsAllStrings($path, ['pmssRunCliEntrypointWithArgv(__FILE__,']);
        }
    }

    public function testLegacyCheckInstancesWrapperDelegatesInProcess(): void
    {
        $path = 'scripts/cron/checkInstances.php';
        $this->pmssAssertRepoFileContainsAllStrings($path, ["\$target = __DIR__.'/checkRtorrent.php';", 'missing; cannot run rTorrent watchdog', 'require $target;']);
        $this->pmssAssertRepoFileNotContainsStrings($path, ['pmss-check'.'Instances.lock', 'passthru($cmd', 'array_shift($args)', 'escapeshellarg($target)']);
    }
}
