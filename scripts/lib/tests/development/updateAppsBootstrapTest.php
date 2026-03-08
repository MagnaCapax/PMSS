<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppsBootstrapTest extends TestCase
{
    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testBootstrapHelperDefinesRuntimeLoader(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/bootstrap.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString('function pmssUpdateAppRuntimeBootstrap', $contents);
        $this->assertStringContainsString("dirname(__DIR__).'/runtime.php'", $contents);
        $this->assertStringContainsString('missing runtime helper', $contents);
    }

    public function testRadarrUsesSharedBootstrapHelper(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/radarr.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString("require_once __DIR__.'/bootstrap.php';", $contents);
        $this->assertStringContainsString("pmssUpdateAppRuntimeBootstrap('Radarr')", $contents);
        $this->assertTrue(strpos($contents, 'missing runtime helper at') === false, 'Radarr should use shared bootstrap warning path');
    }

    public function testSonarrUsesSharedBootstrapHelper(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/sonarr.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString("require_once __DIR__.'/bootstrap.php';", $contents);
        $this->assertStringContainsString("pmssUpdateAppRuntimeBootstrap('Sonarr')", $contents);
        $this->assertTrue(strpos($contents, "dirname(__DIR__, 2).'/runtime.php'") === false, 'Sonarr should not duplicate runtime include logic');
    }
}

