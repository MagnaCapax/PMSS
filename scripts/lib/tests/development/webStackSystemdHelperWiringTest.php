<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Ensures web stack orchestration calls shared systemd helpers.
 */
class WebStackSystemdHelperWiringTest extends TestCase
{
    /**
     * Web stack should reference shared helper include and call sites.
     */
    public function testWebStackUsesSharedRuntimeSystemdActionHelperForServiceState(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/webStack.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(strpos($src, "require_once __DIR__.'/runtime/processes.php';") !== false);
        $this->assertTrue(strpos($src, "pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');") !== false);
        $this->assertTrue(strpos($src, "pmssSystemdUnitActionIfPresent('nginx', 'Enabling nginx systemd service', 'enable');") !== false);
    }
}
