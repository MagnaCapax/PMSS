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
            $source = (string) @file_get_contents(dirname(__DIR__, 4).'/'.$path);
            $this->assertTrue($source !== '', 'Expected to read '.$path);
            $this->assertStringContainsString("require_once __DIR__.'/lib/runtime.php';", $source);
            $this->assertStringContainsString("pmssRequireCliEntrypointScript(__DIR__, '{$target}');", $source);
            $this->assertTrue(strpos($source, '$argv') === false, $path.' should stay a thin wrapper');
        }
    }

    public function testUserDockerKeepsSharedStopAndSocketGuardsInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/userDocker.php';
        $source = (string) @file_get_contents($path);

        $this->assertTrue($source !== '', 'Expected to read '.$path);
        $this->assertStringContainsString('$dockerStopCmd =', $source);
        $this->assertStringContainsString('$socketPresent = file_exists($dockerSock);', $source);
        $this->assertStringContainsString('Docker socket present for {$user}, but process check failed; skipping start', $source);
        $this->assertStringContainsString('Docker start requested for {$user} via dockerd-rootless.sh', $source);
    }
}
