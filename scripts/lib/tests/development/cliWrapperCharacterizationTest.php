<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class CliWrapperCharacterizationTest extends TestCase
{
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
