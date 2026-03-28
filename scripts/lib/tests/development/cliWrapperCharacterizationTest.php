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

    public function testPortManagerUsesSharedCliEntrypoint(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/portManager.php',
            [
                'function pmssPortManagerMain(array $argv): int',
                'pmssRunCliEntrypoint(__FILE__, static function () use ($argv): int {',
                'return pmssPortManagerMain($argv);',
            ]
        );
    }
}
