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
            $source = $this->pmssReadRepoFile($path);
            $this->assertStringContainsString("require_once __DIR__.'/lib/runtime.php';", $source);
            $this->assertStringContainsString("pmssRequireCliEntrypointScript(__DIR__, '{$target}');", $source);
            $this->assertTrue(strpos($source, '$argv') === false, $path.' should stay a thin wrapper');
        }
    }

    public function testUserDockerKeepsSharedStopAndSocketGuardsInline(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/userDocker.php');
        $this->assertStringContainsString('$dockerStopCmd =', $source);
        $this->assertStringContainsString('$socketPresent = file_exists($dockerSock);', $source);
        $this->assertStringContainsString('Docker socket present for {$user}, but process check failed; skipping start', $source);
        $this->assertStringContainsString('Docker start requested for {$user} via dockerd-rootless.sh', $source);
    }
}
